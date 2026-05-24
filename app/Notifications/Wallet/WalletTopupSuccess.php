<?php

namespace App\Notifications\Wallet;

use App\Models\Topup;
use App\Models\WalletTransaction;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to the wallet owner after a successful top-up.
 * Persists a row in `notifications` and pushes to the user's FCM token.
 */
class WalletTopupSuccess extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Topup $topup,
        public WalletTransaction $transaction,
    ) {
        $this->onQueue('notifications');
    }

    public function via($notifiable): array
    {
        // The custom FcmChannel both pushes via Firebase AND persists the
        // notification row in the custom `notifications` inbox table.
        return ['fcm'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Wallet topped up',
            'body'  => 'Your wallet was topped up with ₱'.number_format((float) $this->topup->amount, 2).'.',
            'type'  => 'wallet_topup',
            'data'  => [
                'topup_reference'       => $this->topup->reference_no,
                'transaction_reference' => $this->transaction->reference_no,
                'amount'                => (float) $this->topup->amount,
                'balance_after'         => (float) $this->transaction->balance_after,
            ],
        ];
    }

    /**
     * Send via FCM. We fetch the firebase service lazily so the queue
     * worker doesn't fail if Firebase is misconfigured at boot.
     */
    public function toFcm($notifiable): bool
    {
        if (empty($notifiable->fcm_token)) {
            return false;
        }

        return app(FirebaseService::class)->sendToDevice(
            $notifiable->fcm_token,
            'Wallet topped up',
            'Your wallet was topped up with ₱'.number_format((float) $this->topup->amount, 2).'.',
            [
                'kind'       => 'wallet_topup',
                'reference'  => $this->topup->reference_no,
                'amount'     => (string) $this->topup->amount,
                'balance'    => (string) $this->transaction->balance_after,
            ],
        );
    }
}
