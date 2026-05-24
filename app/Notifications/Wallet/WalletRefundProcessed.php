<?php

namespace App\Notifications\Wallet;

use App\Models\Refund;
use App\Models\WalletTransaction;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WalletRefundProcessed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Refund $refund,
        public WalletTransaction $transaction,
    ) {
        $this->onQueue('notifications');
    }

    public function via($notifiable): array
    {
        return ['fcm'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Refund processed',
            'body'  => '₱'.number_format((float) $this->refund->amount, 2).' has been credited back to your wallet.',
            'type'  => 'wallet_refund',
            'data'  => [
                'refund_reference'      => $this->refund->reference_no,
                'transaction_reference' => $this->transaction->reference_no,
                'amount'                => (float) $this->refund->amount,
                'balance_after'         => (float) $this->transaction->balance_after,
            ],
        ];
    }

    public function toFcm($notifiable): bool
    {
        if (empty($notifiable->fcm_token)) {
            return false;
        }

        return app(FirebaseService::class)->sendToDevice(
            $notifiable->fcm_token,
            'Refund processed',
            '₱'.number_format((float) $this->refund->amount, 2).' has been credited back to your wallet.',
            [
                'kind'      => 'wallet_refund',
                'reference' => $this->refund->reference_no,
                'amount'    => (string) $this->refund->amount,
                'balance'   => (string) $this->transaction->balance_after,
            ],
        );
    }
}
