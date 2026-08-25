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
        $middleware->alias([
            'paymongo.signature' => \App\Http\Middleware\VerifyPaymongoSignature::class,
            'admin.super'        => \App\Http\Middleware\EnsureSuperAdmin::class,
            'admin.developer'    => \App\Http\Middleware\EnsureDeveloper::class,
            'admin.can'          => \App\Http\Middleware\EnsureAdminCan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\App\Exceptions\PaymentException $e) {
            return $e->toResponse();
        });
    })
    ->booted(function (): void {
        RateLimiter::for('order-checkout', function (Request $request) {
            return Limit::perMinute(12)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('order-confirm', function (Request $request) {
            return Limit::perMinute(30)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('paymongo-webhook', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    })
    ->create();
