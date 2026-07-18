<?php

namespace App\Notifications\Channels;

use App\Models\Notification as NotificationModel;
use Illuminate\Notifications\Notification;

/**
 * Hybrid notification channel used by the wallet subsystem.
 *
 * - Sends a push via Firebase by delegating to the notification's
 *   `toFcm($notifiable)` method (if defined).
 * - Persists a row to the existing custom `notifications` table so the
 *   message also shows up in the mobile app's notification inbox.
 *
 * The project ships with a hand-rolled `notifications` schema (with
 * columns like sent_by, target, body) instead of Laravel's stock
 * Notifiable schema, so we cannot rely on the built-in `database`
 * channel — this class fills both roles in one queued worker step.
 */
class FcmChannel
{
    public function send($notifiable, Notification $notification): void
    {
        // Persist a row in the custom notifications table when the
        // notification declares a database payload via toDatabase().
        if (method_exists($notification, 'toDatabase')) {
            $payload = $notification->toDatabase($notifiable);

            if (is_array($payload)) {
                try {
                    NotificationModel::create([
                        'sent_by'    => null,
                        'user_id'    => $notifiable->id ?? null,
                        'title'      => $payload['title'] ?? 'Wallet update',
                        'body'       => $payload['body']  ?? '',
                        'type'       => $payload['type']  ?? 'general',
                        'data'       => $payload['data']  ?? null,
                        'target'     => 'specific_user',
                        'sent_count' => 0,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('wallet.notification.persist_failed', [
                        'reason' => $e->getMessage(),
                        'class'  => get_class($notification),
                    ]);
                }
            }
        }

        // Push via Firebase. Returning false from toFcm is OK — it just
        // means the user has no FCM token registered.
        if (method_exists($notification, 'toFcm')) {
            try {
                $notification->toFcm($notifiable);
            } catch (\Throwable $e) {
                \Log::warning('wallet.notification.push_failed', [
                    'reason' => $e->getMessage(),
                    'class'  => get_class($notification),
                ]);
            }
        }
    }
}
