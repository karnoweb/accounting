<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Service for resolving fiscal years: current or by date.
 */
class FiscalYearService
{
    /** Get the currently active fiscal year, or null. */
    public function current(): ?FiscalYear
    {
        return FiscalYear::current();
    }

    /** Find the fiscal year that contains the given date (Y-m-d), or null. */
    public function findByDate(string $date): ?FiscalYear
    {
        return FiscalYear::findByDate($date);
    }
}
