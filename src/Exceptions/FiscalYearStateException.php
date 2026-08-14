<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Karnoweb\Accounting\Models\FiscalYear;
use Throwable;

class FiscalYearStateException extends Exception
{
    public function __construct(
        public readonly ?FiscalYear $fiscalYear = null,
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.fiscal_year_invalid_state'),
            $code,
            $previous
        );
    }
}
