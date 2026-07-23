<?php

namespace App\Providers;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the FCM notification channel so notifications can declare
        // `'fcm'` in their `via()` array. The channel itself delegates to each
        // notification's toFcm() method.
        $this->app->resolving(ChannelManager::class, function (ChannelManager $manager) {
            $manager->extend('fcm', fn () => $this->app->make(FcmChannel::class));
        });
    }
}
