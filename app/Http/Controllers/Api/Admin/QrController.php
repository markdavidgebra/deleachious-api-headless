<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Models\QrScan;
use App\Models\User;
use App\Models\Order;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyPointSetting;
use App\Models\Redemption;
use App\Models\Reward;
use App\Services\AuditLogService;
use App\Services\LoyaltyPointsService;
use App\Services\RewardRedemptionService;
use App\Support\AdminBranchScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QrController extends Controller
{
    public function __construct(
        protected RewardRedemptionService $redemptions,
        protected LoyaltyPointsService $loyaltyPoints,
    ) {}

    // ── GENERATE QR for a user (loyalty card) ────────────
    public function generateUserQr(User $user)
    {
        $existing = QrCode::where('qrable_type', User::class)
            ->where('qrable_id', $user->id)
            ->first();

        if ($existing && $existing->isValid()) {
            return response()->json([
                'message' => 'User already has an active QR code',
                'qr_code' => $existing,
                'user'    => $user->only(['id', 'name', 'email', 'points']),
            ]);
        }

        $qr = QrCode::create([
            'code'        => QrCode::generateCode(),
            'type'        => 'user',
            'qrable_type' => User::class,
            'qrable_id'   => $user->id,
            'purpose'     => 'user_loyalty',
            'is_active'   => true,
            'max_scans'   => null,
            'expires_at'  => null,
        ]);

        return response()->json([
            'message' => 'User QR code generated successfully',
            'qr_code' => $qr,
            'user'    => $user->only(['id', 'name', 'email', 'points']),
        ], 201);
    }

    // ── GENERATE QR for an order (pickup verification) ───
    public function generateOrderQr(Request $request, Order $order)
    {
        AdminBranchScope::assertOrder($order);

        $request->validate([
            'expires_in_minutes' => 'nullable|integer|min:5|max:1440',
        ]);

        $existing = QrCode::where('qrable_type', Order::class)
            ->where('qrable_id', $order->id)
            ->where('purpose', 'order_pickup')
            ->latest('id')
            ->first();

        if ($existing && $existing->isValid()) {
            return response()->json([
                'message' => 'Order already has an active QR code',
                'qr_code' => $existing,
                'order'   => $order->only(['id', 'order_number', 'status', 'total']),
            ]);
        }

        $qr = $order->ensurePickupQr($request->integer('expires_in_minutes', 120));

        return response()->json([
            'message'    => 'Order QR code generated successfully',
            'qr_code'    => $qr,
            'expires_at' => $qr->expires_at,
            'order'      => $order->only(['id', 'order_number', 'status', 'total']),
        ], 201);
    }

    // ── SCAN a QR code ────────────────────────────────────
    public function scan(Request $request)
    {
        $request->validate([
            'code'      => 'required|string',
            'action'    => 'required|in:earn_points,redeem_reward,verify_order,approve_redemption',
            'branch_id' => 'nullable|exists:branches,id',
            'reward_id' => 'nullable|exists:rewards,id',
            'amount'    => 'nullable|numeric|min:0',
            'points'    => 'nullable|integer|min:1',
        ]);

        $request->merge([
            'branch_id' => AdminBranchScope::resolveWriteBranchId(
                $request->filled('branch_id') ? $request->integer('branch_id') : null
            ),
        ]);

        $code = strtoupper(trim((string) $request->code));
        $qr = QrCode::where('code', $code)->first();

        // QR not found
        if (! $qr) {
            return response()->json([
                'result'  => 'failed',
                'message' => 'Invalid QR code.',
            ], 404);
        }

        // QR expired
        if ($qr->isExpired()) {
            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => $request->action,
                'result'     => 'expired',
                'notes'      => 'QR code has expired',
            ]);

            return response()->json([
                'result'  => 'expired',
                'message' => 'This QR code has expired.',
            ], 422);
        }

        // QR not valid
        if (! $qr->isValid()) {
            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => $request->action,
                'result'     => 'failed',
                'notes'      => 'QR code is inactive or already used',
            ]);

            return response()->json([
                'result'  => 'failed',
                'message' => 'This QR code is no longer valid.',
            ], 422);
        }

        // Identify the QR first so the selected scan mode cannot send an
        // order code through reward-approve (the default counter tab).
        $isRedemptionQr = $qr->type === 'redemption' || $qr->purpose === 'reward_redemption';
        $isOrderQr = $qr->type === 'order' || $qr->purpose === 'order_pickup';

        $response = match (true) {
            $isRedemptionQr => $this->handleApproveRedemption($qr, $request),
            $isOrderQr => $this->handleVerifyOrder($qr, $request),
            $request->action === 'approve_redemption' => $this->handleApproveRedemption($qr, $request),
            $request->action === 'verify_order' => $this->handleVerifyOrder($qr, $request),
            default => match ($request->action) {
                'earn_points'   => $this->handleEarnPoints($qr, $request),
                'redeem_reward' => $this->handleRedeemReward($qr, $request),
                default         => ['result' => 'failed', 'message' => 'Unknown action'],
            },
        };

        $response['action'] = match (true) {
            $isRedemptionQr => 'approve_redemption',
            $isOrderQr => 'verify_order',
            default => $request->action,
        };

        // Only count successful scans so a one-time reward QR is not burned on a failed attempt.
        if (($response['result'] ?? null) === 'success') {
            $qr->increment('scan_count');
        }

        return response()->json($response);
    }

    // ── Handle: Approve in-app redemption QR ──────────────
    private function handleApproveRedemption(QrCode $qr, Request $request): array
    {
        $redemption = null;

        if ($qr->type === 'redemption' || $qr->purpose === 'reward_redemption') {
            $redemption = Redemption::query()->with(['user', 'reward'])->find($qr->qrable_id);
        } elseif ($qr->type === 'user') {
            $redemption = Redemption::query()
                ->with(['user', 'reward'])
                ->where('user_id', $qr->qrable_id)
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            if (! $redemption) {
                QrScan::create([
                    'qr_code_id' => $qr->id,
                    'scanned_by' => auth()->id(),
                    'branch_id'  => $request->branch_id,
                    'action'     => 'approve_redemption',
                    'result'     => 'failed',
                    'notes'      => 'No pending redemption for this member',
                ]);

                return [
                    'result'  => 'failed',
                    'message' => 'This member has no pending reward to approve.',
                    'user'    => User::find($qr->qrable_id)?->only(['id', 'name', 'email']),
                ];
            }
        } else {
            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => 'approve_redemption',
                'result'     => 'failed',
                'notes'      => 'QR is not a redemption or member loyalty code',
            ]);

            return [
                'result'  => 'failed',
                'message' => 'This QR cannot approve a reward. Ask the member to open Rewards and show their redeem QR.',
            ];
        }

        if (! $redemption) {
            return [
                'result'  => 'failed',
                'message' => 'Redemption not found for this QR.',
            ];
        }

        if ($redemption->status !== 'pending') {
            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => 'approve_redemption',
                'result'     => 'failed',
                'notes'      => 'Redemption #'.$redemption->id.' is already '.$redemption->status,
            ]);

            return [
                'result'     => 'failed',
                'message'    => 'This reward was already '.$redemption->status.'.',
                'redemption' => $redemption,
                'reward'     => $redemption->reward,
                'user'       => $redemption->user?->only(['id', 'name', 'email']),
            ];
        }

        try {
            $approved = $this->redemptions->approve($redemption);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to approve redemption.';

            return [
                'result'  => 'failed',
                'message' => $first,
            ];
        }

        QrScan::create([
            'qr_code_id'      => $qr->id,
            'scanned_by'      => auth()->id(),
            'branch_id'       => $request->branch_id,
            'action'          => 'approve_redemption',
            'result'          => 'success',
            'points_affected' => 0,
            'notes'           => 'Approved '.$approved->reward?->name.' for '.$approved->user?->name,
        ]);

        return [
            'result'      => 'success',
            'message'     => ($approved->reward?->name ?? 'Reward').' approved. Please fulfill it at the counter.',
            'reward'      => $approved->reward,
            'redemption'  => $approved,
            'points_used' => (int) $approved->points_used,
            'points_left' => (int) ($approved->user?->points ?? 0),
            'user'        => $approved->user?->only(['id', 'name', 'email']),
        ];
    }

    // ── Handle: Earn Points ───────────────────────────────
    private function handleEarnPoints(QrCode $qr, Request $request): array
    {
        if ($qr->type !== 'user') {
            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => 'earn_points',
                'result'     => 'failed',
                'notes'      => 'QR is not a user QR',
            ]);

            return [
                'result'  => 'failed',
                'message' => 'This QR is not a customer loyalty QR.',
            ];
        }

        // Find user directly by ID
        $user = User::find($qr->qrable_id);

        if (! $user) {
            return [
                'result'  => 'failed',
                'message' => 'Customer not found.',
            ];
        }

        $settings = LoyaltyPointSetting::getSettings();
        $amount   = (float) ($request->amount ?? 0);
        $points   = (int) ($request->points ?? $settings->calculatePoints($amount));

        if ($points <= 0) {
            return [
                'result'  => 'failed',
                'message' => 'No points to award. Check the amount or points value.',
            ];
        }

        // Award points
        LoyaltyPoint::create([
            'user_id'     => $user->id,
            'points'      => $points,
            'type'        => 'earned',
            'description' => 'Points earned via QR scan at branch',
        ]);

        $user->increment('points', $points);

        QrScan::create([
            'qr_code_id'      => $qr->id,
            'scanned_by'      => auth()->id(),
            'branch_id'       => $request->branch_id,
            'action'          => 'earn_points',
            'result'          => 'success',
            'points_affected' => $points,
            'notes'           => $points . ' points awarded to ' . $user->name,
        ]);

        $freshUser = $user->fresh();

        return [
            'result'        => 'success',
            'message'       => $points . ' points awarded successfully!',
            'points_earned' => $points,
            'total_points'  => $freshUser->points,
            'user'          => $user->only(['id', 'name', 'email']),
        ];
    }

    // ── Handle: Redeem Reward ─────────────────────────────
    private function handleRedeemReward(QrCode $qr, Request $request): array
    {
        if ($qr->type !== 'user') {
            return [
                'result'  => 'failed',
                'message' => 'This QR is not a customer loyalty QR.',
            ];
        }

        if (! $request->reward_id) {
            return [
                'result'  => 'failed',
                'message' => 'reward_id is required for redeeming.',
            ];
        }

        // Find user directly by ID
        $user = User::find($qr->qrable_id);

        if (! $user) {
            return [
                'result'  => 'failed',
                'message' => 'Customer not found.',
            ];
        }

        $reward = Reward::find($request->reward_id);

        if (! $reward || ! $reward->is_active) {
            return [
                'result'  => 'failed',
                'message' => 'Reward not found or inactive.',
            ];
        }

        try {
            $result = $this->redemptions->redeemApproved($user, $reward);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to redeem reward.';

            return [
                'result'           => 'failed',
                'message'          => $first,
                'points_required'  => (int) $reward->points_required,
                'points_available' => (int) $user->fresh()->points,
            ];
        }

        QrScan::create([
            'qr_code_id'      => $qr->id,
            'scanned_by'      => auth()->id(),
            'branch_id'       => $request->branch_id,
            'action'          => 'redeem_reward',
            'result'          => 'success',
            'points_affected' => -$result['redemption']->points_used,
            'notes'           => 'Redeemed: '.$reward->name.' by '.$user->name,
        ]);

        return [
            'result'      => 'success',
            'message'     => 'Reward redeemed successfully!',
            'reward'      => $result['reward'],
            'redemption'  => $result['redemption'],
            'points_used' => (int) $result['redemption']->points_used,
            'points_left' => $result['points_left'],
            'user'        => $user->only(['id', 'name', 'email']),
        ];
    }

    // ── Handle: Verify Order ──────────────────────────────
    private function handleVerifyOrder(QrCode $qr, Request $request): array
    {
        if ($qr->type !== 'order') {
            return [
                'result'  => 'failed',
                'message' => 'This QR is not an order QR.',
            ];
        }

        // Find order directly by ID
        $order = Order::find($qr->qrable_id);

        if (! $order) {
            return [
                'result'  => 'failed',
                'message' => 'Order not found.',
            ];
        }

        $lockedBranch = AdminBranchScope::branchId();
        if ($lockedBranch && (int) $order->branch_id !== $lockedBranch) {
            return [
                'result'  => 'failed',
                'message' => 'This order belongs to another branch.',
            ];
        }

        if ($order->status === 'cancelled') {
            return [
                'result'  => 'failed',
                'message' => 'This order has been cancelled.',
            ];
        }

        if ($order->status === 'completed') {
            return [
                'result'  => 'failed',
                'message' => 'This order is already completed.',
                'order'   => $order,
            ];
        }

        if ($order->status !== 'ready') {
            return [
                'result'  => 'failed',
                'message' => 'This order is not ready to serve yet ('.$order->status.').',
                'order'   => $order,
            ];
        }

        $order->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $pointsAward = ['awarded' => false, 'points' => 0, 'total_points' => null];
        if ($order->user_id && $order->points_earned > 0) {
            $pointsAward = $this->loyaltyPoints->awardForOrder($order->fresh());
        }

        $qr->update(['is_active' => false]);

        AuditLogService::log(
            'updated',
            'order',
            'Order '.$order->order_number.' served via QR scan',
            $order
        );

        $servedLabel = match ($order->type) {
            'delivery' => 'delivered',
            'dine_in'  => 'served',
            default    => 'picked up',
        };

        QrScan::create([
            'qr_code_id' => $qr->id,
            'scanned_by' => auth()->id(),
            'branch_id'  => $request->branch_id,
            'action'     => 'verify_order',
            'result'     => 'success',
            'notes'      => 'Order '.$order->order_number.' '.$servedLabel,
        ]);

        return [
            'result'  => 'success',
            'message' => 'Order '.$order->order_number.' '.$servedLabel.'.',
            'order'   => $order->fresh()->load(['items.addons', 'user']),
            'points_awarded' => (int) $pointsAward['points'],
            'points_newly_credited' => (bool) $pointsAward['awarded'],
        ];
    }

    // ── GET all QR codes ──────────────────────────────────
    public function index(Request $request)
    {
        $qrCodes = QrCode::with(['scans'])
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->orderByDesc('created_at')
            ->get();

        return response()->json($qrCodes);
    }

    // ── GET scan history ──────────────────────────────────
    public function scanHistory(Request $request)
    {
        $scans = QrScan::with(['qrCode', 'scannedBy', 'branch']);
        AdminBranchScope::applyColumn($scans, 'branch_id', $request);

        $scans = $scans
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            ->orderByDesc('created_at')
            ->get();

        return response()->json($scans);
    }

    // ── DEACTIVATE a QR code ──────────────────────────────
    public function deactivate(QrCode $qrCode)
    {
        $qrCode->update(['is_active' => false]);

        return response()->json([
            'message' => 'QR code deactivated successfully',
            'qr_code' => $qrCode,
        ]);
    }

    // ── GET user QR code ──────────────────────────────────
    public function getUserQr(User $user)
    {
        $qr = QrCode::where('qrable_type', User::class)
            ->where('qrable_id', $user->id)
            ->first();

        if (! $qr) {
            return response()->json([
                'message' => 'No QR code found for this user.',
            ], 404);
        }

        return response()->json([
            'qr_code'  => $qr,
            'is_valid' => $qr->isValid(),
            'user'     => $user->only(['id', 'name', 'email', 'points']),
        ]);
    }
}