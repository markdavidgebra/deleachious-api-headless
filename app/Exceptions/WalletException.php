<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain exception for the wallet subsystem. Carries an HTTP-friendly
 * status code and a stable error code so the mobile client can localise
 * the message and react programmatically (e.g. show a top-up sheet).
 */
class WalletException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'wallet_error',
        public readonly int $statusCode = 422,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function toResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
            'context'    => $this->context,
        ], $this->statusCode);
    }
}
