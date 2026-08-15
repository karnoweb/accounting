<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\FiscalYearOverlapException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Exceptions\InvalidFiscalYearException;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use RuntimeException;
use Throwable;

/**
 * Authoritative fiscal-year lifecycle: create (draft), update, activate, close,
 * completeOpening, revertOpening.
 *
 * completeOpening()/revertOpening() write only the opening_done flag.
 * Opening journals, carry-forward, and P&L close are not implemented here.
 */
class FiscalYearService
{
    /** @var list<string> */
    private const LIFECYCLE_FIELDS = [
        'status',
        'is_current',
        'opening_done',
        'opened_at',
        'closed_at',
    ];

    /** Get the currently active fiscal year, or null. Closed years are never returned. */
    public function current(): ?FiscalYear
    {
        return FiscalYear::current();
    }

    /**
     * Find the fiscal year that contains the given date (Y-m-d), or null.
     *
     * Ambiguous overlapping ranges are rejected rather than silently preferred.
     */
    public function findByDate(string $date): ?FiscalYear
    {
        return FiscalYear::findByDate($date);
    }

    /**
     * Create a draft fiscal year. Activation is a separate operation.
     *
     * @param  array{title: string, start_date: string|\DateTimeInterface, end_date: string|\DateTimeInterface}  $data
     */
    public function create(array $data): FiscalYear
    {
        $title = $this->normalizeTitle($data['title'] ?? null);
        $start = $this->normalizeDate($data['start_date'] ?? null, 'start_date');
        $end = $this->normalizeDate($data['end_date'] ?? null, 'end_date');
        $this->assertDateOrder($start, $end);
        $this->assertLifecycleFieldsNotSet($data);

        try {
            return DB::transaction(function () use ($title, $start, $end) {
                $this->lockAllFiscalYears();
                $this->assertNoOverlap($start, $end);

                return FiscalYear::create([
                    'title' => $title,
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => FiscalYearStatus::DRAFT,
                    'is_current' => false,
                    'opening_done' => false,
                    'opened_at' => null,
                    'closed_at' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new FiscalYearOverlapException(previous: $e);
        } catch (QueryException $e) {
            if ($this->isExactRangeConflict($e)) {
                throw new FiscalYearOverlapException(previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Update configuration fields. Status transitions must use activate()/close().
     *
     * @param  array{title?: string, start_date?: string|\DateTimeInterface, end_date?: string|\DateTimeInterface}  $data
     */
    public function update(FiscalYear|int $fiscalYear, array $data): FiscalYear
    {
        $this->assertLifecycleFieldsNotSet($data);

        return DB::transaction(function () use ($fiscalYear, $data) {
            $this->lockAllFiscalYears();
            $fiscalYear = $this->lockFiscalYear($fiscalYear);

            if ($fiscalYear->isClosed()) {
                throw new FiscalYearStateException(
                    $fiscalYear,
                    __('accounting::accounting.messages.fiscal_year_not_editable')
                );
            }

            $changes = [];

            if (array_key_exists('title', $data)) {
                $changes['title'] = $this->normalizeTitle($data['title']);
            }

            $datesChanging = array_key_exists('start_date', $data) || array_key_exists('end_date', $data);

            if ($datesChanging) {
                if ($fiscalYear->isActive()) {
                    throw new FiscalYearStateException(
                        $fiscalYear,
                        __('accounting::accounting.messages.fiscal_year_dates_locked')
                    );
                }

                if ($this->hasDocuments($fiscalYear)) {
                    throw new FiscalYearStateException(
                        $fiscalYear,
                        __('accounting::accounting.messages.fiscal_year_dates_locked')
                    );
                }

                $start = array_key_exists('start_date', $data)
                    ? $this->normalizeDate($data['start_date'], 'start_date')
                    : Carbon::parse($fiscalYear->start_date)->toDateString();
                $end = array_key_exists('end_date', $data)
                    ? $this->normalizeDate($data['end_date'], 'end_date')
                    : Carbon::parse($fiscalYear->end_date)->toDateString();

                $this->assertDateOrder($start, $end);
                $this->assertNoOverlap($start, $end, $fiscalYear->id);
                $changes['start_date'] = $start;
                $changes['end_date'] = $end;
            }

            if ($changes === []) {
                return $fiscalYear->fresh();
            }

            $fiscalYear->update($changes);

            return $fiscalYear->fresh();
        });
    }

    /**
     * Open a draft fiscal year. Does not create opening journal entries.
     * `opening_done` remains false.
     */
    public function activate(FiscalYear|int $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($fiscalYear) {
            $this->lockAllFiscalYears();
            $fiscalYear = $this->lockFiscalYear($fiscalYear);

            if ($fiscalYear->isClosed()) {
                throw new FiscalYearStateException(
                    $fiscalYear,
                    __('accounting::accounting.messages.fiscal_year_cannot_reopen')
                );
            }

            $otherActive = FiscalYear::query()
                ->where('status', FiscalYearStatus::ACTIVE)
                ->whereKeyNot($fiscalYear->id)
                ->first();

            if ($otherActive) {
                throw new FiscalYearStateException(
                    $fiscalYear,
                    __('accounting::accounting.messages.fiscal_year_another_active')
                );
            }

            $this->assertNoOverlap(
                Carbon::parse($fiscalYear->start_date)->toDateString(),
                Carbon::parse($fiscalYear->end_date)->toDateString(),
                $fiscalYear->id
            );

            FiscalYear::query()
                ->where('is_current', true)
                ->whereKeyNot($fiscalYear->id)
                ->update(['is_current' => false]);

            $attributes = [
                'status' => FiscalYearStatus::ACTIVE,
                'is_current' => true,
            ];

            if ( ! $fiscalYear->isActive()) {
                $attributes['opening_done'] = false;
            }

            if ($fiscalYear->opened_at === null) {
                $attributes['opened_at'] = now();
            }

            $fiscalYear->update($attributes);

            return $fiscalYear->fresh();
        });
    }

    /**
     * Preflight for close. Does not mutate state or create journal entries.
     *
     * @throws FiscalYearStateException
     */
    public function validateCanClose(FiscalYear|int $fiscalYear): void
    {
        $fiscalYear = $this->resolve($fiscalYear);

        if ($fiscalYear->isClosed()) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.fiscal_year_already_closed')
            );
        }

        if ( ! $fiscalYear->isActive()) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.fiscal_year_cannot_close')
            );
        }

        if ($this->unpostedDocumentCount($fiscalYear) > 0) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.fiscal_year_has_unposted_documents')
            );
        }
    }

    /**
     * Close an active fiscal year. Lifecycle only — no closing entries, next year, or ledger mutation.
     */
    public function close(FiscalYear|int $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($fiscalYear) {
            $this->lockAllFiscalYears();
            $fiscalYear = $this->lockFiscalYear($fiscalYear);

            $this->lockUnpostedDocuments($fiscalYear);
            $this->validateCanClose($fiscalYear);

            $openingDone = (bool) $fiscalYear->opening_done;

            $fiscalYear->update([
                'status' => FiscalYearStatus::CLOSED,
                'is_current' => false,
                'closed_at' => now(),
                'opening_done' => $openingDone,
            ]);

            return $fiscalYear->fresh();
        });
    }

