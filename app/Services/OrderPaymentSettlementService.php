<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marks an order paid when a PayMongo payment is confirmed.
 * Used by webhooks and by the client "confirm" poll after checkout.
 */
class OrderPaymentSettlementService
{
    public function __construct(
        protected PayMongoService $paymongo,
        protected LoyaltyPointsService $loyaltyPoints,
    ) {}

    /**
     * @return array{order: Order, transaction: Transaction, paid: bool, pending: bool, points: array<string, mixed>|null}
     */
    public function settleFromGateway(Transaction $transaction): array
    {
        $transaction->refresh();

        if ($transaction->status === 'paid') {
            $order = $transaction->order ?? Order::find($transaction->order_id);

            return [
                'order'       => $order,
                'transaction' => $transaction,
                'paid'        => true,
                'pending'     => false,
                'points'      => null,
            ];
        }

        if (! $transaction->gateway_checkout_id) {
            return [
                'order'       => $transaction->order,
                'transaction' => $transaction,
                'paid'        => false,
                'pending'     => true,
                'points'      => null,
            ];
        }

        $session = $this->paymongo->retrieveCheckoutSession($transaction->gateway_checkout_id);
        $payment = $this->paymongo->findPaidPayment($session);

        if (! $payment) {
            return [
                'order'       => $transaction->order,
                'transaction' => $transaction,
                'paid'        => false,
                'pending'     => true,
                'points'      => null,
            ];
        }

        $result = $this->markPaidFromPayment($transaction, $payment);

        return [
            'order'       => $result['order'],
            'transaction' => $result['transaction'],
            'paid'        => $result['newly_paid'],
            'pending'     => false,
            'points'      => $result['points'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array{order: Order, transaction: Transaction, newly_paid: bool, points: array<string, mixed>|null}
     */
    public function markPaidFromPayment(Transaction $transaction, array $payment): array
    {
        return DB::transaction(function () use ($transaction, $payment) {
            /** @var Transaction|null $locked */
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new \RuntimeException('Transaction missing.');
            }

            /** @var Order|null $order */
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->first();
            if (! $order) {
                throw new \RuntimeException('Order missing.');
            }

            if ($locked->status === 'paid') {
                return [
                    'order'       => $order->load(['items.addons', 'transaction']),
                    'transaction' => $locked,
                    'newly_paid'  => false,
                    'points'      => null,
                ];
            }

            $attributes = $payment['attributes'] ?? [];
            $gatewayAmountCentavos = $attributes['amount'] ?? null;

            if ($gatewayAmountCentavos !== null) {
                $gatewayAmount = round(((int) $gatewayAmountCentavos) / 100, 2);
                if (abs($gatewayAmount - (float) $locked->amount) > 0.01) {
                    Log::warning('paymongo.order.amount_mismatch', [
                        'transaction_id' => $locked->id,
                        'expected'       => $locked->amount,
                        'received'       => $gatewayAmount,
                    ]);

                    $locked->update(['status' => 'failed']);

                    return [
                        'order'       => $order->load(['items.addons', 'transaction']),
                        'transaction' => $locked->fresh(),
                        'newly_paid'  => false,
                        'points'      => null,
                    ];
                }
            }

            $paymentId = $payment['id'] ?? ($attributes['id'] ?? $locked->gateway_payment_id);

            $locked->update([
                'status'             => 'paid',
                'gateway_payment_id' => $paymentId,
                'paid_at'            => now(),
            ]);

            if ($order->status === 'pending') {
                $order->update(['status' => 'confirmed']);
            }

            $points = $this->loyaltyPoints->awardForOrder($order->fresh());

            return [
                'order'       => $order->fresh()->load(['items.addons', 'transaction']),
                'transaction' => $locked->fresh(),
                'newly_paid'  => true,
                'points'      => $points,
            ];
        });
    }

    public function markFailed(Transaction $transaction, ?string $reason = null): void
    {
        DB::transaction(function () use ($transaction, $reason) {
            /** @var Transaction|null $locked */
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'paid') {
                return;
            }

            $locked->update(['status' => 'failed']);

            Log::info('paymongo.order.failed', [
                'transaction_id' => $locked->id,
                'reason'         => $reason,
            ]);
        });
    }
}
