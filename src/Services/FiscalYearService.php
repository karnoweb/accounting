<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Karnoweb\Accounting\Models\FiscalYear;

class FiscalYearService
{
    public function current(): ?FiscalYear
    {
        return FiscalYear::current();
    }

    public function findByDate(string $date): ?FiscalYear
    {
        return FiscalYear::findByDate($date);
    }
}
