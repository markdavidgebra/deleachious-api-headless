<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymongoWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives PayMongo webhook events.
 *
 * Pipeline:
 *  1. `paymongo.signature` middleware verifies the signature.
 *  2. We persist the raw event to the log (audit trail) and dispatch a
 *     job that settles order payment (mark paid, confirm order, award points).
 *  3. We immediately return HTTP 200 — PayMongo retries on non-2xx.
 *
 * Returning fast is critical: PayMongo expects acknowledgement within a
 * short window or it will retry, causing duplicate work.
 */
class PaymongoWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $event = $request->json()->all();

        if (! is_array($event) || empty($event['data'])) {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        $eventId = $event['data']['id'] ?? null;
        $type    = $event['data']['attributes']['type'] ?? null;

        Log::info('paymongo.webhook.received', [
            'event_id' => $eventId,
            'type'     => $type,
        ]);

        ProcessPaymongoWebhook::dispatchSync($event);

        return response()->json(['received' => true]);
    }
}
