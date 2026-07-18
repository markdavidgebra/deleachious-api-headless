<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\Topup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around PayMongo's REST API.
 *
 * IMPORTANT: We never touch raw card numbers, CVV or expiry. The mobile
 * app opens the gateway-hosted Checkout URL returned by `createCheckout`,
 * and PayMongo handles all PCI-sensitive data. We only persist opaque
 * gateway IDs (intent / source / payment ids) and the public checkout URL.
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
     * Create a hosted Checkout Session for a top-up. The mobile app opens
     * the returned `checkout_url` in a browser/in-app web view; the user
     * pays there; PayMongo notifies us via webhook on success/failure.
     *
     * @return array{ id: string, checkout_url: string }
     */
    public function createCheckout(Topup $topup, string $successUrl, string $cancelUrl): array
    {
        $this->assertConfigured();

        // PayMongo wants amounts in centavos (smallest currency unit).
        $amountCentavos = (int) round(((float) $topup->amount) * 100);

        $attributes = [
            'send_email_receipt'   => false,
            'show_description'     => true,
            'show_line_items'      => true,
            'description'          => 'Daleachious Wallet Top-up',
            'reference_number'     => $topup->reference_no,
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'payment_method_types' => $this->channelToPaymentMethods($topup->channel),
            'line_items'           => [[
                'name'     => 'Wallet Top-up',
                'amount'   => $amountCentavos,
                'currency' => $topup->currency ?: 'PHP',
                'quantity' => 1,
            ]],
            'metadata' => [
                'topup_id'     => (string) $topup->id,
                'wallet_id'    => (string) $topup->wallet_id,
                'user_id'      => (string) $topup->user_id,
                'reference_no' => $topup->reference_no,
            ],
        ];

        // Prefill PayMongo Customer Information from the logged-in user.
        $billing = $this->billingFromTopup($topup);
        if ($billing) {
            $attributes['billing'] = $billing;
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl . '/checkout_sessions', [
                'data' => [
                    'attributes' => $attributes,
                ],
            ]);

        if ($response->failed()) {
            Log::error('paymongo.checkout.failed', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            throw new WalletException(
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
     * Build PayMongo billing fields so Name / Email (and phone when present)
     * are pre-filled on the hosted checkout Customer Information step.
     *
     * @return array{name?: string, email?: string, phone?: string}|null
     */
    protected function billingFromTopup(Topup $topup): ?array
    {
        $user = $topup->relationLoaded('user')
            ? $topup->user
            : $topup->user()->first();

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
     * Retrieve a Checkout Session (includes `payments` when using the secret key).
     *
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $checkoutSessionId): array
    {
        $this->assertConfigured();

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->get($this->baseUrl . '/checkout_sessions/' . $checkoutSessionId);

        if ($response->failed()) {
            Log::error('paymongo.checkout.retrieve_failed', [
                'checkout_session_id' => $checkoutSessionId,
                'status'              => $response->status(),
                'body'                => $response->json(),
            ]);

            throw new WalletException(
                'Failed to verify payment status.',
                'paymongo_retrieve_failed',
                502,
                ['gateway' => $response->json()],
            );
        }

        return $response->json('data') ?? [];
    }

    /**
     * @param  array<string, mixed>  $checkoutSession  PayMongo checkout session `data` object
     * @return array<string, mixed>|null  Paid payment resource, if any
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

    /**
     * Verify a webhook signature using PayMongo's HMAC-SHA256 scheme.
     *
     * The `Paymongo-Signature` header looks like:
     *     t=1234567890,te=<test-sig>,li=<live-sig>
     *
     * Signature payload is `<timestamp>.<rawBody>`.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if (! $this->webhookSecret) {
            // Fail closed: never accept webhooks unless a secret is configured.
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

        // Reject signatures older than 5 minutes to mitigate replay attacks.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);

        return hash_equals($expected, $sig);
    }

    /**
     * Issue a refund on the original payment via PayMongo's refunds API.
     */
    public function refundPayment(string $paymentId, int $amountCentavos, string $reason = 'requested_by_customer'): array
    {
        $this->assertConfigured();

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl . '/refunds', [
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

            throw new WalletException(
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
            default => ['card', 'gcash', 'paymaya'],
        };
    }

    protected function assertConfigured(): void
    {
        if (! $this->secretKey) {
            throw new WalletException(
                'Payment gateway is not configured.',
                'paymongo_not_configured',
                503,
            );
        }
    }
}
