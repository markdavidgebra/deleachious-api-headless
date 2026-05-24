<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\WalletException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Topup;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuditLogService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Back-office wallet endpoints. Lives under /api/admin/wallet/*.
 * All routes require an authenticated admin (Sanctum).
 */
class WalletController extends Controller
{
    public function __construct(protected WalletService $wallet) {}

    // ── Monitoring ────────────────────────────────────────────────────

    // GET /admin/wallet/transactions
    public function transactions(Request $request)
    {
        $request->validate([
            'type'      => 'nullable|in:topup,purchase,refund,adjustment',
            'status'    => 'nullable|in:pending,completed,failed,reversed',
            'wallet_id' => 'nullable|exists:wallets,id',
            'user_id'   => 'nullable|exists:users,id',
            'branch_id' => 'nullable|exists:branches,id',
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'per_page'  => 'nullable|integer|min:1|max:200',
        ]);

        $query = WalletTransaction::with(['wallet.user', 'branch'])
            ->when($request->type,      fn ($q, $t) => $q->where('transaction_type', $t))
            ->when($request->status,    fn ($q, $s) => $q->where('status', $s))
            ->when($request->wallet_id, fn ($q, $id) => $q->where('wallet_id', $id))
            ->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->user_id,   fn ($q, $id) => $q->whereHas('wallet', fn ($w) => $w->where('user_id', $id)))
            ->when($request->from,      fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to,        fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    // GET /admin/wallet/transactions/{transaction}
    public function showTransaction(WalletTransaction $transaction)
    {
        return response()->json(
            $transaction->load(['wallet.user', 'branch']),
        );
    }

    // GET /admin/wallet/users/{user}
    public function showUserWallet(User $user)
    {
        $wallet = $user->getOrCreateWallet();

        return response()->json([
            'user'   => $user->only(['id', 'name', 'email', 'phone']),
            'wallet' => $wallet,
            'totals' => [
                'topups_total'    => (float) WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('transaction_type', 'topup')->where('status', 'completed')->sum('amount'),
                'purchases_total' => (float) WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('transaction_type', 'purchase')->where('status', 'completed')->sum('amount'),
                'refunds_total'   => (float) WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('transaction_type', 'refund')->where('status', 'completed')->sum('amount'),
            ],
        ]);
    }

    // GET /admin/wallet/users/{user}/history
    public function userHistory(Request $request, User $user)
    {
        $wallet = $user->getOrCreateWallet();

        return response()->json(
            WalletTransaction::where('wallet_id', $wallet->id)
                ->orderByDesc('created_at')
                ->paginate($request->per_page ?? 25),
        );
    }

    // ── Counter top-up (cash at branch) ───────────────────────────────

    // POST /admin/wallet/topup-cash
    public function counterTopup(Request $request)
    {
        $request->validate([
            'user_id'   => 'required|exists:users,id',
            'amount'    => 'required|numeric|min:0.01',
            'branch_id' => 'required|exists:branches,id',
            'note'      => 'nullable|string|max:255',
        ]);

        $user = User::findOrFail($request->user_id);

        try {
            $tx = DB::transaction(function () use ($request, $user) {
                $wallet = $user->getOrCreateWallet();

                $topup = Topup::create([
                    'wallet_id' => $wallet->id,
                    'user_id'   => $user->id,
                    'channel'   => 'cash',
                    'currency'  => 'PHP',
                    'amount'    => $request->amount,
                    'status'    => 'pending',
                    'gateway'   => 'cash',
                    'branch_id' => $request->branch_id,
                    'metadata'  => ['note' => $request->note],
                ]);

                $tx = $this->wallet->creditTopup($user, [
                    'channel'           => 'cash',
                    'amount'            => $request->amount,
                    'branch_id'         => $request->branch_id,
                    'description'       => 'Counter top-up at branch #'.$request->branch_id,
                    'created_by'        => auth()->user(),
                    'source_type'       => Topup::class,
                    'source_id'         => $topup->id,
                    'metadata'          => ['note' => $request->note],
                ]);

                $topup->forceFill([
                    'status'                => 'succeeded',
                    'wallet_transaction_id' => $tx->id,
                    'paid_at'               => now(),
                ])->save();

                $user->notify(new \App\Notifications\Wallet\WalletTopupSuccess($topup->fresh(), $tx));

                return $tx;
            });
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        AuditLogService::log(
            'topup',
            'wallet',
            'Counter top-up of ₱'.number_format((float) $request->amount, 2).' for '.$user->name,
            $user,
        );

        return response()->json([
            'message'     => 'Top-up successful',
            'transaction' => $tx,
            'receipt'     => $this->wallet->buildReceipt($tx),
        ], 201);
    }

    // ── QR redemption (staff scans the customer's mobile QR) ──────────

    // POST /admin/wallet/qr/redeem
    public function redeemQr(Request $request)
    {
        $request->validate([
            'qr_token'  => 'required|string',
            'branch_id' => 'required|exists:branches,id',
        ]);

        // Strip any prefix the cashier scanner may include.
        $token = preg_replace('/^DALWAL:/', '', $request->qr_token);

        try {
            $result = $this->wallet->redeemPurchaseQr(
                $token,
                (int) $request->branch_id,
                auth()->user(),
            );
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        AuditLogService::log(
            'redeem',
            'wallet',
            'Redeemed wallet QR purchase '.$result['purchase']->reference_no,
            $result['purchase'],
        );

        return response()->json([
            'message'     => 'Payment successful',
            'transaction' => $result['transaction'],
            'purchase'    => $result['purchase'],
            'receipt'     => $this->wallet->buildReceipt($result['transaction']),
        ]);
    }

    // ── Manual adjustment ─────────────────────────────────────────────

    // POST /admin/wallet/adjust
    public function adjust(Request $request)
    {
        $request->validate([
            'user_id'   => 'required|exists:users,id',
            'amount'    => 'required|numeric',
            'reason'    => 'required|string|max:255',
            'direction' => ['required', Rule::in(['credit', 'debit'])],
        ]);

        $user   = User::findOrFail($request->user_id);
        $signed = $request->direction === 'credit'
            ? abs((float) $request->amount)
            : -abs((float) $request->amount);

        try {
            $tx = $this->wallet->adjustBalance($user, $signed, $request->reason, auth()->user());
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        AuditLogService::log(
            'adjusted',
            'wallet',
            'Adjusted wallet balance for '.$user->name.': '
                .($signed > 0 ? '+' : '').number_format($signed, 2).' — '.$request->reason,
            $user,
        );

        return response()->json([
            'message'     => 'Wallet adjusted',
            'transaction' => $tx,
            'receipt'     => $this->wallet->buildReceipt($tx),
        ], 201);
    }

    // ── Refund management ─────────────────────────────────────────────

    // GET /admin/wallet/refunds
    public function refundsIndex(Request $request)
    {
        $request->validate([
            'status'   => 'nullable|in:pending,approved,processing,completed,rejected,failed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            Refund::with(['user', 'reviewer', 'refundable'])
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('created_at')
                ->paginate($request->per_page ?? 25),
        );
    }

    // POST /admin/wallet/refunds/{refund}/approve
    public function approveRefund(Request $request, Refund $refund)
    {
        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($refund->status !== 'pending') {
            return response()->json([
                'message'    => 'Only pending refunds can be approved.',
                'error_code' => 'invalid_refund_state',
            ], 422);
        }

        $refund->forceFill([
            'status'      => 'approved',
            'admin_notes' => $request->admin_notes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();

        try {
            $tx = $this->wallet->processRefund($refund, auth()->user());
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        AuditLogService::log(
            'approved',
            'wallet_refund',
            'Approved refund '.$refund->reference_no.' for ₱'.number_format((float) $refund->amount, 2),
            $refund,
        );

        return response()->json([
            'message'     => 'Refund approved and processed',
            'refund'      => $refund->fresh(),
            'transaction' => $tx,
        ]);
    }

    // POST /admin/wallet/refunds/{refund}/reject
    public function rejectRefund(Request $request, Refund $refund)
    {
        $request->validate([
            'admin_notes' => 'required|string|max:500',
        ]);

        if ($refund->status !== 'pending') {
            return response()->json([
                'message'    => 'Only pending refunds can be rejected.',
                'error_code' => 'invalid_refund_state',
            ], 422);
        }

        $refund->forceFill([
            'status'      => 'rejected',
            'admin_notes' => $request->admin_notes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();

        AuditLogService::log(
            'rejected',
            'wallet_refund',
            'Rejected refund '.$refund->reference_no,
            $refund,
        );

        return response()->json([
            'message' => 'Refund rejected',
            'refund'  => $refund->fresh(),
        ]);
    }

    // ── Wallet status (freeze / suspend / unsuspend) ──────────────────

    // PATCH /admin/wallet/wallets/{wallet}/status
    public function updateWalletStatus(Request $request, Wallet $wallet)
    {
        $request->validate([
            'status' => ['required', Rule::in(['active', 'frozen', 'suspended', 'closed'])],
            'reason' => 'nullable|string|max:255',
        ]);

        $old = $wallet->status;
        $wallet->forceFill(['status' => $request->status])->save();

        AuditLogService::log(
            'updated',
            'wallet',
            'Wallet status changed: '.$old.' → '.$request->status
                .($request->reason ? ' ('.$request->reason.')' : ''),
            $wallet,
        );

        return response()->json([
            'message' => 'Wallet status updated',
            'wallet'  => $wallet->fresh(),
        ]);
    }

    // ── Reporting ─────────────────────────────────────────────────────

    // GET /admin/wallet/summary
    public function summary(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $base = WalletTransaction::query()
            ->where('status', 'completed')
            ->when($request->from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to,   fn ($q, $d) => $q->whereDate('created_at', '<=', $d));

        return response()->json([
            'total_topups'     => (float) (clone $base)->where('transaction_type', 'topup')->sum('amount'),
            'total_purchases'  => (float) (clone $base)->where('transaction_type', 'purchase')->sum('amount'),
            'total_refunds'    => (float) (clone $base)->where('transaction_type', 'refund')->sum('amount'),
            'total_adjustments_in'  => (float) (clone $base)->where('transaction_type', 'adjustment')->where('direction', 'credit')->sum('amount'),
            'total_adjustments_out' => (float) (clone $base)->where('transaction_type', 'adjustment')->where('direction', 'debit')->sum('amount'),
            'total_outstanding_balance' => (float) Wallet::sum('current_balance'),
            'total_active_wallets'      => Wallet::where('status', 'active')->count(),
        ]);
    }

    // GET /admin/wallet/branches/{branch}/report
    public function branchReport(Request $request, Branch $branch)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $base = WalletTransaction::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'completed')
            ->when($request->from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to,   fn ($q, $d) => $q->whereDate('created_at', '<=', $d));

        return response()->json([
            'branch'     => $branch->only(['id', 'name', 'code', 'city']),
            'topups'     => (float) (clone $base)->where('transaction_type', 'topup')->sum('amount'),
            'purchases'  => (float) (clone $base)->where('transaction_type', 'purchase')->sum('amount'),
            'refunds'    => (float) (clone $base)->where('transaction_type', 'refund')->sum('amount'),
            'tx_count'   => (clone $base)->count(),
        ]);
    }
}
