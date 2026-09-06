<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Canonical posting authorization for DocumentService and ERP adapters.
 *
 * Resolution order:
 *   1. Fiscal year (explicit or findByDate)
 *   2. Accounting period for that year + date
 *   3. FY status / date range, then period OPEN status
 *
 * FiscalYearService remains the FY lifecycle authority.
 * AccountingPeriodService remains the period lifecycle authority.
 *
 * type and branch_id are part of the stable call shape for future type/branch
 * policy; they do not change the decision today. Opening and closing extra
 * rules stay on those services.
 */
class PostingService
{
    public function __construct(
        private FiscalYearService $fiscalYearService,
        private AccountingPeriodService $periodService
    ) {}

    /**
     * Allow posting, or throw a deterministic accounting exception.
     *
     * When $fiscalYear is omitted, the unique year containing $date is used
     * (FiscalYear::findByDate). Overlaps throw FiscalYearOverlapException.
     * No year is never resolved to "current" — that would hide a missing match.
     *
     * Returns the OPEN AccountingPeriod that accepted the posting date so
     * callers can persist accounting_period_id.
     */
    public function assertAllowed(
        string|\DateTimeInterface $date,
        FiscalYear|int|null $fiscalYear = null,
        ?string $type = null,
        ?int $branchId = null,
        bool $lockPeriod = true,
    ): AccountingPeriod {
        // ponytail: type/branch reserved for future type locks; FY+period+date is the gate.
        unset($type, $branchId);

        $normalized = $this->normalizeDate($date);
        $resolved = $this->resolveFiscalYear($fiscalYear, $normalized);
        $this->fiscalYearService->assertAcceptsPosting($resolved, $normalized);

        return $this->periodService->assertAllowsPosting($resolved, $normalized, $lockPeriod);
    }

    /**
     * Whether posting is allowed (no throw). Does not lock rows.
     */
    public function isAllowed(
        string|\DateTimeInterface $date,
        FiscalYear|int|null $fiscalYear = null,
        ?string $type = null,
        ?int $branchId = null,
    ): bool {
        try {
            $this->assertAllowed($date, $fiscalYear, $type, $branchId, lockPeriod: false);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Canonical period resolution for a date (after FY is known).
     */
    public function resolvePeriod(
        FiscalYear|int $fiscalYear,
        string|\DateTimeInterface $date
    ): ?AccountingPeriod {
        return $this->periodService->resolve($fiscalYear, $date);
    }

    private function normalizeDate(string|\DateTimeInterface $date): string
    {
        if (is_string($date) && trim($date) === '') {
            throw new InvalidArgumentException(__('accounting::accounting.validation.date_required'));
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (InvalidFormatException $e) {
            throw new InvalidArgumentException(__('accounting::accounting.validation.date_required'), 0, $e);
        }
    }

    private function resolveFiscalYear(FiscalYear|int|null $fiscalYear, string $date): FiscalYear
    {
        if ($fiscalYear instanceof FiscalYear) {
            return $fiscalYear;
        }

        if (is_int($fiscalYear)) {
            return FiscalYear::query()->findOrFail($fiscalYear);
        }

        $matched = FiscalYear::findByDate($date);
        if ($matched === null) {
            throw new FiscalYearStateException(
                null,
                __('accounting::accounting.messages.no_fiscal_year_for_date')
            );
        }

        return $matched;
    }
}
