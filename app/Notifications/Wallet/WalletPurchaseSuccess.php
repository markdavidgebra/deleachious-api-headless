<?php

namespace App\Notifications\Wallet;

use App\Models\Purchase;
use App\Models\WalletTransaction;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WalletPurchaseSuccess extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Purchase $purchase,
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
            'title' => 'Wallet purchase',
            'body'  => 'You spent ₱'.number_format((float) $this->purchase->amount, 2).' from your wallet.',
            'type'  => 'wallet_purchase',
            'data'  => [
                'purchase_reference'    => $this->purchase->reference_no,
                'transaction_reference' => $this->transaction->reference_no,
                'amount'                => (float) $this->purchase->amount,
                'balance_after'         => (float) $this->transaction->balance_after,
                'branch_id'             => $this->purchase->branch_id,
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
            'Wallet purchase',
            'You spent ₱'.number_format((float) $this->purchase->amount, 2).' from your wallet.',
            [
                'kind'      => 'wallet_purchase',
                'reference' => $this->purchase->reference_no,
                'amount'    => (string) $this->purchase->amount,
                'balance'   => (string) $this->transaction->balance_after,
            ],
        );
    }
}
