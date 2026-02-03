<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Throwable;

class UnbalancedDocumentException extends Exception
{
    public function __construct(
        public readonly float $debitTotal,
        public readonly float $creditTotal,
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.document_not_balanced'),
            $code,
            $previous
        );
    }
}
