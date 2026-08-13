<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Throwable;

class DuplicateIdempotencyKeyException extends Exception
{
    public function __construct(
        public readonly string $idempotencyKey = '',
        string $message = '',
        int $code = 409,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.duplicate_idempotency_key', [
                'key' => $idempotencyKey,
            ]),
            $code,
            $previous
        );
    }
}
