<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Wallet-specific middleware aliases.
        $middleware->alias([
            'wallet.idempotency'       => \App\Http\Middleware\WalletIdempotency::class,
            'wallet.paymongo.signature' => \App\Http\Middleware\VerifyPaymongoSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Map domain-specific wallet errors to clean JSON responses.
        $exceptions->render(function (\App\Exceptions\WalletException $e) {
            return $e->toResponse();
        });
    })
    ->booted(function (): void {
        // ── Rate limiters for wallet endpoints ─────────────────────────
        // Aggressive throttles on money-moving routes prevent brute-force
        // probing of payment / balance APIs and stop runaway client retries.
        RateLimiter::for('wallet-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('wallet-write', function (Request $request) {
            return Limit::perMinute(20)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('wallet-topup', function (Request $request) {
            return Limit::perMinute(6)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('wallet-pay', function (Request $request) {
            return Limit::perMinute(12)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('paymongo-webhook', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    })
    ->create();
