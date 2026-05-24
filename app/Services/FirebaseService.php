<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));

        $this->messaging = $factory->createMessaging();
    }

    // Send to a single device token
    public function sendToDevice(
        string $token,
        string $title,
        string $body,
        array $data = []
    ): bool {
        try {
            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(
                    Notification::create($title, $body)
                )
                ->withData($data);

            $this->messaging->send($message);

            return true;
        } catch (\Exception $e) {
            \Log::error('Firebase send error: ' . $e->getMessage());
            return false;
        }
    }

    // Send to multiple device tokens
    public function sendToMultiple(
        array $tokens,
        string $title,
        string $body,
        array $data = []
    ): int {
        if (empty($tokens)) {
            return 0;
        }

        $successCount = 0;

        // Firebase allows max 500 tokens per batch
        $chunks = array_chunk($tokens, 500);

        foreach ($chunks as $chunk) {
            try {
                $message = CloudMessage::new()
                    ->withNotification(
                        Notification::create($title, $body)
                    )
                    ->withData($data);

                $report = $this->messaging->sendMulticast($message, $chunk);
                $successCount += $report->successes()->count();
            } catch (\Exception $e) {
                \Log::error('Firebase multicast error: ' . $e->getMessage());
            }
        }

        return $successCount;
    }
}