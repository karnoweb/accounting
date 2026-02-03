<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Karnoweb\Accounting\Models\FiscalYear;
use Throwable;

class ClosedFiscalYearException extends Exception
{
    public function __construct(
        public readonly ?FiscalYear $fiscalYear = null,
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.fiscal_year_closed'),
            $code,
            $previous
        );
    }
}
