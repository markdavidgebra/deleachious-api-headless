<?php

namespace App\Notifications\Wallet;

use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WalletSuspiciousActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $context = [])
    {
        $this->onQueue('notifications');
    }

    public function via($notifiable): array
    {
        return ['fcm'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Unusual wallet activity detected',
            'body'  => 'We noticed unusual top-up attempts on your account. If this wasn\'t you, please contact support.',
            'type'  => 'wallet_fraud',
            'data'  => $this->context,
        ];
    }

    public function toFcm($notifiable): bool
    {
        if (empty($notifiable->fcm_token)) {
            return false;
        }

        return app(FirebaseService::class)->sendToDevice(
            $notifiable->fcm_token,
            'Unusual wallet activity',
            'Several recent top-up attempts failed. If this wasn\'t you, please contact support.',
            ['kind' => 'wallet_fraud'],
        );
    }
}
