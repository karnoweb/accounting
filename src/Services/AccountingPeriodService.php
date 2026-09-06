<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Karnoweb\Accounting\Enums\AccountingPeriodStatus;
use Karnoweb\Accounting\Events\AccountingPeriodClosed;
use Karnoweb\Accounting\Events\AccountingPeriodOpened;
use Karnoweb\Accounting\Events\PostingRejectedForClosedPeriod;
use Karnoweb\Accounting\Exceptions\AccountingPeriodOverlapException;
use Karnoweb\Accounting\Exceptions\AccountingPeriodStateException;
use Karnoweb\Accounting\Exceptions\ClosedAccountingPeriodException;
use Karnoweb\Accounting\Exceptions\InvalidAccountingPeriodException;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\FiscalYear;
use Throwable;

/**
 * Authoritative AccountingPeriod lifecycle and resolution.
 *
 * Posting gates go through PostingService → assertAllowsPosting().
 * Overlap is enforced in-domain (portable across SQLite/MySQL/PostgreSQL);
 * exact duplicate ranges are also unique at the database.
 *
 * Periods are fiscal-year scoped, not branch-scoped. Branch isolation remains
 * on documents/accounts.
 */
class AccountingPeriodService
{
    /** @var list<string> */
    private const LIFECYCLE_FIELDS = [
        'status',
        'opened_at',
        'closed_at',
    ];

