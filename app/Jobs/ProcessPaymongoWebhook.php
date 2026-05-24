<?php

namespace App\Jobs;

use App\Models\Topup;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes a verified PayMongo webhook event off-thread. The HTTP
 * controller verifies the signature, persists the raw event, and then
 * dispatches this job — the gateway gets a fast 200 OK back regardless
 * of how long it takes to credit the wallet.
 */
class ProcessPaymongoWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $backoff = 30;

    public function __construct(
        public array $event,
    ) {
        $this->onQueue('payments');
    }

    public function handle(WalletService $wallet): void
    {
        $type = $this->event['data']['attributes']['type'] ?? null;
        $data = $this->event['data']['attributes']['data'] ?? null;

        if (! $type || ! $data) {
            Log::warning('paymongo.webhook.invalid_payload', $this->event);
            return;
        }

        $attributes = $data['attributes'] ?? [];
        $metadata   = $attributes['metadata'] ?? [];
        $topupId    = $metadata['topup_id'] ?? null;

        $topup = $topupId ? Topup::find($topupId) : $this->resolveTopupFromGateway($attributes);

        if (! $topup) {
            Log::warning('paymongo.webhook.topup_not_found', [
                'type'     => $type,
                'metadata' => $metadata,
            ]);
            return;
        }

        // Already processed — webhooks can be retried by PayMongo.
        if ($topup->status === 'succeeded') {
            return;
        }

        match ($type) {
            'payment.paid',
            'checkout_session.payment.paid',
            'source.chargeable' => $this->handlePaid($wallet, $topup, $attributes),

            'payment.failed' => $wallet->markTopupFailed(
                $topup,
                $attributes['failed_message'] ?? 'payment_failed',
            ),

            default => Log::info('paymongo.webhook.ignored', ['type' => $type]),
        };
    }

    protected function handlePaid($wallet, Topup $topup, array $attributes): void
    {
        $user = $topup->user ?? User::find($topup->user_id);
        if (! $user) {
            Log::warning('paymongo.webhook.user_missing', ['topup_id' => $topup->id]);
            return;
        }

        // Pull authoritative amount from gateway, ignore client-sent value.
        $gatewayAmountCentavos = $attributes['amount'] ?? null;
        if ($gatewayAmountCentavos !== null) {
            $gatewayAmount = round(((int) $gatewayAmountCentavos) / 100, 2);
            if (abs($gatewayAmount - (float) $topup->amount) > 0.01) {
                Log::warning('paymongo.webhook.amount_mismatch', [
                    'topup_id'  => $topup->id,
                    'expected'  => $topup->amount,
                    'received'  => $gatewayAmount,
                ]);
                $wallet->markTopupFailed($topup, 'amount_mismatch');
                return;
            }
        }

        $tx = $wallet->creditTopup($user, [
            'channel'            => $topup->channel,
            'amount'             => $topup->amount,
            'idempotency_key'    => $topup->idempotency_key,
            'gateway'            => 'paymongo',
            'gateway_intent_id'  => $topup->gateway_intent_id,
            'gateway_payment_id' => $attributes['id'] ?? $topup->gateway_payment_id,
            'metadata'           => [
                'paymongo_event_id' => $this->event['data']['id'] ?? null,
                'gateway_payload'   => $attributes,
            ],
            'description' => 'Top-up via PayMongo ('.$topup->channel.')',
        ]);

        $topup->forceFill([
            'status'                => 'succeeded',
            'gateway_payment_id'    => $attributes['id'] ?? $topup->gateway_payment_id,
            'wallet_transaction_id' => $tx->id,
            'paid_at'               => now(),
        ])->save();

        $user->notify(new \App\Notifications\Wallet\WalletTopupSuccess($topup->fresh(), $tx));
    }

    /**
     * Fallback when metadata.topup_id is missing — try to find a topup
     * by the gateway-supplied IDs.
     */
    protected function resolveTopupFromGateway(array $attributes): ?Topup
    {
        $intentId  = $attributes['payment_intent_id'] ?? null;
        $paymentId = $attributes['id'] ?? null;

        return Topup::query()
            ->when($intentId,  fn ($q) => $q->orWhere('gateway_intent_id', $intentId))
            ->when($paymentId, fn ($q) => $q->orWhere('gateway_payment_id', $paymentId))
            ->latest()
            ->first();
    }
}
