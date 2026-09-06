<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Karnoweb\Accounting\Models\AccountingPeriod;

class AccountingPeriodClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public AccountingPeriod $period
    ) {}
}
