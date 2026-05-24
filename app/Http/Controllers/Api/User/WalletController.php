<?php

namespace App\Http\Controllers\Api\User;

use App\Exceptions\WalletException;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Topup;
use App\Models\WalletSetting;
use App\Models\WalletTransaction;
use App\Services\PayMongoService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile-app facing wallet endpoints.
 *
 * Every method here calls the {@see WalletService} for the actual money
 * movement so the rules (DB transactions, ledger writes, limits, branch
 * gating) are applied uniformly. The controller is only responsible for
 * input validation and shaping responses.
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallet,
        protected PayMongoService $paymongo,
    ) {}

    // GET /user/wallet/balance ────────────────────────────────────────
    public function balance(Request $request)
    {
        $wallet   = $request->user()->getOrCreateWallet();
        $settings = WalletSetting::getSettings();

        return response()->json([
            'wallet' => [
                'id'              => $wallet->id,
                'currency'        => $wallet->currency,
                'current_balance' => (float) $wallet->current_balance,
                'status'          => $wallet->status,
                'last_activity_at' => $wallet->last_activity_at,
            ],
            'limits' => [
                'max_balance'           => (float) $settings->max_balance,
                'max_topup'             => (float) $settings->max_topup,
                'min_topup'             => (float) $settings->min_topup,
                'max_purchase'          => (float) $settings->max_purchase,
                'daily_topup_limit'     => (float) $settings->daily_topup_limit,
                'daily_purchase_limit'  => (float) $settings->daily_purchase_limit,
            ],
            'flags' => [
                'topup_enabled'    => (bool) $settings->topup_enabled,
                'purchase_enabled' => (bool) $settings->purchase_enabled,
                'refund_enabled'   => (bool) $settings->refund_enabled,
            ],
            'terms' => [
                'version'    => $settings->terms_version,
                'updated_at' => $settings->terms_updated_at,
            ],
        ]);
    }

    // GET /user/wallet/history ────────────────────────────────────────
    public function history(Request $request)
    {
        $request->validate([
            'type'     => 'nullable|in:topup,purchase,refund,adjustment',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $wallet = $request->user()->getOrCreateWallet();

        $query = WalletTransaction::where('wallet_id', $wallet->id)
            ->when($request->type, fn ($q, $t) => $q->where('transaction_type', $t))
            ->orderByDesc('created_at');

        return response()->json(
            $query->paginate($request->per_page ?? 20),
        );
    }

    // GET /user/wallet/terms ──────────────────────────────────────────
    public function terms()
    {
        $settings = WalletSetting::getSettings();
        return response()->json([
            'version'              => $settings->terms_version,
            'terms_and_conditions' => $settings->terms_and_conditions,
            'updated_at'           => $settings->terms_updated_at,
        ]);
    }

    // POST /user/wallet/topup ─────────────────────────────────────────
    /**
     * Initiates a top-up. For card / GCash / Maya we create a PayMongo
     * checkout session and return its URL — the actual wallet credit
     * happens later in the webhook. For cash channel we reject (only
     * staff can perform counter top-ups via the admin endpoint).
     */
    public function topup(Request $request)
    {
        $request->validate([
            'amount'      => 'required|numeric|min:1',
            'channel'     => ['required', Rule::in(['card', 'gcash', 'maya'])],
            'success_url' => 'nullable|url',
            'cancel_url'  => 'nullable|url',
        ]);

        $settings = WalletSetting::getSettings();

        if (! $settings->topup_enabled) {
            return response()->json([
                'message'    => 'Top-up is currently disabled.',
                'error_code' => 'topup_disabled',
            ], 503);
        }

        $amount = round((float) $request->amount, 2);

        if ($amount < (float) $settings->min_topup || $amount > (float) $settings->max_topup) {
            return response()->json([
                'message'    => "Top-up amount must be between ₱{$settings->min_topup} and ₱{$settings->max_topup}.",
                'error_code' => 'amount_out_of_range',
            ], 422);
        }

        $user           = $request->user();
        $wallet         = $user->getOrCreateWallet();
        $idempotencyKey = $request->header('Idempotency-Key');

        // If client retries with the same Idempotency-Key, return the
        // previously-created topup row instead of double-charging them.
        if ($idempotencyKey) {
            $existing = Topup::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'topup'        => $existing,
                    'checkout_url' => $existing->checkout_url,
                ]);
            }
        }

        $topup = Topup::create([
            'wallet_id'        => $wallet->id,
            'user_id'          => $user->id,
            'channel'          => $request->channel,
            'currency'         => 'PHP',
            'amount'           => $amount,
            'status'           => 'pending',
            'gateway'          => 'paymongo',
            'idempotency_key'  => $idempotencyKey,
            'metadata'         => [
                'ip'         => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        ]);

        try {
            $checkout = $this->paymongo->createCheckout(
                $topup,
                $request->success_url ?? config('services.paymongo.success_url', url('/wallet/topup/success')),
                $request->cancel_url  ?? config('services.paymongo.cancel_url',  url('/wallet/topup/cancel')),
            );
        } catch (WalletException $e) {
            $topup->forceFill([
                'status'         => 'failed',
                'failure_reason' => $e->errorCode,
            ])->save();

            return $e->toResponse();
        }

        $topup->forceFill([
            'status'            => 'awaiting_webhook',
            'gateway_intent_id' => $checkout['id'] ?? null,
            'checkout_url'      => $checkout['checkout_url'] ?? null,
        ])->save();

        return response()->json([
            'topup'        => $topup->fresh(),
            'checkout_url' => $checkout['checkout_url'] ?? null,
            'message'      => 'Top-up initiated. Complete payment in the gateway page.',
        ], 201);
    }

    // POST /user/wallet/pay ───────────────────────────────────────────
    /**
     * Direct wallet pay. The mobile app calls this when the user taps
     * "Pay with wallet" at the POS — typically the cashier already
     * scanned a QR or entered an order id.
     */
    public function pay(Request $request)
    {
        $request->validate([
            'amount'    => 'required|numeric|min:0.01',
            'branch_id' => 'required|exists:branches,id',
            'order_id'  => 'nullable|exists:orders,id',
            'note'      => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->wallet->debitPurchase($request->user(), [
                'amount'          => $request->amount,
                'branch_id'       => $request->branch_id,
                'order_id'        => $request->order_id,
                'idempotency_key' => $request->header('Idempotency-Key'),
                'description'     => $request->note,
                'metadata'        => [
                    'ip'         => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                ],
            ]);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'message'     => 'Payment successful',
            'transaction' => $result['transaction'],
            'purchase'    => $result['purchase'],
            'receipt'     => $this->wallet->buildReceipt($result['transaction']),
        ], 201);
    }

    // POST /user/wallet/qr/generate ───────────────────────────────────
    /**
     * Generate a single-use dynamic QR token for an in-store payment.
     * The token is meant to be displayed by the mobile app and scanned
     * by the cashier device, which then calls /admin/wallet/qr/redeem.
     */
    public function generateQr(Request $request)
    {
        $request->validate([
            'amount'    => 'required|numeric|min:0.01',
            'branch_id' => 'required|exists:branches,id',
            'order_id'  => 'nullable|exists:orders,id',
        ]);

        try {
            $purchase = $this->wallet->createPurchaseIntent($request->user(), [
                'amount'          => $request->amount,
                'branch_id'       => $request->branch_id,
                'order_id'        => $request->order_id,
                'idempotency_key' => $request->header('Idempotency-Key'),
            ]);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'purchase'    => $purchase->only([
                'id', 'reference_no', 'amount', 'branch_id', 'status',
                'qr_expires_at', 'created_at',
            ]),
            'qr_token'    => $purchase->qr_token,
            'qr_payload'  => 'DALWAL:'.$purchase->qr_token,
            'expires_at'  => $purchase->qr_expires_at,
        ], 201);
    }

    // POST /user/wallet/refund ────────────────────────────────────────
    /**
     * Customer-initiated refund request. Always lands as `pending` for
     * admin review — refunds are never auto-approved from the mobile app.
     */
    public function requestRefund(Request $request)
    {
        $request->validate([
            'reference_no' => 'required|string',
            'reason'       => 'nullable|string|max:500',
        ]);

        $user   = $request->user();
        $wallet = $user->getOrCreateWallet();

        $purchase = Purchase::where('reference_no', $request->reference_no)
            ->where('user_id', $user->id)
            ->first();

        if (! $purchase) {
            return response()->json([
                'message'    => 'Purchase not found or not yours.',
                'error_code' => 'purchase_not_found',
            ], 404);
        }

        if ($purchase->status !== 'completed') {
            return response()->json([
                'message'    => 'Only completed purchases can be refunded.',
                'error_code' => 'invalid_purchase_state',
            ], 422);
        }

        // Prevent duplicate open refund requests for the same purchase.
        $hasOpen = Refund::where('refundable_type', Purchase::class)
            ->where('refundable_id', $purchase->id)
            ->whereIn('status', ['pending', 'approved', 'processing', 'completed'])
            ->exists();

        if ($hasOpen) {
            return response()->json([
                'message'    => 'A refund request already exists for this purchase.',
                'error_code' => 'refund_already_exists',
            ], 409);
        }

        $refund = Refund::create([
            'wallet_id'        => $wallet->id,
            'user_id'          => $user->id,
            'refundable_type'  => Purchase::class,
            'refundable_id'    => $purchase->id,
            'amount'           => $purchase->amount,
            'currency'         => $purchase->currency,
            'method'           => 'wallet',
            'status'           => 'pending',
            'reason'           => $request->reason,
        ]);

        return response()->json([
            'message' => 'Refund request submitted. Our team will review it shortly.',
            'refund'  => $refund,
        ], 201);
    }
}
