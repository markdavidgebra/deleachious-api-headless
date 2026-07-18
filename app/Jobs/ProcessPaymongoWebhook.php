<?php

namespace App\Jobs;

use App\Models\Topup;
use App\Services\TopupSettlementService;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes a verified PayMongo webhook event.
 *
 * The controller preferably runs this synchronously (`dispatchSync`) so
 * wallet credit does not depend on a queue worker being online.
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

    public function handle(TopupSettlementService $settlement, WalletService $wallet): void
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

        $topup = $topupId ? Topup::find($topupId) : $this->resolveTopupFromGateway($data, $attributes);

        if (! $topup) {
            Log::warning('paymongo.webhook.topup_not_found', [
                'type'     => $type,
                'metadata' => $metadata,
            ]);
            return;
        }

        if ($topup->status === 'succeeded') {
            return;
        }

        match ($type) {
            'payment.paid',
            'checkout_session.payment.paid',
            'source.chargeable' => $this->handlePaid($settlement, $topup, $data, $attributes),

            'payment.failed' => $wallet->markTopupFailed(
                $topup,
                $attributes['failed_message'] ?? 'payment_failed',
            ),

            default => Log::info('paymongo.webhook.ignored', ['type' => $type]),
        };
    }

    /**
     * @param  array<string, mixed>  $data        Nested resource (`payment` or `checkout_session`)
     * @param  array<string, mixed>  $attributes  Resource attributes
     */
    protected function handlePaid(
        TopupSettlementService $settlement,
        Topup $topup,
        array $data,
        array $attributes,
    ): void {
        // Checkout session webhooks embed payments[]; payment webhooks are the payment itself.
        $payment = null;
        if (($data['type'] ?? null) === 'checkout_session' || isset($attributes['payments'])) {
            $payment = app(\App\Services\PayMongoService::class)->findPaidPayment($data)
                ?? (isset($attributes['payments'][0]) ? $attributes['payments'][0] : null);
        }

        if (! $payment && (($data['type'] ?? null) === 'payment' || isset($attributes['status']))) {
            $payment = $data;
        }

        if (! $payment) {
            // Fall back to retrieving the checkout session from PayMongo.
            $settlement->settleFromGateway($topup);
            return;
        }

        $settlement->creditFromPaidPayment($topup, $payment);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    protected function resolveTopupFromGateway(array $data, array $attributes): ?Topup
    {
        $sessionId = ($data['type'] ?? null) === 'checkout_session'
            ? ($data['id'] ?? null)
            : null;
        $intentId  = $attributes['payment_intent_id']
            ?? ($attributes['payment_intent']['id'] ?? null)
            ?? $sessionId;
        $paymentId = $attributes['id'] ?? ($data['id'] ?? null);

        return Topup::query()
            ->when($sessionId, fn ($q) => $q->orWhere('gateway_intent_id', $sessionId))
            ->when($intentId,  fn ($q) => $q->orWhere('gateway_intent_id', $intentId))
            ->when($paymentId, fn ($q) => $q->orWhere('gateway_payment_id', $paymentId))
            ->when($attributes['reference_number'] ?? null, function ($q) use ($attributes) {
                $q->orWhere('reference_no', $attributes['reference_number']);
            })
            ->latest()
            ->first();
    }
}
