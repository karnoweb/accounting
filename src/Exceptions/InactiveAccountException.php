<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Karnoweb\Accounting\Models\Account;
use Throwable;

class InactiveAccountException extends Exception
{
    public function __construct(
        public readonly ?Account $account = null,
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.account_inactive'),
            $code,
            $previous
        );
    }
}
