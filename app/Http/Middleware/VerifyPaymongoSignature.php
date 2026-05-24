<?php

namespace App\Http\Middleware;

use App\Services\PayMongoService;
use Closure;
use Illuminate\Http\Request;

/**
 * Rejects PayMongo webhook calls whose signature header doesn't match.
 * Runs before the controller so unauthorised requests never touch the DB.
 */
class VerifyPaymongoSignature
{
    public function __construct(protected PayMongoService $paymongo) {}

    public function handle(Request $request, Closure $next)
    {
        $signature = $request->header('Paymongo-Signature');
        $rawBody   = $request->getContent();

        if (! $this->paymongo->verifyWebhookSignature($rawBody, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
