<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Models\QrScan;
use App\Models\User;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyPointSetting;
use App\Models\Redemption;
use App\Models\PointItem;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\DaleachiousCardTransaction;
use App\Services\AuditLogService;
use App\Services\DaleachiousCardService;
use App\Services\RewardRedemptionService;
use App\Support\AdminBranchScope;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrController extends Controller
{
    public function __construct(
        protected RewardRedemptionService $redemptions,
        protected DaleachiousCardService $cards,
    ) {}

    // ── SCAN a QR code ────────────────────────────────────
    public function scan(Request $request)
    {
        $request->validate([
            'code'      => 'required|string',
            'action'    => 'required|in:earn_points,redeem_reward,fulfill_redemption,topup_card',
            'reward_id' => 'nullable|exists:point_items,id',
            'point_item_id' => 'nullable|exists:point_items,id',
            'amount'    => 'nullable|numeric|min:0',
            'points'    => 'nullable|integer|min:1',
            'payment_method' => 'nullable|string|in:daleachious_card,cash,credit_debit,gcash,maya',
        ]);

        $request->merge([
            'branch_id' => AdminBranchScope::resolveWriteBranchId(null),
        ]);

        if (! $request->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'This staff account has no assigned branch. Assign a branch before scanning.',
            ]);
        }

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

        $isRedemptionQr = $qr->type === 'redemption' || in_array($qr->purpose, ['point_redemption', 'reward_redemption'], true);

        $response = match (true) {
            $request->action === 'topup_card' => $this->handleTopUpCard($qr, $request),
            $isRedemptionQr => $this->handleFulfillRedemption($qr, $request),
            $request->action === 'fulfill_redemption' => $this->handleFulfillRedemption($qr, $request),
            default => match ($request->action) {
                'earn_points'   => $this->handleEarnPoints($qr, $request),
                'redeem_reward' => $this->handleRedeemReward($qr, $request),
                default         => ['result' => 'failed', 'message' => 'Unknown action'],
            },
        };

        $response['action'] = $request->action === 'topup_card'
            ? 'topup_card'
            : ($isRedemptionQr ? 'fulfill_redemption' : $request->action);

        // Only count successful scans so a one-time reward QR is not burned on a failed attempt.
        if (($response['result'] ?? null) === 'success') {
            $qr->increment('scan_count');
            $response['branch'] = Branch::query()
                ->find($request->branch_id)
                ?->only(['id', 'name', 'code']);
            $scanner = $request->user();
            $response['scanned_by'] = $scanner instanceof Admin
                ? $scanner->only(['id', 'name', 'role'])
                : null;
        }

        return response()->json($response);
    }

    // ── Handle: Fulfill an in-app redemption QR ───────────
    private function handleFulfillRedemption(QrCode $qr, Request $request): array
    {
        $redemption = null;

        if ($qr->type === 'redemption' || in_array($qr->purpose, ['point_redemption', 'reward_redemption'], true)) {
            $redemption = Redemption::query()->with(['user', 'pointItem'])->find($qr->qrable_id);
        } elseif ($qr->type === 'user') {
            $redemption = Redemption::query()
                ->with(['user', 'pointItem'])
                ->where('user_id', $qr->qrable_id)
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            if (! $redemption) {
                QrScan::create([
                    'qr_code_id' => $qr->id,
                    'scanned_by' => auth()->id(),
                    'branch_id'  => $request->branch_id,
                    'action'     => 'fulfill_redemption',
                    'result'     => 'failed',
                    'notes'      => 'No pending redemption for this member',
                ]);

                return [
                    'result'  => 'failed',
                    'message' => 'This member has no pending reward to fulfill.',
                    'user'    => User::find($qr->qrable_id)?->only(['id', 'name', 'email']),
                ];
            }
        } else {
            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => 'fulfill_redemption',
                'result'     => 'failed',
                'notes'      => 'QR is not a redemption or member loyalty code',
            ]);

            return [
                'result'  => 'failed',
                'message' => 'This QR cannot fulfill a reward. Ask the member to open Points and show their reward QR.',
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
                'action'     => 'fulfill_redemption',
                'result'     => 'failed',
                'notes'      => 'Redemption #'.$redemption->id.' is already '.$redemption->status,
            ]);

            return [
                'result'     => 'failed',
                'message'    => 'This item was already '.$redemption->status.'.',
                'redemption' => $redemption,
                'reward'     => $redemption->reward,
                'user'       => $redemption->user?->only(['id', 'name', 'email']),
            ];
        }

        try {
            $fulfilled = $this->redemptions->fulfill($redemption);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to fulfill this reward.';

            return [
                'result'  => 'failed',
                'message' => $first,
            ];
        }

        QrScan::create([
            'qr_code_id'      => $qr->id,
            'scanned_by'      => auth()->id(),
            'branch_id'       => $request->branch_id,
            'action'          => 'fulfill_redemption',
            'result'          => 'success',
            'points_affected' => 0,
            'notes'           => 'Fulfilled '.$fulfilled->pointItem?->name.' for '.$fulfilled->user?->name,
        ]);

        $memberCode = $fulfilled->user
            ? QrCode::getOrCreateForUser($fulfilled->user)->code
            : null;

        return [
            'result'      => 'success',
            'message'     => ($fulfilled->pointItem?->name ?? 'Reward').' fulfilled.',
            'item'        => $fulfilled->pointItem,
            'reward'      => $fulfilled->pointItem,
            'redemption'  => $fulfilled,
            'points_used' => (int) $fulfilled->points_used,
            'points_left' => (int) ($fulfilled->user?->points ?? 0),
            'user'        => $fulfilled->user?->only(['id', 'name', 'email']),
            'member_code' => $memberCode,
        ];
    }

    private function handleTopUpCard(QrCode $qr, Request $request): array
    {
        $admin = $request->user();
        if (! $admin instanceof Admin || ! AdminPermissions::allows($admin, 'card.confirm')) {
            return [
                'result'  => 'failed',
                'message' => 'You do not have access to confirm Card top-ups.',
            ];
        }

        if ($qr->type !== 'user') {
            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => 'topup_card',
                'result'     => 'failed',
                'notes'      => 'QR is not a member loyalty QR',
            ]);

            return [
                'result'  => 'failed',
                'message' => 'Scan the member’s Daleachious Card QR, not a redemption QR.',
            ];
        }

        $user = User::find($qr->qrable_id);
        if (! $user) {
            return [
                'result'  => 'failed',
                'message' => 'Customer not found.',
            ];
        }

        if (! $request->filled('amount')) {
            return [
                'result'  => 'failed',
                'message' => 'Enter the top-up amount before scanning.',
                'user'    => $user->only(['id', 'name', 'email']),
            ];
        }

        try {
            $txn = $this->cards->topUpInStore($user, $request->input('amount'), 'in_store');
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to top up this Card.';
            $card = $this->cards->getOrCreateCard($user);

            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => 'topup_card',
                'result'     => 'failed',
                'notes'      => $first,
            ]);

            return [
                'result'                   => 'failed',
                'message'                  => $first,
                'user'                     => $user->only(['id', 'name', 'email']),
                'card_balance'             => (float) $card->balance,
                'formatted_card_balance'   => $this->cards->formatPeso($card->balance),
            ];
        }

        $card = $txn->card()->first();

        QrScan::create([
            'qr_code_id'      => $qr->id,
            'scanned_by'      => auth()->id(),
            'branch_id'       => $request->branch_id,
            'action'          => 'topup_card',
            'result'          => 'success',
            'points_affected' => 0,
            'notes'           => $this->cards->formatPeso($txn->amount).' Card top-up for '.$user->name.' ('.$txn->reference.')',
        ]);

        return [
            'result'                 => 'success',
            'message'                => $this->cards->formatPeso($txn->amount).' added to the Daleachious Card.',
            'reference'              => $txn->reference,
            'amount'                 => (float) $txn->amount,
            'formatted_amount'       => $this->cards->formatPeso($txn->amount),
            'card_balance'           => $card ? (float) $card->balance : null,
            'formatted_card_balance' => $card ? $this->cards->formatPeso($card->balance) : null,
            'transaction'            => $this->cards->presentTransaction($txn->load(['user', 'card'])),
            'user'                   => $user->only(['id', 'name', 'email']),
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

        $user = User::find($qr->qrable_id);

        if (! $user) {
            return [
                'result'  => 'failed',
                'message' => 'Customer not found.',
            ];
        }

        $method = (string) $request->input('payment_method', '');
        $allowed = array_keys(config('daleachious.purchase_payment_methods', []));
        if (! in_array($method, $allowed, true)) {
            return [
                'result'  => 'failed',
                'message' => 'Choose how the member paid: Daleachious Card, cash, credit/debit, GCash, or Maya.',
                'user'    => $user->only(['id', 'name', 'email']),
            ];
        }

        $admin = $request->user();
        $isCardPay = LoyaltyPointSetting::isCardPayment($method);
        if ($isCardPay && (! $admin instanceof Admin || ! AdminPermissions::allows($admin, 'card.confirm'))) {
            return [
                'result'  => 'failed',
                'message' => 'You do not have access to charge a Daleachious Card.',
                'user'    => $user->only(['id', 'name', 'email']),
            ];
        }

        if (! $request->filled('amount') || (float) $request->amount <= 0) {
            return [
                'result'  => 'failed',
                'message' => 'Enter the purchase amount before scanning.',
                'user'    => $user->only(['id', 'name', 'email']),
            ];
        }

        $amount = (float) $request->amount;
        $settings = LoyaltyPointSetting::getSettings();
        $points = $settings->calculatePoints($amount, $method);
        $methodLabel = (string) config('daleachious.purchase_payment_methods.'.$method, $method);

        try {
            return DB::transaction(function () use (
                $qr,
                $request,
                $user,
                $amount,
                $points,
                $method,
                $methodLabel,
                $isCardPay,
                $settings,
            ) {
                $cardTxn = null;
                if ($isCardPay) {
                    $cardTxn = $this->cards->debitForPurchase($user, $amount, $method);
                }

                if ($points > 0) {
                    LoyaltyPoint::create([
                        'user_id'     => $user->id,
                        'points'      => $points,
                        'type'        => 'earned',
                        'description' => $methodLabel.' purchase of '.$this->cards->formatPeso($amount),
                        'reference_type' => $cardTxn ? DaleachiousCardTransaction::class : null,
                        'reference_id'   => $cardTxn?->id,
                    ]);
                    $user->increment('points', $points);
                }

                QrScan::create([
                    'qr_code_id'      => $qr->id,
                    'scanned_by'      => auth()->id(),
                    'branch_id'       => $request->branch_id,
                    'action'          => 'earn_points',
                    'result'          => 'success',
                    'points_affected' => $points,
                    'notes'           => $this->cards->formatPeso($amount).' '.$methodLabel.' — '.$points.' Points for '.$user->name,
                ]);

                $freshUser = $user->fresh();
                $card = $this->cards->getOrCreateCard($freshUser);

                $message = $isCardPay
                    ? $this->cards->formatPeso($amount).' charged to the Daleachious Card. '.$points.' Points added.'
                    : $points.' Points added for a '.$methodLabel.' purchase.';

                return [
                    'result'                   => 'success',
                    'message'                  => $message,
                    'points_earned'            => $points,
                    'total_points'             => $freshUser->points,
                    'payment_method'           => $method,
                    'payment_method_label'     => $methodLabel,
                    'amount'                   => $amount,
                    'formatted_amount'         => $this->cards->formatPeso($amount),
                    'peso_per_point'           => $settings->pesoPerPointFor($method),
                    'card_charged'             => $isCardPay,
                    'card_balance'             => (float) $card->balance,
                    'formatted_card_balance'   => $this->cards->formatPeso($card->balance),
                    'reference'                => $cardTxn?->reference,
                    'user'                     => $freshUser->only(['id', 'name', 'email']),
                ];
            });
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to complete this purchase.';
            $card = $this->cards->getOrCreateCard($user);

            QrScan::create([
                'qr_code_id' => $qr->id,
                'scanned_by' => auth()->id(),
                'branch_id'  => $request->branch_id,
                'action'     => 'earn_points',
                'result'     => 'failed',
                'notes'      => $first,
            ]);

            return [
                'result'                 => 'failed',
                'message'                => $first,
                'payment_method'         => $method,
                'payment_method_label'   => $methodLabel,
                'card_balance'           => (float) $card->balance,
                'formatted_card_balance' => $this->cards->formatPeso($card->balance),
                'user'                   => $user->only(['id', 'name', 'email']),
            ];
        }
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

        $itemId = $request->point_item_id ?? $request->reward_id;

        if (! $itemId) {
            return [
                'result'  => 'failed',
                'message' => 'point_item_id is required for redeeming.',
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

        $item = PointItem::find($itemId);

        if (! $item || ! $item->is_active) {
            return [
                'result'  => 'failed',
                'message' => 'Item not found or inactive.',
            ];
        }

        try {
            $result = $this->redemptions->redeemApproved($user, $item);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to redeem this item.';

            return [
                'result'           => 'failed',
                'message'          => $first,
                'points_required'  => (int) $item->points_required,
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
            'notes'           => 'Redeemed: '.$item->name.' by '.$user->name,
        ]);

        return [
            'result'      => 'success',
            'message'     => 'Points redeemed successfully!',
            'item'        => $result['item'],
            'reward'      => $result['item'],
            'redemption'  => $result['redemption'],
            'points_used' => (int) $result['redemption']->points_used,
            'points_left' => $result['points_left'],
            'user'        => $user->only(['id', 'name', 'email']),
        ];
    }

    // ── LOOKUP a QR code (read-only; opens customer counter page) ──
    public function lookup(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $code = strtoupper(trim((string) $request->code));
        $qr = QrCode::where('code', $code)->first();

        if (! $qr) {
            return response()->json([
                'result'  => 'failed',
                'message' => 'Invalid QR code.',
            ], 404);
        }

        if ($qr->isExpired()) {
            return response()->json([
                'result'  => 'expired',
                'message' => 'This QR code has expired.',
                'qr_type' => $qr->type,
            ], 422);
        }

        if (! $qr->isValid()) {
            return response()->json([
                'result'  => 'failed',
                'message' => 'This QR code is no longer valid.',
                'qr_type' => $qr->type,
            ], 422);
        }

        $isRedemptionQr = $qr->type === 'redemption'
            || in_array($qr->purpose, ['point_redemption', 'reward_redemption'], true);

        if ($isRedemptionQr) {
            $redemption = Redemption::query()->with('user')->find($qr->qrable_id);

            if (! $redemption?->user) {
                return response()->json([
                    'result'  => 'failed',
                    'message' => 'Customer not found for this QR.',
                ], 404);
            }

            $payload = $this->customerLookupPayload($redemption->user, $code);
            $payload['qr_type'] = 'redemption';

            return response()->json($payload);
        }

        if ($qr->type !== 'user') {
            return response()->json([
                'result'  => 'failed',
                'message' => 'This QR is not a member loyalty QR.',
                'qr_type' => $qr->type,
            ], 422);
        }

        $user = User::find($qr->qrable_id);

        if (! $user) {
            return response()->json([
                'result'  => 'failed',
                'message' => 'Customer not found.',
            ], 404);
        }

        $payload = $this->customerLookupPayload($user, $code);
        $payload['qr_type'] = 'member';

        return response()->json($payload);
    }

    private function customerLookupPayload(User $user, string $code): array
    {
        $card = $this->cards->getOrCreateCard($user);
        $settings = LoyaltyPointSetting::getSettings();

        return [
            'result'                 => 'success',
            'code'                   => $code,
            'user'                   => $user->only(['id', 'name', 'email', 'phone', 'points']),
            'card_balance'           => (float) $card->balance,
            'formatted_card_balance' => $this->cards->formatPeso($card->balance),
            'earn_rates'             => [
                'daleachious_card' => $settings->pesoPerPointFor(LoyaltyPointSetting::CARD_PAYMENT),
                'other'            => $settings->pesoPerPointFor('cash'),
            ],
            'payment_methods'        => config('daleachious.purchase_payment_methods', []),
        ];
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

}