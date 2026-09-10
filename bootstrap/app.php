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
            'admin.super'        => \App\Http\Middleware\EnsureSuperAdmin::class,
            'admin.developer'    => \App\Http\Middleware\EnsureDeveloper::class,
            'admin.can'          => \App\Http\Middleware\EnsureAdminCan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->booted(function (): void {
        RateLimiter::for('redemptions', function (Request $request) {
            return Limit::perMinute(12)
                ->by(optional($request->user())->id ?: $request->ip());
        });
    })
    ->create();