    /**
     * Create a draft period. Opening is a separate operation.
     *
     * @param  array{fiscal_year_id: int, name: string, start_date: string|\DateTimeInterface, end_date: string|\DateTimeInterface}  $data
     */
    public function create(array $data): AccountingPeriod
    {
        $this->assertLifecycleFieldsNotSet($data);

        $fiscalYear = $this->resolveFiscalYear($data['fiscal_year_id'] ?? null);
        $name = $this->normalizeName($data['name'] ?? null);
        $start = $this->normalizeDate($data['start_date'] ?? null, 'start_date');
        $end = $this->normalizeDate($data['end_date'] ?? null, 'end_date');
        $this->assertDateOrder($start, $end);
        $this->assertWithinFiscalYear($fiscalYear, $start, $end);

        try {
            return DB::transaction(function () use ($fiscalYear, $name, $start, $end) {
                $this->lockFiscalYearPeriods($fiscalYear);
                $this->assertNoOverlap($fiscalYear, $start, $end);

                return AccountingPeriod::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'name' => $name,
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => AccountingPeriodStatus::DRAFT,
                    'opened_at' => null,
                    'closed_at' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new AccountingPeriodOverlapException(previous: $e);
        } catch (QueryException $e) {
            if ($this->isExactRangeConflict($e)) {
                throw new AccountingPeriodOverlapException(previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Update configuration fields. Status transitions must use open()/close().
     *
     * @param  array{name?: string, start_date?: string|\DateTimeInterface, end_date?: string|\DateTimeInterface}  $data
     */
    public function update(AccountingPeriod|int $period, array $data): AccountingPeriod
    {
        $this->assertLifecycleFieldsNotSet($data);

        return DB::transaction(function () use ($period, $data) {
            $period = $this->lockPeriod($period);

            if ($period->isClosed()) {
                throw new AccountingPeriodStateException(
                    $period,
                    __('accounting::accounting.messages.accounting_period_not_editable')
                );
            }

            if ($period->isOpen() && (array_key_exists('start_date', $data) || array_key_exists('end_date', $data))) {
                throw new AccountingPeriodStateException(
                    $period,
                    __('accounting::accounting.messages.accounting_period_dates_locked')
                );
            }

            $changes = [];

            if (array_key_exists('name', $data)) {
                $changes['name'] = $this->normalizeName($data['name']);
            }

            $startChanging = array_key_exists('start_date', $data);
            $endChanging = array_key_exists('end_date', $data);

            if ($startChanging || $endChanging) {
                $fiscalYear = $this->lockFiscalYear($period->fiscal_year_id);
                $this->lockFiscalYearPeriods($fiscalYear);

                $start = $startChanging
                    ? $this->normalizeDate($data['start_date'], 'start_date')
                    : Carbon::parse($period->start_date)->toDateString();
                $end = $endChanging
                    ? $this->normalizeDate($data['end_date'], 'end_date')
                    : Carbon::parse($period->end_date)->toDateString();

                $this->assertDateOrder($start, $end);
                $this->assertWithinFiscalYear($fiscalYear, $start, $end);
                $this->assertNoOverlap($fiscalYear, $start, $end, $period->id);

                $changes['start_date'] = $start;
                $changes['end_date'] = $end;
            }

            if ($changes !== []) {
                $period->update($changes);
            }

            return $period->fresh();
        });
    }

    /**
     * Open a draft period for posting.
     */
    public function open(AccountingPeriod|int $period): AccountingPeriod
    {
        return DB::transaction(function () use ($period) {
            $period = $this->lockPeriod($period);
            $fiscalYear = $this->lockFiscalYear($period->fiscal_year_id);

            if ($period->isClosed()) {
                throw new AccountingPeriodStateException(
                    $period,
                    __('accounting::accounting.messages.accounting_period_cannot_reopen')
                );
            }

            if ($period->isOpen()) {
                return $period;
            }

            if ($fiscalYear->isClosed()) {
                throw new AccountingPeriodStateException(
                    $period,
                    __('accounting::accounting.messages.accounting_period_fiscal_year_closed')
                );
            }

            $period->update([
                'status' => AccountingPeriodStatus::OPEN,
                'opened_at' => $period->opened_at ?? now(),
                'closed_at' => null,
            ]);

            $period = $period->fresh();
            Event::dispatch(new AccountingPeriodOpened($period));

            return $period;
        });
    }

    /**
     * Preflight for close. Does not mutate state or journals.
     */
    public function validateCanClose(AccountingPeriod|int $period): void
    {
        $period = $this->resolvePeriod($period);

        if ($period->isClosed()) {
            throw new AccountingPeriodStateException(
                $period,
                __('accounting::accounting.messages.accounting_period_already_closed')
            );
        }

        if ( ! $period->isOpen()) {
            throw new AccountingPeriodStateException(
                $period,
                __('accounting::accounting.messages.accounting_period_cannot_close')
            );
        }
    }

    /**
     * Close an open period. Lifecycle only — does not mutate journal lines.
     */
    public function close(AccountingPeriod|int $period): AccountingPeriod
    {
        return DB::transaction(function () use ($period) {
            $period = $this->lockPeriod($period);
            $this->validateCanClose($period);

            $period->update([
                'status' => AccountingPeriodStatus::CLOSED,
                'closed_at' => now(),
            ]);

            $period = $period->fresh();
            Event::dispatch(new AccountingPeriodClosed($period));

            return $period;
        });
    }

    /**
     * Close every OPEN period for a fiscal year (used when the year itself closes).
     *
     * @return list<AccountingPeriod>
     */
    public function closeOpenPeriodsForFiscalYear(FiscalYear|int $fiscalYear): array
    {
        return DB::transaction(function () use ($fiscalYear) {
            $fiscalYear = $this->lockFiscalYear($fiscalYear);
            $this->lockFiscalYearPeriods($fiscalYear);

            $closed = [];
            $open = AccountingPeriod::query()
                ->forFiscalYear($fiscalYear)
                ->open()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($open as $period) {
                $period->update([
                    'status' => AccountingPeriodStatus::CLOSED,
                    'closed_at' => now(),
                ]);
                $fresh = $period->fresh();
                Event::dispatch(new AccountingPeriodClosed($fresh));
                $closed[] = $fresh;
            }

            return $closed;
        });
    }

    /**
     * Canonical period resolution for a posting date within a fiscal year.
     *
     * Ambiguous overlaps are rejected. Missing coverage returns null.
     * Callers that need a hard failure use resolveOrFail() / assertAllowsPosting().
     */
    public function resolve(FiscalYear|int $fiscalYear, string|\DateTimeInterface $date): ?AccountingPeriod
    {
        $fiscalYearId = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;
        $normalized = $this->normalizeDate($date, 'date');

        $matches = AccountingPeriod::query()
            ->forFiscalYear($fiscalYearId)
            ->containingDate($normalized)
            ->orderBy('id')
            ->get();

        if ($matches->count() > 1) {
            throw new AccountingPeriodOverlapException(
                __('accounting::accounting.messages.accounting_period_ambiguous')
            );
        }

        return $matches->first();
    }

    /**
     * Resolve with lockForUpdate for concurrent posting/close safety.
     * Must be called inside an open database transaction.
     */
    public function resolveForUpdate(FiscalYear|int $fiscalYear, string|\DateTimeInterface $date): ?AccountingPeriod
    {
        $fiscalYearId = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;
        $normalized = $this->normalizeDate($date, 'date');

        $matches = AccountingPeriod::query()
            ->forFiscalYear($fiscalYearId)
            ->containingDate($normalized)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($matches->count() > 1) {
            throw new AccountingPeriodOverlapException(
                __('accounting::accounting.messages.accounting_period_ambiguous')
            );
        }

        return $matches->first();
    }

    public function resolveOrFail(FiscalYear|int $fiscalYear, string|\DateTimeInterface $date): AccountingPeriod
    {
        $period = $this->resolve($fiscalYear, $date);

        if ($period === null) {
            throw new AccountingPeriodStateException(
                null,
                __('accounting::accounting.messages.no_accounting_period_for_date')
            );
        }

        return $period;
    }

    /**
     * Domain posting gate for period state. Locks the matching period row.
     * Prefer calling inside DocumentService's transaction.
     *
     * @throws ClosedAccountingPeriodException
     * @throws AccountingPeriodStateException
     */
    public function assertAllowsPosting(
        FiscalYear|int $fiscalYear,
        string|\DateTimeInterface $date,
        bool $lock = true
    ): AccountingPeriod {
        $normalized = $this->normalizeDate($date, 'date');
        $period = $lock
            ? $this->resolveForUpdate($fiscalYear, $normalized)
            : $this->resolve($fiscalYear, $normalized);

        if ($period === null) {
            throw new AccountingPeriodStateException(
                null,
                __('accounting::accounting.messages.no_accounting_period_for_date')
            );
        }

        if ($period->isClosed()) {
            Event::dispatch(new PostingRejectedForClosedPeriod($period, $normalized));

            throw new ClosedAccountingPeriodException($period);
        }

        if ( ! $period->isOpen()) {
            throw new AccountingPeriodStateException(
                $period,
                __('accounting::accounting.messages.accounting_period_not_open')
            );
        }

        return $period;
    }

    /**
     * Whether posting into the period covering $date is allowed (no throw).
     */
    public function allowsPosting(FiscalYear|int $fiscalYear, string|\DateTimeInterface $date): bool
    {
        try {
            $this->assertAllowsPosting($fiscalYear, $date, lock: false);

            return true;
        } catch (ClosedAccountingPeriodException|AccountingPeriodStateException|AccountingPeriodOverlapException|InvalidAccountingPeriodException) {
            return false;
        }
    }

    /**
     * Ensure an OPEN period spans the whole fiscal year when none exist yet.
     * Used by FiscalYearService::activate() so hosts keep a working posting path
     * until they carve monthly/quarterly periods.
     */
    public function ensureFullYearOpen(FiscalYear|int $fiscalYear): AccountingPeriod
    {
        return DB::transaction(function () use ($fiscalYear) {
            $fiscalYear = $this->lockFiscalYear($fiscalYear);
            $this->lockFiscalYearPeriods($fiscalYear);

            $existing = AccountingPeriod::query()
                ->forFiscalYear($fiscalYear)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($existing->isNotEmpty()) {
                $covering = $existing->first(
                    fn (AccountingPeriod $period) => Carbon::parse($period->start_date)->toDateString()
                        === Carbon::parse($fiscalYear->start_date)->toDateString()
                        && Carbon::parse($period->end_date)->toDateString()
                        === Carbon::parse($fiscalYear->end_date)->toDateString()
                );

                if ($covering) {
                    if ($covering->isDraft()) {
                        return $this->open($covering);
                    }

                    return $covering;
                }

                return $existing->first();
            }

            $period = AccountingPeriod::create([
                'fiscal_year_id' => $fiscalYear->id,
                'name' => $fiscalYear->title,
                'start_date' => Carbon::parse($fiscalYear->start_date)->toDateString(),
                'end_date' => Carbon::parse($fiscalYear->end_date)->toDateString(),
                'status' => AccountingPeriodStatus::OPEN,
                'opened_at' => now(),
                'closed_at' => null,
            ]);

            Event::dispatch(new AccountingPeriodOpened($period));

            return $period;
        });
    }

    public function hasPeriods(FiscalYear|int $fiscalYear): bool
    {
        $id = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;

        return AccountingPeriod::query()->forFiscalYear($id)->exists();
    }

    public function assertNoOverlap(
        FiscalYear|int $fiscalYear,
        string $startDate,
        string $endDate,
        ?int $exceptId = null
    ): void {
        $fiscalYearId = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;

        $query = AccountingPeriod::query()
            ->forFiscalYear($fiscalYearId)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);

        if ($exceptId) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw new AccountingPeriodOverlapException;
        }
    }

    private function assertWithinFiscalYear(FiscalYear $fiscalYear, string $start, string $end): void
    {
        $fyStart = Carbon::parse($fiscalYear->start_date)->toDateString();
        $fyEnd = Carbon::parse($fiscalYear->end_date)->toDateString();

        if ($start < $fyStart || $end > $fyEnd) {
            throw new InvalidAccountingPeriodException(
                __('accounting::accounting.messages.accounting_period_outside_fiscal_year')
            );
        }
    }

    private function assertDateOrder(string $start, string $end): void
    {
        if ($start > $end) {
            throw new InvalidAccountingPeriodException(
                __('accounting::accounting.messages.accounting_period_invalid_dates')
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertLifecycleFieldsNotSet(array $data): void
    {
        foreach (self::LIFECYCLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                throw new AccountingPeriodStateException(
                    message: __('accounting::accounting.messages.accounting_period_lifecycle_fields_locked')
                );
            }
        }
    }

    private function normalizeName(mixed $name): string
    {
        if ( ! is_string($name) || trim($name) === '') {
            throw new InvalidAccountingPeriodException(
                __('accounting::accounting.messages.accounting_period_name_required')
            );
        }

        return trim($name);
    }

    private function normalizeDate(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new InvalidAccountingPeriodException(
                __('accounting::accounting.messages.accounting_period_date_required', [
                    'field' => $field,
                ])
            );
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable $e) {
            throw new InvalidAccountingPeriodException(
                __('accounting::accounting.messages.accounting_period_date_required', [
                    'field' => $field,
                ]),
                previous: $e
            );
        }
    }

    private function resolveFiscalYear(mixed $fiscalYear): FiscalYear
    {
        if ($fiscalYear instanceof FiscalYear) {
            return $fiscalYear;
        }

        if (is_int($fiscalYear) || (is_string($fiscalYear) && ctype_digit($fiscalYear))) {
            return FiscalYear::query()->findOrFail((int) $fiscalYear);
        }

        throw new InvalidAccountingPeriodException(
            __('accounting::accounting.messages.accounting_period_fiscal_year_required')
        );
    }

    private function resolvePeriod(AccountingPeriod|int $period): AccountingPeriod
    {
        return $period instanceof AccountingPeriod
            ? $period
            : AccountingPeriod::query()->findOrFail($period);
    }

    private function lockPeriod(AccountingPeriod|int $period): AccountingPeriod
    {
        $id = $period instanceof AccountingPeriod ? $period->id : $period;

        return AccountingPeriod::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockFiscalYear(FiscalYear|int $fiscalYear): FiscalYear
    {
        $id = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;

        return FiscalYear::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockFiscalYearPeriods(FiscalYear $fiscalYear): void
    {
        AccountingPeriod::query()
            ->forFiscalYear($fiscalYear)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function isExactRangeConflict(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'acc_periods_fy_range_unique')
            || (bool) preg_match(
                '/unique constraint failed:\s*[`"\']?[\w.]*fiscal_year_id[`"\']?\s*,\s*[`"\']?[\w.]*start_date[`"\']?\s*,\s*[`"\']?[\w.]*end_date/i',
                $message
            );
    }
}
