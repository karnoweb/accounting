<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Carbon\Carbon;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Support\Amount;

/**
 * Read-only period closing readiness. Does not close a period or post journals.
 *
 * `AccountingPeriodService::close()` currently only requires the period to be
 * OPEN. This report is stricter: unposted documents are readiness blockers
 * even though close() will still succeed if they are ignored.
 */
final class PeriodClosingReport
{
    public function __construct(
        private readonly LedgerReportFilters $filters,
        private readonly ReportPagination $pagination,
    ) {}

    public function build(): PeriodClosingResult
    {
        $period = $this->filters->accountingPeriod;
        $fiscalYear = $this->filters->fiscalYear;

        if (! $period) {
            throw new InvalidReportFilterException('Period closing report requires accounting_period_id.');
        }

        $fiscalYear ??= $period->fiscalYear;
        $query = $this->filters->toLedgerQuery();
        $activity = $this->activitySummary($query, $period);
        $temporary = $this->temporarySummary($query);
        [$tempRows, $pagination] = $this->paginateTemporaryRows($temporary['rows']);

        $scopeChecks = $this->scopeChecks($period, $fiscalYear, $query, $activity, $temporary);
        $ready = collect($scopeChecks)->where('severity', 'blocker')->where('passed', false)->isEmpty();

        $branchReadiness = null;
        if ($this->filters->branchScope->includeBreakdown || $this->filters->branchScope->isMulti()) {
            $branchReadiness = $this->perBranchReadiness($period);
            if ($this->filters->branchScope->mode === BranchScope::MODE_ALL
                || $this->filters->branchScope->mode === BranchScope::MODE_SELECTED
            ) {
                foreach ($branchReadiness as $branch) {
                    if (! $branch['ready_to_close']) {
                        $ready = false;
                    }
                }
            }
        }

        $blockers = array_values(array_filter($scopeChecks, fn (ClosingReadinessCheck $check) => ! $check->passed && $check->severity === 'blocker'));
        $warnings = array_values(array_filter($scopeChecks, fn (ClosingReadinessCheck $check) => ! $check->passed && $check->severity === 'warning'));

        if (! $ready && $this->hasUnreadyBranch($branchReadiness) && ! $this->hasCode($blockers, 'BRANCH_NOT_READY')) {
            $blockers[] = new ClosingReadinessCheck(
                'BRANCH_NOT_READY',
                false,
                'blocker',
                collect($branchReadiness ?? [])->where('ready_to_close', false)->count(),
                'One or more branches in the selected scope are not ready to close.',
            );
            $scopeChecks[] = $blockers[array_key_last($blockers)];
        }

        return new PeriodClosingResult(
            meta: ReportMeta::make(
                'period_closing',
                $this->filters->toArray(),
                $this->filters->branchScope->toArray(),
                $this->filters->mode->value,
            ),
            header: [
                'fiscal_year_id' => $fiscalYear?->id,
                'fiscal_year' => $fiscalYear?->title,
                'accounting_period_id' => $period->id,
                'accounting_period' => $period->name,
                'period_start' => Carbon::parse($period->start_date)->toDateString(),
                'period_end' => Carbon::parse($period->end_date)->toDateString(),
                'period_status' => $period->status->value,
                'branch_scope' => $this->filters->branchScope->toArray(),
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
            activity: $activity,
            readiness: [
                'ready_to_close' => $ready,
                'blockers' => array_map(fn (ClosingReadinessCheck $check) => $check->toArray(), $blockers),
                'warnings' => array_map(fn (ClosingReadinessCheck $check) => $check->toArray(), $warnings),
                'checks' => array_map(fn (ClosingReadinessCheck $check) => $check->toArray(), $scopeChecks),
            ],
            temporaryAccounts: [
                'revenue_balance' => $temporary['revenue_balance'],
                'expense_balance' => $temporary['expense_balance'],
                'current_profit_loss' => $temporary['current_profit_loss'],
                'temporary_accounts_remaining' => $temporary['temporary_accounts_remaining'],
            ],
            temporaryAccountRows: $tempRows,
            pagination: $pagination,
            branches: $branchReadiness,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function activitySummary(LedgerQuery $query, AccountingPeriod $period): array
    {
        $financial = $query->periodTotalsExact();
        $documents = (new Document)->getTable();
        $base = $this->documentScopeQuery($query, $documents);

        $postedDates = (clone $base)
            ->where("{$documents}.status", DocumentStatus::POSTED->value)
            ->selectRaw("MIN({$documents}.date) as first_date, MAX({$documents}.date) as last_date")
            ->first();

        return [
            'posted_document_count' => $financial['document_count'],
            'posted_line_count' => $financial['line_count'],
            'total_debit' => $financial['debit'],
            'total_credit' => $financial['credit'],
            'first_posted_document_date' => $postedDates?->first_date ? substr((string) $postedDates->first_date, 0, 10) : null,
            'last_posted_document_date' => $postedDates?->last_date ? substr((string) $postedDates->last_date, 0, 10) : null,
            'draft_document_count' => $this->countDocuments($base, DocumentStatus::DRAFT),
            'pending_document_count' => $this->countDocuments($base, DocumentStatus::PENDING),
            'approved_document_count' => $this->countDocuments($base, DocumentStatus::APPROVED),
            'voided_document_count' => $this->countDocuments($base, DocumentStatus::VOIDED),
            'reversal_document_count' => (clone $base)
                ->where("{$documents}.status", DocumentStatus::POSTED->value)
                ->where("{$documents}.type", 'reversal')
                ->distinct()
                ->count("{$documents}.id"),
            'closing_document_count' => (clone $base)
                ->where("{$documents}.status", DocumentStatus::POSTED->value)
                ->where("{$documents}.type", 'closing')
                ->distinct()
                ->count("{$documents}.id"),
        ];
    }

    private function documentScopeQuery(LedgerQuery $query, string $documents)
    {
        $clone = clone $query;
        $clone->mode(ReportMode::Audit);

        return $clone->listingQuery()->select("{$documents}.*");
    }

    private function countDocuments($base, DocumentStatus $status): int
    {
        $documents = (new Document)->getTable();

        return (int) (clone $base)
            ->where("{$documents}.status", $status->value)
            ->distinct()
            ->count("{$documents}.id");
    }

    /**
     * @return array{revenue_balance: string, expense_balance: string, current_profit_loss: string, temporary_accounts_remaining: int, rows: list<array<string, mixed>>}
     */
    private function temporarySummary(LedgerQuery $query): array
    {
        $period = $query->periodActivityByAccount();
        $accountIds = array_keys($period);
        $accounts = $accountIds === []
            ? collect()
            : Account::query()->whereIn('id', $accountIds)->orderBy('code')->orderBy('id')->get()->keyBy('id');

        $revenue = Amount::zero();
        $expense = Amount::zero();
        $rows = [];

        foreach ($period as $accountId => $totals) {
            $account = $accounts->get($accountId);
            if (! $account || ! $account->type->isTemporary()) {
                continue;
            }

            $debit = Amount::of($totals['debit']);
            $credit = Amount::of($totals['credit']);
            $balance = $debit->subtract($credit);
            if ($account->type === AccountType::INCOME) {
                $revenue = $revenue->add($credit->subtract($debit));
            } else {
                $expense = $expense->add($debit->subtract($credit));
            }

            if (! $balance->isZero()) {
                $rows[] = [
                    'account_id' => (int) $account->id,
                    'account_code' => (string) $account->code,
                    'account_name' => (string) $account->title,
                    'account_type' => $account->type->value,
                    'debit' => $debit->toStorage(),
                    'credit' => $credit->toStorage(),
                    'balance' => $balance->toStorage(),
                ];
            }
        }

        return [
            'revenue_balance' => $revenue->toStorage(),
            'expense_balance' => $expense->toStorage(),
            'current_profit_loss' => $revenue->subtract($expense)->toStorage(),
            'temporary_accounts_remaining' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: PaginationMeta}
     */
    private function paginateTemporaryRows(array $rows): array
    {
        $pagination = $this->pagination;

        if ($pagination->mode === ReportPagination::MODE_OFFSET) {
            $total = count($rows);
            $slice = array_slice($rows, $pagination->offsetValue(), $pagination->perPage);

            return [
                $slice,
                PaginationMeta::offset(
                    $pagination->page,
                    $pagination->perPage,
                    $total,
                    $pagination->offsetValue() + count($slice) < $total,
                ),
            ];
        }

        $start = 0;
        if ($pagination->cursor !== null) {
            $keys = CursorCodec::decode($pagination->cursor);
            $cursorId = (int) ($keys['account_id'] ?? 0);
            foreach ($rows as $index => $row) {
                if ((int) $row['account_id'] === $cursorId) {
                    $start = $index + 1;
                    break;
                }
            }
        }

        $slice = array_slice($rows, $start, $pagination->perPage + 1);
        $hasMore = count($slice) > $pagination->perPage;
        $slice = array_slice($slice, 0, $pagination->perPage);
        $next = $hasMore && $slice !== []
            ? CursorCodec::encode(['account_id' => $slice[array_key_last($slice)]['account_id']])
            : null;

        return [$slice, PaginationMeta::cursor($pagination->perPage, $hasMore, $next)];
    }

    /**
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $temporary
     * @return list<ClosingReadinessCheck>
     */
    private function scopeChecks(
        AccountingPeriod $period,
        mixed $fiscalYear,
        LedgerQuery $query,
        array $activity,
        array $temporary,
    ): array {
        $unposted = $activity['draft_document_count'] + $activity['pending_document_count'] + $activity['approved_document_count'];
        $balanced = Amount::of($activity['total_debit'])->equals($activity['total_credit']);
        $reConfigured = is_string(config('accounting.account.system_accounts.retained_earnings'))
            && config('accounting.account.system_accounts.retained_earnings') !== '';
        $reAvailable = $reConfigured && $this->retainedEarningsResolvable($query);

        return [
            new ClosingReadinessCheck('PERIOD_EXISTS', true, 'blocker', 1, 'Accounting period exists.'),
            new ClosingReadinessCheck(
                'PERIOD_FISCAL_YEAR_MATCH',
                $fiscalYear !== null && (int) $period->fiscal_year_id === (int) $fiscalYear->id,
                'blocker',
                1,
                'Period belongs to the selected fiscal year.',
            ),
            new ClosingReadinessCheck(
                'PERIOD_ALREADY_CLOSED',
                ! $period->isClosed(),
                'blocker',
                $period->isClosed() ? 1 : 0,
                $period->isClosed()
                    ? 'Period is already closed. Reporting remains available; close() will reject a second close.'
                    : 'Period is not already closed.',
            ),
            new ClosingReadinessCheck(
                'PERIOD_CLOSABLE',
                $period->isOpen(),
                'blocker',
                $period->isOpen() ? 0 : 1,
                $period->isOpen()
                    ? 'Period is open and can be closed by AccountingPeriodService::close().'
                    : 'Period is not open. close() only accepts an OPEN period.',
            ),
            new ClosingReadinessCheck(
                'DEBIT_EQUALS_CREDIT',
                $balanced,
                'blocker',
                $balanced ? 0 : 1,
                $balanced
                    ? 'Posted period debit equals posted period credit.'
                    : 'Posted period debit does not equal posted period credit.',
            ),
            new ClosingReadinessCheck(
                'UNPOSTED_DOCUMENTS',
                $unposted === 0,
                'blocker',
                $unposted,
                $unposted === 0
                    ? 'No draft, pending, or approved documents remain in this period scope.'
                    : "{$unposted} documents remain unposted in this period scope.",
            ),
            new ClosingReadinessCheck(
                'EXISTING_CLOSING_JOURNAL',
                $activity['closing_document_count'] === 0,
                'warning',
                $activity['closing_document_count'],
                $activity['closing_document_count'] === 0
                    ? 'No posted type=closing journals exist in this scope.'
                    : 'Posted closing journals already exist. Period close() does not create another; year-end P&L close may treat this as already closed.',
            ),
            new ClosingReadinessCheck(
                'DUPLICATE_CLOSING_JOURNAL',
                $activity['closing_document_count'] <= 1,
                'warning',
                max(0, $activity['closing_document_count'] - 1),
                $activity['closing_document_count'] <= 1
                    ? 'No duplicate closing journals detected.'
                    : 'More than one posted closing journal exists in this scope.',
            ),
            new ClosingReadinessCheck(
                'RETAINED_EARNINGS_AVAILABLE',
                $reAvailable,
                'warning',
                $reAvailable ? 0 : 1,
                $reAvailable
                    ? 'Retained earnings system account is configured and resolvable.'
                    : 'Retained earnings account is missing or not postable. Required for year-end P&L close, not for period close().',
            ),
            new ClosingReadinessCheck(
                'OPENING_INCOMPLETE',
                $fiscalYear?->opening_done ? true : false,
                'warning',
                $fiscalYear?->opening_done ? 0 : 1,
                $fiscalYear?->opening_done
                    ? 'Fiscal year opening_done is set.'
                    : 'Fiscal year opening is not marked complete. Period close() does not require this.',
            ),
            new ClosingReadinessCheck(
                'SYSTEM_ACCOUNTS_AVAILABLE',
                $reConfigured,
                'warning',
                $reConfigured ? 0 : 1,
                $reConfigured
                    ? 'Retained earnings system account key is configured.'
                    : 'accounting.account.system_accounts.retained_earnings is not configured.',
            ),
            new ClosingReadinessCheck(
                'TEMPORARY_ACCOUNTS_REMAINING',
                $temporary['temporary_accounts_remaining'] === 0,
                'warning',
                $temporary['temporary_accounts_remaining'],
                $temporary['temporary_accounts_remaining'] === 0
                    ? 'No residual temporary P&L balances remain.'
                    : 'Temporary P&L account balances remain. Period close() does not post a closing journal.',
            ),
        ];
    }

    private function retainedEarningsResolvable(LedgerQuery $query): bool
    {
        $code = config('accounting.account.system_accounts.retained_earnings');
        if (! is_string($code) || $code === '') {
            return false;
        }

        $branchIds = $query->branchIds();
        if ($branchIds === []) {
            $branchIds = [null];
        }

        $accounts = app(AccountService::class);
        foreach ($branchIds as $branchId) {
            $account = $accounts->findByCodeForBranch($code, $branchId);
            if (! $account || ! $account->isPostable()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function perBranchReadiness(AccountingPeriod $period): array
    {
        $documents = (new Document)->getTable();
        $items = (new DocumentItem)->getTable();
        $query = $this->filters->toLedgerQuery();
        $rows = $query->listingQuery()
            ->selectRaw("{$documents}.branch_id as branch_id")
            ->selectRaw("SUM(CASE WHEN {$documents}.status = 'posted' THEN 1 ELSE 0 END) as posted_lines")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$documents}.status IN ('draft','pending','approved') THEN {$documents}.id END) as unposted_docs")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$documents}.status = 'posted' THEN {$items}.debit ELSE 0 END), 0) as debit")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$documents}.status = 'posted' THEN {$items}.credit ELSE 0 END), 0) as credit")
            ->groupBy("{$documents}.branch_id")
            ->orderBy("{$documents}.branch_id")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $unposted = (int) $row->unposted_docs;
            $balanced = Amount::of($row->debit)->equals($row->credit);
            $ready = $period->isOpen() && $unposted === 0 && $balanced;
            $result[] = [
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'ready_to_close' => $ready,
                'unposted_document_count' => $unposted,
                'total_debit' => Amount::of($row->debit)->toStorage(),
                'total_credit' => Amount::of($row->credit)->toStorage(),
                'blockers' => array_values(array_filter([
                    ! $period->isOpen() ? 'PERIOD_CLOSABLE' : null,
                    $unposted > 0 ? 'UNPOSTED_DOCUMENTS' : null,
                    ! $balanced ? 'DEBIT_EQUALS_CREDIT' : null,
                ])),
            ];
        }

        return $result;
    }

    private function hasUnreadyBranch(?array $branches): bool
    {
        if ($branches === null) {
            return false;
        }

        foreach ($branches as $branch) {
            if (! $branch['ready_to_close']) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<ClosingReadinessCheck>  $checks */
    private function hasCode(array $checks, string $code): bool
    {
        foreach ($checks as $check) {
            if ($check->code === $code) {
                return true;
            }
        }

        return false;
    }
}
