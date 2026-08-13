<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\FiscalYearOverlapException;
use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Service for resolving and creating fiscal years.
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

    /**
     * @param array{title: string, start_date: string|\DateTimeInterface, end_date: string|\DateTimeInterface, status?: string|FiscalYearStatus, is_current?: bool} $data
     */
    public function create(array $data): FiscalYear
    {
        return DB::transaction(function () use ($data) {
            $start = Carbon::parse($data['start_date'])->toDateString();
            $end = Carbon::parse($data['end_date'])->toDateString();

            $this->assertNoOverlap($start, $end);

            if ( ! empty($data['is_current'])) {
                FiscalYear::query()->where('is_current', true)->update(['is_current' => false]);
            }

            return FiscalYear::create([
                'title' => $data['title'],
                'start_date' => $start,
                'end_date' => $end,
                'status' => $data['status'] ?? FiscalYearStatus::DRAFT,
                'is_current' => (bool) ($data['is_current'] ?? false),
                'opening_done' => false,
            ]);
        });
    }

    public function assertNoOverlap(string $startDate, string $endDate, ?int $exceptId = null): void
    {
        if (config('accounting.fiscal_year.allow_overlap', false)) {
            return;
        }

        $query = FiscalYear::query()
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw new FiscalYearOverlapException;
        }
    }
}
