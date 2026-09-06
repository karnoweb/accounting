<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Throwable;

class ClosedAccountingPeriodException extends Exception
{
    public function __construct(
        public readonly ?AccountingPeriod $period = null,
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.accounting_period_closed'),
            $code,
            $previous
        );
    }
}
