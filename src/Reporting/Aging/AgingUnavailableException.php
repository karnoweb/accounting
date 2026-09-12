<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting\Aging;

use RuntimeException;

class AgingUnavailableException extends RuntimeException
{
    public function __construct(string $side = 'receivable')
    {
        parent::__construct(
            "AR/AP {$side} aging is not available: the ledger has no counterparty, due_date, or open-item settlement data. Bind a Karnoweb\\Accounting\\Reporting\\Aging\\AgingSourceProvider from the host ERP/Sales/Purchase module."
        );
    }
}
