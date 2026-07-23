<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around PayMongo's REST API.
 *
 * IMPORTANT: We never touch raw card numbers, CVV or expiry. Clients open
 * the gateway-hosted Checkout URL returned by `createOrderCheckout`, and
 * PayMongo handles all PCI-sensitive data. We only persist opaque gateway
 * IDs and the public checkout URL.
 */
class PayMongoService
{
    protected string $baseUrl;
    protected ?string $secretKey;
    protected ?string $webhookSecret;

    public function __construct()
    {
        $this->baseUrl       = rtrim(config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/');
        $this->secretKey     = config('services.paymongo.secret_key');
        $this->webhookSecret = config('services.paymongo.webhook_secret');
    }

    /**
     * Create a hosted Checkout Session for an order payment.
     *
     * @return array{ id: string, checkout_url: string }
     */
    public function createOrderCheckout(
        Transaction $transaction,
        string $channel,
        string $successUrl,
        string $cancelUrl,
        ?User $user = null,
    ): array {
        $this->assertConfigured();

        $order = $transaction->relationLoaded('order')
            ? $transaction->order
            : $transaction->order()->first();

        $amountCentavos = (int) round(((float) $transaction->amount) * 100);
        $orderNumber    = $order?->order_number ?? $transaction->reference_number;

        $attributes = [
            'send_email_receipt'   => false,
            'show_description'     => true,
            'show_line_items'      => true,
            'description'          => 'Daleachious Order '.$orderNumber,
            'reference_number'     => $transaction->reference_number,
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'payment_method_types' => $this->channelToPaymentMethods($channel),
            'line_items'           => [[
                'name'     => 'Order '.$orderNumber,
                'amount'   => $amountCentavos,
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'metadata' => [
                'order_id'         => (string) $transaction->order_id,
                'transaction_id'   => (string) $transaction->id,
                'user_id'          => (string) ($transaction->user_id ?? ''),
                'reference_number' => $transaction->reference_number,
                'purpose'          => 'order_payment',
            ],
        ];

        $billing = $this->billingFromUser($user ?? $transaction->user);
        if ($billing) {
            $attributes['billing'] = $billing;
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl.'/checkout_sessions', [
                'data' => [
                    'attributes' => $attributes,
                ],
            ]);

        if ($response->failed()) {
            Log::error('paymongo.checkout.failed', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            throw new PaymentException(
                'Failed to create payment session.',
                'paymongo_checkout_failed',
                502,
                ['gateway' => $response->json()],
            );
        }

        $payload = $response->json('data');

        return [
            'id'           => $payload['id'] ?? '',
            'checkout_url' => $payload['attributes']['checkout_url'] ?? '',
        ];
    }

    /**
     * @return array{name?: string, email?: string, phone?: string}|null
     */
    protected function billingFromUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $billing = [];

        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            $billing['name'] = $name;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $billing['email'] = $email;
        }

        $phone = trim((string) ($user->phone ?? ''));
        if ($phone !== '') {
            $billing['phone'] = $phone;
        }

        return $billing === [] ? null : $billing;
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $checkoutSessionId): array
    {
        $this->assertConfigured();

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->get($this->baseUrl.'/checkout_sessions/'.$checkoutSessionId);

        if ($response->failed()) {
            Log::error('paymongo.checkout.retrieve_failed', [
                'checkout_session_id' => $checkoutSessionId,
                'status'              => $response->status(),
                'body'                => $response->json(),
            ]);

            throw new PaymentException(
                'Failed to verify payment status.',
                'paymongo_retrieve_failed',
                502,
                ['gateway' => $response->json()],
            );
        }

        return $response->json('data') ?? [];
    }

    /**
     * @param  array<string, mixed>  $checkoutSession
     * @return array<string, mixed>|null
     */
    public function findPaidPayment(array $checkoutSession): ?array
    {
        $payments = $checkoutSession['attributes']['payments'] ?? [];

        foreach ($payments as $payment) {
            if (($payment['attributes']['status'] ?? null) === 'paid') {
                return $payment;
            }
        }

        return null;
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if (! $this->webhookSecret) {
            Log::error('paymongo.webhook.missing_secret');

            return false;
        }

        if (! $signatureHeader) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            if (! str_contains($segment, '=')) {
                continue;
            }
            [$k, $v] = explode('=', trim($segment), 2);
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? null;
        $sig       = $parts['li'] ?? ($parts['te'] ?? null);

        if (! $timestamp || ! $sig) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->webhookSecret);

        return hash_equals($expected, $sig);
    }

    public function refundPayment(string $paymentId, int $amountCentavos, string $reason = 'requested_by_customer'): array
    {
        $this->assertConfigured();

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl.'/refunds', [
                'data' => [
                    'attributes' => [
                        'amount'     => $amountCentavos,
                        'payment_id' => $paymentId,
                        'reason'     => $reason,
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('paymongo.refund.failed', [
                'status'     => $response->status(),
                'body'       => $response->json(),
                'payment_id' => $paymentId,
            ]);

            throw new PaymentException(
                'Failed to issue refund via gateway.',
                'paymongo_refund_failed',
                502,
            );
        }

        return $response->json('data') ?? [];
    }

    protected function channelToPaymentMethods(string $channel): array
    {
        return match ($channel) {
            'card'  => ['card'],
            'gcash' => ['gcash'],
            'maya'  => ['paymaya'],
            'qrph'  => ['qrph'],
            default => ['card', 'gcash', 'paymaya', 'qrph'],
        };
    }

    protected function assertConfigured(): void
    {
        if (! $this->secretKey) {
            throw new PaymentException(
                'Payment gateway is not configured.',
                'paymongo_not_configured',
                503,
            );
        }
    }
}
