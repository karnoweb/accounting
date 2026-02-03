<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Throwable;

class AccountNotFoundException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 404,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.account_not_found'),
            $code,
            $previous
        );
    }
}
