<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use InvalidArgumentException;
use Throwable;

class InvalidReportFilterException extends InvalidArgumentException
{
    public function __construct(
        string $message = 'Invalid report filter.',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
