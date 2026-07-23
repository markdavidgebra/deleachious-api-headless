<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain exception for payment / PayMongo flows.
 */
class PaymentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'payment_error',
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
