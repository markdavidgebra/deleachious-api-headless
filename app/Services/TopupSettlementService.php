<?php

namespace App\Services;

use App\Models\Topup;
use App\Models\User;
use App\Notifications\Wallet\WalletTopupSuccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Credits the wallet when a PayMongo top-up payment is confirmed.
 * Used by webhooks and by the client "confirm" poll after checkout.
 */
class TopupSettlementService
{
    public function __construct(
        protected WalletService $wallet,
        protected PayMongoService $paymongo,
    ) {}

    /**
     * Ask PayMongo for the checkout session and credit the wallet if paid.
     *
     * @return array{topup: Topup, credited: bool, pending: bool}
     */
    public function settleFromGateway(Topup $topup): array
    {
        $topup->refresh();

        if ($topup->status === 'succeeded') {
            return ['topup' => $topup, 'credited' => false, 'pending' => false];
        }

        if (! $topup->gateway_intent_id) {
            return ['topup' => $topup, 'credited' => false, 'pending' => true];
        }

        $session = $this->paymongo->retrieveCheckoutSession($topup->gateway_intent_id);
        $payment = $this->paymongo->findPaidPayment($session);

        if (! $payment) {
            return ['topup' => $topup, 'credited' => false, 'pending' => true];
        }

        $credited = $this->creditFromPaidPayment($topup, $payment);

        return [
            'topup'    => $topup->fresh(),
            'credited' => $credited,
            'pending'  => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payment  PayMongo payment resource (`id` + `attributes`)
     */
    public function creditFromPaidPayment(Topup $topup, array $payment): bool
    {
        return DB::transaction(function () use ($topup, $payment) {
            /** @var Topup|null $locked */
            $locked = Topup::whereKey($topup->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'succeeded') {
                return false;
            }

            $attributes = $payment['attributes'] ?? [];
            $gatewayAmountCentavos = $attributes['amount'] ?? null;

            if ($gatewayAmountCentavos !== null) {
                $gatewayAmount = round(((int) $gatewayAmountCentavos) / 100, 2);
                if (abs($gatewayAmount - (float) $locked->amount) > 0.01) {
                    Log::warning('paymongo.settlement.amount_mismatch', [
                        'topup_id' => $locked->id,
                        'expected' => $locked->amount,
                        'received' => $gatewayAmount,
                    ]);
                    $this->wallet->markTopupFailed($locked, 'amount_mismatch');

                    return false;
                }
            }

            $user = $locked->user ?? User::find($locked->user_id);
            if (! $user) {
                Log::warning('paymongo.settlement.user_missing', ['topup_id' => $locked->id]);

                return false;
            }

            $paymentId = $payment['id'] ?? ($attributes['id'] ?? $locked->gateway_payment_id);

            $tx = $this->wallet->creditTopup($user, [
                'channel'            => $locked->channel,
                'amount'             => $locked->amount,
                'idempotency_key'    => $locked->idempotency_key,
                'gateway'            => 'paymongo',
                'gateway_intent_id'  => $locked->gateway_intent_id,
                'gateway_payment_id' => $paymentId,
                'source_type'        => Topup::class,
                'source_id'          => $locked->id,
                'metadata'           => [
                    'settlement'           => 'paymongo',
                    'gateway'              => 'paymongo',
                    'paymongo_payment_id'  => $paymentId,
                    'gateway_payment_id'   => $paymentId,
                    'gateway_checkout_id'  => $locked->gateway_intent_id,
                    'channel'              => $locked->channel,
                    'topup_reference'      => $locked->reference_no,
                ],
                'description' => 'Top-up via PayMongo ('.$locked->channel.')',
            ]);

            $locked->forceFill([
                'status'                => 'succeeded',
                'gateway_payment_id'    => $paymentId,
                'wallet_transaction_id' => $tx->id,
                'paid_at'               => now(),
            ])->save();

            $user->notify(new WalletTopupSuccess($locked->fresh(), $tx));

            return true;
        });
    }
}
