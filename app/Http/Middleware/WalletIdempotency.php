<?php

namespace App\Http\Middleware;

use App\Models\WalletIdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency middleware for wallet POSTs.
 *
 * Usage:
 *     Route::middleware(['auth:sanctum', 'wallet.idempotency:topup'])
 *
 * - Reads the `Idempotency-Key` header (max 80 chars, ASCII).
 * - First time we see a given (user, scope, key) tuple we run the
 *   downstream pipeline, persist its response, and return it.
 * - Subsequent requests with the same key return the cached response
 *   verbatim — even if the request body changes we 409 to prevent the
 *   client from accidentally creating two different transactions.
 */
class WalletIdempotency
{
    public function handle(Request $request, Closure $next, string $scope = 'default')
    {
        $key  = $request->header('Idempotency-Key');
        $user = $request->user();

        if (! $user || ! $key) {
            // No user or no key — pass through. The route's auth middleware
            // will reject unauthenticated requests separately. Idempotency is
            // optional; we only enforce it when the client opts in.
            return $next($request);
        }

        if (strlen($key) > 80 || ! preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
            return response()->json([
                'message' => 'Invalid Idempotency-Key header.',
            ], 422);
        }

        $hash = hash('sha256', $request->getContent() ?: '{}');

        $existing = WalletIdempotencyKey::where('user_id', $user->id)
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();

        if ($existing) {
            if ($existing->request_hash !== $hash) {
                // Same key, different payload → conflict.
                return response()->json([
                    'message'    => 'Idempotency-Key reused with a different payload.',
                    'error_code' => 'idempotency_conflict',
                ], 409);
            }

            return response()->json($existing->response, $existing->status_code);
        }

        $response = $next($request);

        // Only cache successful 2xx responses; failed validations etc.
        // should be retryable normally.
        if ($response->isSuccessful()) {
            try {
                DB::transaction(function () use ($user, $scope, $key, $hash, $response) {
                    WalletIdempotencyKey::create([
                        'user_id'      => $user->id,
                        'scope'        => $scope,
                        'key'          => $key,
                        'request_hash' => $hash,
                        'status_code'  => $response->getStatusCode(),
                        'response'     => json_decode($response->getContent(), true),
                        'expires_at'   => now()->addHours(24),
                    ]);
                });
            } catch (\Throwable $e) {
                // Race: another worker won. Fall through with the new response.
            }
        }

        return $response;
    }
}
