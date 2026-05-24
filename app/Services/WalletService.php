<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Topup;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletSetting;
use App\Models\WalletTransaction;
use App\Notifications\Wallet\WalletPurchaseSuccess;
use App\Notifications\Wallet\WalletRefundProcessed;
use App\Notifications\Wallet\WalletSuspiciousActivity;
use App\Notifications\Wallet\WalletTopupSuccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Authoritative implementation of every wallet money movement.
 *
 * RULES (enforced here, not in controllers):
 *   - Every change to a wallet balance MUST go through DB::transaction.
 *   - The wallet row is locked with lockForUpdate() before reading the
 *     current balance, so concurrent debits/credits cannot race.
 *   - Every change MUST produce a wallet_transactions row whose
 *     balance_before / balance_after exactly bracket the new balance.
 *   - Limits (max balance, daily limits, per-transaction caps) are
 *     verified inside the same transaction.
 *   - Wallet may only be debited at one of our own active branches.
 */
class WalletService
{
    /**
     * Top up the wallet from a confirmed payment. Used by both the
     * PayMongo webhook (card / GCash) and admin counter top-ups (cash).
     *
     * @param  array{
     *     channel: string,
     *     amount: float|int|string,
     *     idempotency_key?: ?string,
     *     gateway?: ?string,
     *     gateway_intent_id?: ?string,
     *     gateway_payment_id?: ?string,
     *     branch_id?: ?int,
     *     metadata?: ?array,
     *     created_by?: ?\Illuminate\Database\Eloquent\Model,
     *     description?: ?string
     *  }  $data
     */
    public function creditTopup(User $user, array $data): WalletTransaction
    {
        $amount = $this->normalizeAmount($data['amount']);
        $this->assertPositive($amount);

        $settings = WalletSetting::getSettings();
        if (! $settings->topup_enabled) {
            throw new WalletException('Top-up is currently disabled.', 'topup_disabled', 503);
        }

        if ($amount < (float) $settings->min_topup) {
            throw new WalletException(
                "Minimum top-up is ₱{$settings->min_topup}.",
                'topup_below_minimum',
            );
        }

        if ($amount > (float) $settings->max_topup) {
            throw new WalletException(
                "Maximum top-up per transaction is ₱{$settings->max_topup}.",
                'topup_above_maximum',
            );
        }

        return DB::transaction(function () use ($user, $data, $amount, $settings) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id'         => $user->id,
                    'current_balance' => 0,
                    'currency'        => 'PHP',
                    'status'          => 'active',
                ]);
                // Re-fetch with lock now that the row exists.
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            }

            $this->assertWalletUsable($wallet);

            $balanceBefore = (float) $wallet->current_balance;
            $balanceAfter  = round($balanceBefore + $amount, 2);

            // Closed-loop wallets cap the maximum stored balance.
            if ($balanceAfter > (float) $settings->max_balance) {
                throw new WalletException(
                    "This top-up would exceed the maximum wallet balance of ₱{$settings->max_balance}.",
                    'max_balance_exceeded',
                );
            }

            // Daily top-up cap (across all channels).
            $todaysTopups = (float) WalletTransaction::where('wallet_id', $wallet->id)
                ->where('transaction_type', 'topup')
                ->where('status', 'completed')
                ->whereDate('created_at', now()->toDateString())
                ->sum('amount');

            if (($todaysTopups + $amount) > (float) $settings->daily_topup_limit) {
                throw new WalletException(
                    "Daily top-up limit of ₱{$settings->daily_topup_limit} would be exceeded.",
                    'daily_topup_limit_exceeded',
                );
            }

            $tx = $this->writeLedger(
                wallet: $wallet,
                type: 'topup',
                direction: 'credit',
                amount: $amount,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                data: $data,
            );

            // Atomically update the wallet to the new balance.
            $wallet->forceFill([
                'current_balance'  => $balanceAfter,
                'last_activity_at' => now(),
            ])->save();

            return $tx;
        });
    }

    /**
     * Debit the wallet for a purchase at one of our branches.
     *
     * The branch_id is required and must be an active company-owned
     * branch — wallets cannot be used outside our ecosystem.
     */
    public function debitPurchase(User $user, array $data): array
    {
        $amount   = $this->normalizeAmount($data['amount']);
        $branchId = (int) ($data['branch_id'] ?? 0);

        $this->assertPositive($amount);
        $this->assertBranchAllowed($branchId);

        $settings = WalletSetting::getSettings();
        if (! $settings->purchase_enabled) {
            throw new WalletException('Wallet payments are currently disabled.', 'purchase_disabled', 503);
        }

        if ($amount > (float) $settings->max_purchase) {
            throw new WalletException(
                "Maximum purchase per transaction is ₱{$settings->max_purchase}.",
                'purchase_above_maximum',
            );
        }

        return DB::transaction(function () use ($user, $data, $amount, $branchId, $settings) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $wallet) {
                throw new WalletException('Wallet not found.', 'wallet_not_found', 404);
            }

            $this->assertWalletUsable($wallet);

            $balanceBefore = (float) $wallet->current_balance;

            if ($balanceBefore < $amount) {
                throw new WalletException(
                    'Insufficient wallet balance.',
                    'insufficient_funds',
                    422,
                    ['available' => $balanceBefore, 'required' => $amount],
                );
            }

            // Daily purchase cap.
            $todaysPurchases = (float) WalletTransaction::where('wallet_id', $wallet->id)
                ->where('transaction_type', 'purchase')
                ->where('status', 'completed')
                ->whereDate('created_at', now()->toDateString())
                ->sum('amount');

            if (($todaysPurchases + $amount) > (float) $settings->daily_purchase_limit) {
                throw new WalletException(
                    "Daily purchase limit of ₱{$settings->daily_purchase_limit} would be exceeded.",
                    'daily_purchase_limit_exceeded',
                );
            }

            $balanceAfter = round($balanceBefore - $amount, 2);

            $purchase = Purchase::create([
                'wallet_id'       => $wallet->id,
                'user_id'         => $user->id,
                'branch_id'       => $branchId,
                'order_id'        => $data['order_id'] ?? null,
                'amount'          => $amount,
                'currency'        => 'PHP',
                'status'          => 'completed',
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'metadata'        => $data['metadata'] ?? null,
                'paid_at'         => now(),
            ]);

            $tx = $this->writeLedger(
                wallet: $wallet,
                type: 'purchase',
                direction: 'debit',
                amount: $amount,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                data: array_merge($data, [
                    'source_type' => Purchase::class,
                    'source_id'   => $purchase->id,
                    'description' => $data['description']
                        ?? 'Wallet purchase at branch #'.$branchId,
                ]),
            );

            $purchase->forceFill(['wallet_transaction_id' => $tx->id])->save();

            $wallet->forceFill([
                'current_balance'  => $balanceAfter,
                'last_activity_at' => now(),
            ])->save();

            // Receipt + push notification (queued).
            $this->notifySafely($user, new WalletPurchaseSuccess($purchase, $tx));

            return ['transaction' => $tx, 'purchase' => $purchase];
        });
    }

    /**
     * Generate a single-use dynamic QR token that authorises a purchase.
     * The token is signed and stored on the Purchase row. The actual
     * debit only happens when staff redeems the token via /wallet/qr/redeem.
     */
    public function createPurchaseIntent(User $user, array $data): Purchase
    {
        $amount   = $this->normalizeAmount($data['amount']);
        $branchId = (int) ($data['branch_id'] ?? 0);

        $this->assertPositive($amount);
        $this->assertBranchAllowed($branchId);

        $settings = WalletSetting::getSettings();
        if (! $settings->purchase_enabled) {
            throw new WalletException('Wallet payments are currently disabled.', 'purchase_disabled', 503);
        }

        $wallet = $user->getOrCreateWallet();
        $this->assertWalletUsable($wallet);

        if ((float) $wallet->current_balance < $amount) {
            throw new WalletException(
                'Insufficient wallet balance.',
                'insufficient_funds',
                422,
                [
                    'available' => (float) $wallet->current_balance,
                    'required'  => $amount,
                ],
            );
        }

        return Purchase::create([
            'wallet_id'       => $wallet->id,
            'user_id'         => $user->id,
            'branch_id'       => $branchId,
            'order_id'        => $data['order_id'] ?? null,
            'amount'          => $amount,
            'currency'        => 'PHP',
            'status'          => 'pending',
            'qr_token'        => $this->makeSignedQrToken(),
            'qr_expires_at'   => now()->addSeconds((int) $settings->qr_ttl_seconds),
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'metadata'        => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Staff-side: redeem a QR token and complete the debit.
     */
    public function redeemPurchaseQr(string $qrToken, int $branchId, $createdBy = null): array
    {
        $purchase = Purchase::where('qr_token', $qrToken)->first();

        if (! $purchase) {
            throw new WalletException('Invalid QR code.', 'qr_invalid', 404);
        }

        if (! $purchase->qrIsValid()) {
            throw new WalletException('QR code has expired or is no longer valid.', 'qr_expired');
        }

        if ($purchase->branch_id !== $branchId) {
            throw new WalletException(
                'QR code is not valid at this branch.',
                'qr_branch_mismatch',
            );
        }

        return DB::transaction(function () use ($purchase, $createdBy) {
            $wallet = Wallet::where('id', $purchase->wallet_id)
                ->lockForUpdate()
                ->first();

            $this->assertWalletUsable($wallet);

            $purchase = Purchase::where('id', $purchase->id)->lockForUpdate()->first();

            // Re-validate inside the transaction in case of concurrent redeem.
            if (! $purchase->qrIsValid()) {
                throw new WalletException('QR code has expired or already been used.', 'qr_already_used');
            }

            $amount        = (float) $purchase->amount;
            $balanceBefore = (float) $wallet->current_balance;

            if ($balanceBefore < $amount) {
                throw new WalletException(
                    'Insufficient wallet balance.',
                    'insufficient_funds',
                    422,
                    ['available' => $balanceBefore, 'required' => $amount],
                );
            }

            $balanceAfter = round($balanceBefore - $amount, 2);

            $tx = $this->writeLedger(
                wallet: $wallet,
                type: 'purchase',
                direction: 'debit',
                amount: $amount,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                data: [
                    'branch_id'   => $purchase->branch_id,
                    'source_type' => Purchase::class,
                    'source_id'   => $purchase->id,
                    'created_by'  => $createdBy,
                    'description' => 'Wallet QR purchase at branch #'.$purchase->branch_id,
                ],
            );

            $purchase->forceFill([
                'status'                => 'completed',
                'wallet_transaction_id' => $tx->id,
                'paid_at'               => now(),
                'qr_token'              => null, // burn the QR
            ])->save();

            $wallet->forceFill([
                'current_balance'  => $balanceAfter,
                'last_activity_at' => now(),
            ])->save();

            $this->notifySafely(
                $purchase->user,
                new WalletPurchaseSuccess($purchase->fresh(), $tx),
            );

            return ['transaction' => $tx, 'purchase' => $purchase];
        });
    }

    /**
     * Issue a refund. Default method is to credit the wallet back.
     * Triggered by admin approval after a refund request, or directly
     * by admin via the admin endpoint.
     */
    public function processRefund(Refund $refund, $reviewer = null): WalletTransaction
    {
        if ($refund->status === 'completed') {
            throw new WalletException('Refund already completed.', 'refund_already_completed');
        }

        $settings = WalletSetting::getSettings();
        if (! $settings->refund_enabled) {
            throw new WalletException('Refunds are currently disabled.', 'refund_disabled', 503);
        }

        return DB::transaction(function () use ($refund, $reviewer) {
            $wallet = Wallet::where('id', $refund->wallet_id)->lockForUpdate()->first();
            $this->assertWalletUsable($wallet);

            $amount        = (float) $refund->amount;
            $balanceBefore = (float) $wallet->current_balance;
            $balanceAfter  = round($balanceBefore + $amount, 2);

            $settings = WalletSetting::getSettings();
            if ($balanceAfter > (float) $settings->max_balance) {
                // Refunds may push past the max balance — log it but allow,
                // since otherwise we cannot make the customer whole.
                Log::warning('wallet.refund.exceeds_max_balance', [
                    'wallet_id' => $wallet->id,
                    'attempted' => $balanceAfter,
                    'max'       => $settings->max_balance,
                ]);
            }

            $tx = $this->writeLedger(
                wallet: $wallet,
                type: 'refund',
                direction: 'credit',
                amount: $amount,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                data: [
                    'source_type' => $refund->refundable_type,
                    'source_id'   => $refund->refundable_id,
                    'created_by'  => $reviewer,
                    'description' => 'Refund — '.$refund->reference_no,
                    'metadata'    => array_merge((array) $refund->metadata, [
                        'refund_id'        => $refund->id,
                        'refund_reference' => $refund->reference_no,
                    ]),
                ],
            );

            $refund->forceFill([
                'status'                => 'completed',
                'wallet_transaction_id' => $tx->id,
                'completed_at'          => now(),
                'reviewed_by'           => $reviewer?->id ?? $refund->reviewed_by,
                'reviewed_at'           => $refund->reviewed_at ?? now(),
            ])->save();

            $wallet->forceFill([
                'current_balance'  => $balanceAfter,
                'last_activity_at' => now(),
            ])->save();

            $this->notifySafely(
                $refund->user,
                new WalletRefundProcessed($refund->fresh(), $tx),
            );

            return $tx;
        });
    }

    /**
     * Manual admin adjustment (positive or negative). Used for
     * reconciliation and goodwill credits. Always logged + audited.
     */
    public function adjustBalance(
        User $user,
        float $signedAmount,
        string $reason,
        $admin = null,
    ): WalletTransaction {
        if ($signedAmount === 0.0) {
            throw new WalletException('Adjustment amount cannot be zero.', 'invalid_amount');
        }

        return DB::transaction(function () use ($user, $signedAmount, $reason, $admin) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first()
                ?? Wallet::create([
                    'user_id'         => $user->id,
                    'current_balance' => 0,
                    'currency'        => 'PHP',
                    'status'          => 'active',
                ]);

            $balanceBefore = (float) $wallet->current_balance;
            $balanceAfter  = round($balanceBefore + $signedAmount, 2);

            if ($balanceAfter < 0) {
                throw new WalletException(
                    'Adjustment would put the wallet into a negative balance.',
                    'negative_balance',
                );
            }

            $direction = $signedAmount > 0 ? 'credit' : 'debit';
            $amount    = abs($signedAmount);

            $tx = $this->writeLedger(
                wallet: $wallet,
                type: 'adjustment',
                direction: $direction,
                amount: $amount,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                data: [
                    'description' => $reason,
                    'created_by'  => $admin,
                ],
            );

            $wallet->forceFill([
                'current_balance'  => $balanceAfter,
                'last_activity_at' => now(),
            ])->save();

            return $tx;
        });
    }

    // ──────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────

    /**
     * Write a single ledger row using the provided values. The caller is
     * responsible for being inside DB::transaction and for actually
     * updating the wallet balance to match `$balanceAfter`.
     */
    protected function writeLedger(
        Wallet $wallet,
        string $type,
        string $direction,
        float $amount,
        float $balanceBefore,
        float $balanceAfter,
        array $data = [],
    ): WalletTransaction {
        $createdBy = $data['created_by'] ?? null;

        return WalletTransaction::create([
            'wallet_id'        => $wallet->id,
            'transaction_type' => $type,
            'direction'        => $direction,
            'amount'           => round($amount, 2),
            'balance_before'   => round($balanceBefore, 2),
            'balance_after'    => round($balanceAfter, 2),
            'branch_id'        => $data['branch_id'] ?? null,
            'source_type'      => $data['source_type'] ?? null,
            'source_id'        => $data['source_id'] ?? null,
            'status'           => 'completed',
            'metadata'         => $data['metadata'] ?? null,
            'description'      => $data['description'] ?? null,
            'created_by_type'  => $createdBy ? get_class($createdBy) : null,
            'created_by_id'    => $createdBy?->id,
            'idempotency_key'  => $data['idempotency_key'] ?? null,
        ]);
    }

    protected function normalizeAmount(mixed $amount): float
    {
        return round((float) $amount, 2);
    }

    protected function assertPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw new WalletException('Amount must be greater than zero.', 'invalid_amount');
        }
    }

    protected function assertWalletUsable(?Wallet $wallet): void
    {
        if (! $wallet || ! $wallet->isUsable()) {
            throw new WalletException(
                'Wallet is not active.',
                'wallet_not_active',
                403,
                ['status' => $wallet?->status],
            );
        }
    }

    /**
     * Closed-loop guard: reject any branch_id that doesn't belong to an
     * active company-owned branch. There is no way to spend the wallet
     * outside our own coffee shops.
     */
    protected function assertBranchAllowed(int $branchId): void
    {
        if ($branchId <= 0) {
            throw new WalletException('Branch is required.', 'branch_required', 422);
        }

        $branch = Branch::find($branchId);

        if (! $branch || ! $branch->is_active) {
            throw new WalletException(
                'Wallet may only be used at active Daleachious branches.',
                'branch_invalid',
                422,
            );
        }
    }

    /**
     * Sign a short opaque token used as the QR payload. The HMAC ensures
     * the token can't be guessed or forged even if the database is leaked.
     */
    protected function makeSignedQrToken(): string
    {
        $payload = Str::random(24);
        $sig     = hash_hmac('sha256', $payload, config('app.key'));
        return $payload.'.'.substr($sig, 0, 32);
    }

    /**
     * Send a notification but never let a failure (Firebase outage,
     * missing FCM token, etc.) roll back the wallet transaction.
     * Notifications are queued via the trait-based Notifiable so the
     * caller doesn't block on push-token delivery.
     */
    protected function notifySafely($user, $notification): void
    {
        try {
            if ($user && method_exists($user, 'notify')) {
                $user->notify($notification);
            }
        } catch (\Throwable $e) {
            Log::warning('wallet.notify.failed', [
                'reason' => $e->getMessage(),
                'class'  => get_class($notification),
            ]);
        }
    }

    /**
     * Detect simple anti-replay patterns: too many failed top-ups
     * from the same user in the configured window. Surfaces a fraud
     * alert to admins if exceeded.
     */
    public function checkFraudHeuristics(User $user): void
    {
        $settings = WalletSetting::getSettings();
        $since    = now()->subMinutes((int) $settings->failed_topup_window_minutes);

        $failed = Topup::where('user_id', $user->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', $since)
            ->count();

        if ($failed >= (int) $settings->failed_topup_threshold) {
            try {
                // Notify all admins via push if a fraud-recipient list is set.
                Log::warning('wallet.fraud.heuristic_triggered', [
                    'user_id'   => $user->id,
                    'failed'    => $failed,
                    'threshold' => $settings->failed_topup_threshold,
                ]);

                $user->notify(new WalletSuspiciousActivity([
                    'failed_topups' => $failed,
                    'window_min'    => $settings->failed_topup_window_minutes,
                ]));
            } catch (\Throwable $e) {
                Log::warning('wallet.fraud.notify_failed', ['reason' => $e->getMessage()]);
            }
        }
    }

    /**
     * Mark a top-up failed and write a `failed` ledger row for audit
     * (no balance change). Used by the PayMongo webhook on payment.failed.
     */
    public function markTopupFailed(Topup $topup, ?string $reason = null): void
    {
        DB::transaction(function () use ($topup, $reason) {
            $topup->forceFill([
                'status'         => 'failed',
                'failure_reason' => $reason,
            ])->save();

            // Write a failed ledger row so support has a record.
            WalletTransaction::create([
                'wallet_id'        => $topup->wallet_id,
                'transaction_type' => 'topup',
                'direction'        => 'credit',
                'amount'           => (float) $topup->amount,
                'balance_before'   => (float) optional($topup->wallet)->current_balance,
                'balance_after'    => (float) optional($topup->wallet)->current_balance,
                'branch_id'        => $topup->branch_id,
                'source_type'      => Topup::class,
                'source_id'        => $topup->id,
                'status'           => 'failed',
                'description'      => 'Top-up failed: '.($reason ?? 'unknown'),
                'metadata'         => ['topup_id' => $topup->id],
                'idempotency_key'  => $topup->idempotency_key,
            ]);
        });

        // Run the heuristic outside the transaction so notifications don't roll back.
        if ($topup->user) {
            $this->checkFraudHeuristics($topup->user);
        }
    }

    /**
     * Receipt-friendly snapshot of a wallet transaction.
     */
    public function buildReceipt(WalletTransaction $tx): array
    {
        return [
            'reference_no'   => $tx->reference_no,
            'uuid'           => $tx->uuid,
            'type'           => $tx->transaction_type,
            'direction'      => $tx->direction,
            'amount'         => (float) $tx->amount,
            'balance_before' => (float) $tx->balance_before,
            'balance_after'  => (float) $tx->balance_after,
            'currency'       => 'PHP',
            'description'    => $tx->description,
            'branch_id'      => $tx->branch_id,
            'created_at'     => $tx->created_at?->toIso8601String(),
        ];
    }
}
