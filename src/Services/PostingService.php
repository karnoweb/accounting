<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Canonical posting authorization for DocumentService and ERP adapters.
 *
 * FiscalYearService remains the FY lifecycle authority. This service resolves
 * the year (explicit or findByDate) and delegates date/status acceptance there.
 *
 * There is no persisted AccountingPeriod. type and branch_id are part of the
 * stable call shape for future period/type policy; they do not change the
 * decision today. Opening and closing extra rules stay on those services.
 */
class PostingService
{
    public function __construct(
        private FiscalYearService $fiscalYearService
    ) {}

    /**
     * Allow posting, or throw a deterministic accounting exception.
     *
     * When $fiscalYear is omitted, the unique year containing $date is used
     * (FiscalYear::findByDate). Overlaps throw FiscalYearOverlapException.
     * No year is never resolved to "current" — that would hide a missing match.
     */
    public function assertAllowed(
        string|\DateTimeInterface $date,
        FiscalYear|int|null $fiscalYear = null,
        ?string $type = null,
        ?int $branchId = null,
    ): void {
        // ponytail: type/branch reserved for future period locks; FY+date is the only gate.
        unset($type, $branchId);

        $normalized = $this->normalizeDate($date);
        $resolved = $this->resolveFiscalYear($fiscalYear, $normalized);
        $this->fiscalYearService->assertAcceptsPosting($resolved, $normalized);
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