    /**
     * Mark opening as complete. Flag only — does not create or post opening journals.
     */
    public function completeOpening(FiscalYear|int $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($fiscalYear) {
            $this->lockAllFiscalYears();
            $fiscalYear = $this->lockFiscalYear($fiscalYear);

            $this->assertActiveForOpeningFlag($fiscalYear, 'fiscal_year_cannot_complete_opening');

            if ($fiscalYear->opening_done) {
                return $fiscalYear;
            }

            $fiscalYear->update(['opening_done' => true]);

            return $fiscalYear->fresh();
        });
    }

    /**
     * Clear opening_done. Flag only — does not void or delete documents.
     * Refuses while any posted opening document remains for this fiscal year.
     */
    public function revertOpening(FiscalYear|int $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($fiscalYear) {
            $this->lockAllFiscalYears();
            $fiscalYear = $this->lockFiscalYear($fiscalYear);

            $this->assertActiveForOpeningFlag($fiscalYear, 'fiscal_year_cannot_revert_opening');

            if ( ! $fiscalYear->opening_done) {
                return $fiscalYear;
            }

            $this->lockPostedOpeningDocuments($fiscalYear);

            if ($this->hasPostedOpeningDocuments($fiscalYear)) {
                throw new FiscalYearStateException(
                    $fiscalYear,
                    __('accounting::accounting.messages.fiscal_year_has_posted_opening')
                );
            }

            $fiscalYear->update(['opening_done' => false]);

            return $fiscalYear->fresh();
        });
    }

    /**
     * Fiscal-year + date primitive used by PostingService.
     * ERP adapters should call PostingService::assertAllowed(), not this method.
     * Closed years stay readable; they cannot receive documents.
     */
    public function assertAcceptsPosting(FiscalYear $fiscalYear, string $date): void
    {
        if ($fiscalYear->isClosed()) {
            throw new ClosedFiscalYearException($fiscalYear);
        }

        if ( ! $fiscalYear->isActive()) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.fiscal_year_not_active')
            );
        }

        if (config('accounting.validation.check_date_range', true) && ! $fiscalYear->containsDate($date)) {
            throw new RuntimeException(__('accounting::accounting.validation.date_out_of_fiscal_year'));
        }
    }

    public function assertNoOverlap(string $startDate, string $endDate, ?int $exceptId = null): void
    {
        if (config('accounting.fiscal_year.allow_overlap', false)) {
            return;
        }

        $query = FiscalYear::query()
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);

        if ($exceptId) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw new FiscalYearOverlapException;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertLifecycleFieldsNotSet(array $data): void
    {
        foreach (self::LIFECYCLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                throw new FiscalYearStateException(
                    message: __('accounting::accounting.messages.fiscal_year_lifecycle_fields_locked')
                );
            }
        }
    }

    private function normalizeTitle(mixed $title): string
    {
        if ( ! is_string($title) || trim($title) === '') {
            throw new InvalidFiscalYearException(__('accounting::accounting.messages.fiscal_year_title_required'));
        }

        return trim($title);
    }

    private function normalizeDate(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new InvalidFiscalYearException(__('accounting::accounting.messages.fiscal_year_date_required', [
                'field' => $field,
            ]));
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable $e) {
            throw new InvalidFiscalYearException(
                __('accounting::accounting.messages.fiscal_year_date_required', ['field' => $field]),
                previous: $e
            );
        }
    }

    private function assertDateOrder(string $start, string $end): void
    {
        if ($start > $end) {
            throw new InvalidFiscalYearException(__('accounting::accounting.messages.fiscal_year_invalid_dates'));
        }
    }

    private function resolve(FiscalYear|int $fiscalYear): FiscalYear
    {
        return $fiscalYear instanceof FiscalYear
            ? $fiscalYear->fresh() ?? $fiscalYear
            : FiscalYear::query()->findOrFail($fiscalYear);
    }

    private function lockFiscalYear(FiscalYear|int $fiscalYear): FiscalYear
    {
        $id = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;

        return FiscalYear::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockAllFiscalYears(): void
    {
        FiscalYear::query()->orderBy('id')->lockForUpdate()->get();
    }

    private function hasDocuments(FiscalYear $fiscalYear): bool
    {
        return Document::query()->where('fiscal_year_id', $fiscalYear->id)->exists();
    }

    private function unpostedDocumentCount(FiscalYear $fiscalYear): int
    {
        return Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereNotIn('status', [DocumentStatus::POSTED->value, DocumentStatus::VOIDED->value])
            ->count();
    }

    private function lockUnpostedDocuments(FiscalYear $fiscalYear): void
    {
        Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereNotIn('status', [DocumentStatus::POSTED->value, DocumentStatus::VOIDED->value])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function assertActiveForOpeningFlag(FiscalYear $fiscalYear, string $closedMessageKey): void
    {
        if ($fiscalYear->isClosed()) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.'.$closedMessageKey)
            );
        }

        if ( ! $fiscalYear->isActive()) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.fiscal_year_not_active')
            );
        }
    }

    private function lockPostedOpeningDocuments(FiscalYear $fiscalYear): void
    {
        Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->where('type', 'opening')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function hasPostedOpeningDocuments(FiscalYear $fiscalYear): bool
    {
        return Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->where('type', 'opening')
            ->exists();
    }

    private function isExactRangeConflict(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'fiscal_years_start_date_end_date_unique')
            || str_contains($message, 'acc_fiscal_years_start_date_end_date_unique')
            || (bool) preg_match('/unique constraint failed:\s*[`"\']?[\w.]*start_date[`"\']?\s*,\s*[`"\']?[\w.]*end_date/i', $message);
    }
}
