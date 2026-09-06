<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Throwable;

class InvalidAccountingPeriodException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.accounting_period_invalid'),
            $code,
            $previous
        );
    }
}
