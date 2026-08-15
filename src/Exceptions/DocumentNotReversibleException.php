<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Exceptions;

use Exception;
use Karnoweb\Accounting\Models\Document;
use Throwable;

class DocumentNotReversibleException extends Exception
{
    public function __construct(
        public readonly ?Document $document = null,
        string $message = '',
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: __('accounting::accounting.messages.document_not_reversible'),
            $code,
            $previous
        );
    }
}
