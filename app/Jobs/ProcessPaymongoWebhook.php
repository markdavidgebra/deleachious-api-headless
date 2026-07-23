<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\OrderPaymentSettlementService;
use App\Services\PayMongoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes a verified PayMongo webhook event for order payments.
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

    public function handle(OrderPaymentSettlementService $settlement): void
    {
        $type = $this->event['data']['attributes']['type'] ?? null;
        $data = $this->event['data']['attributes']['data'] ?? null;

        if (! $type || ! $data) {
            Log::warning('paymongo.webhook.invalid_payload', $this->event);

            return;
        }

        $attributes = $data['attributes'] ?? [];
        $metadata   = $attributes['metadata'] ?? [];

        $transaction = $this->resolveTransaction($data, $attributes, $metadata);

        if (! $transaction) {
            Log::warning('paymongo.webhook.transaction_not_found', [
                'type'     => $type,
                'metadata' => $metadata,
            ]);

            return;
        }

        if ($transaction->status === 'paid') {
            return;
        }

        match ($type) {
            'payment.paid',
            'checkout_session.payment.paid',
            'source.chargeable' => $this->handlePaid($settlement, $transaction, $data, $attributes),

            'payment.failed',
            'qrph.expired' => $settlement->markFailed(
                $transaction,
                $attributes['failed_message'] ?? ($type === 'qrph.expired' ? 'qrph_expired' : 'payment_failed'),
            ),

            default => Log::info('paymongo.webhook.ignored', ['type' => $type]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    protected function handlePaid(
        OrderPaymentSettlementService $settlement,
        Transaction $transaction,
        array $data,
        array $attributes,
    ): void {
        $payment = null;
        if (($data['type'] ?? null) === 'checkout_session' || isset($attributes['payments'])) {
            $payment = app(PayMongoService::class)->findPaidPayment($data)
                ?? (isset($attributes['payments'][0]) ? $attributes['payments'][0] : null);
        }

        if (! $payment && (($data['type'] ?? null) === 'payment' || isset($attributes['status']))) {
            $payment = $data;
        }

        if (! $payment) {
            $settlement->settleFromGateway($transaction);

            return;
        }

        $settlement->markPaidFromPayment($transaction, $payment);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $metadata
     */
    protected function resolveTransaction(array $data, array $attributes, array $metadata): ?Transaction
    {
        if (! empty($metadata['transaction_id'])) {
            $tx = Transaction::find($metadata['transaction_id']);
            if ($tx) {
                return $tx;
            }
        }

        if (! empty($metadata['order_id'])) {
            $tx = Transaction::query()
                ->where('order_id', $metadata['order_id'])
                ->latest()
                ->first();
            if ($tx) {
                return $tx;
            }
        }

        $sessionId = ($data['type'] ?? null) === 'checkout_session'
            ? ($data['id'] ?? null)
            : null;
        $paymentId = (($data['type'] ?? null) === 'payment')
            ? ($data['id'] ?? null)
            : ($attributes['id'] ?? null);

        return Transaction::query()
            ->when($sessionId, fn ($q) => $q->orWhere('gateway_checkout_id', $sessionId))
            ->when($paymentId, fn ($q) => $q->orWhere('gateway_payment_id', $paymentId))
            ->when($attributes['reference_number'] ?? null, function ($q) use ($attributes) {
                $q->orWhere('reference_number', $attributes['reference_number']);
            })
            ->latest()
            ->first();
    }
}
