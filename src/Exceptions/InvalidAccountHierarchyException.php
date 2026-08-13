<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Throwable;

class InvalidAccountHierarchyException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.invalid_account_hierarchy'),
            $code,
            $previous
        );
    }
}
