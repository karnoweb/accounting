<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\AccountLedger;
use Karnoweb\Accounting\Reporting\BalanceSheetReport;
use Karnoweb\Accounting\Reporting\CashAccountResolver;
use Karnoweb\Accounting\Reporting\CashMovementReport;
use Karnoweb\Accounting\Reporting\FinancialStatements;
use Karnoweb\Accounting\Reporting\GeneralLedgerReport;
use Karnoweb\Accounting\Reporting\GeneralLedgerSummaryRow;
use Karnoweb\Accounting\Reporting\HierarchyRollup;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Reporting\PaginatedAccountStatement;
use Karnoweb\Accounting\Reporting\PaginatedCostCenterStatement;
use Karnoweb\Accounting\Reporting\PaginatedGeneralLedgerSummary;
use Karnoweb\Accounting\Reporting\ProfitAndLossReport;
use Karnoweb\Accounting\Reporting\StatementLine;
use Karnoweb\Accounting\Reporting\TrialBalanceReport;
use Karnoweb\Accounting\Enums\AccountNature;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Karnoweb\Accounting\Support\Amount;

/**
 * Service for accounting reports (trial balance, statements, general ledger).
 *
 * Every report reads posted journal lines (acc_document_items JOIN acc_documents),
 * never Account::cached_balance — see Karnoweb\Accounting\Reporting\LedgerQuery.
 */
class ReportService
{
    public function __construct(
        private BalanceService $balanceService,
        private AccountService $accountService
    ) {}

    /**
     * Trial balance: list of posting-level accounts with non-zero FY balance, debit and credit columns.
     *
     * @deprecated 13.2.0 Not a real Trial Balance: no opening/period split, no L0-L2
     *             rollup, no ending columns, and zero-balance accounts are dropped.
     *             Use trialBalanceDetailed() instead. Kept unchanged for backward compatibility.
     * @return array<int, array{account: \Karnoweb\Accounting\Models\Account, debit: float, credit: float}>
     */
    public function trialBalance(?FiscalYear $fiscalYear = null): array
    {
        $fiscalYear ??= FiscalYear::current();

        if (! $fiscalYear) {
            return [];
        }

        $accounts = $this->accountService->search([
            'is_active' => true,
            'level' => AccountHierarchy::postingLevel(),
        ]);

        $rows = [];
        foreach ($accounts as $account) {
            $balance = Amount::of($this->balanceService->getBalance($account, $fiscalYear));
            if ( ! $balance->isZero()) {
                $rows[] = [
                    'account' => $account,
                    'debit' => $balance->isPositive() ? $balance->toFloat() : 0.0,
                    'credit' => $balance->isNegative() ? $balance->abs()->toFloat() : 0.0,
                ];
            }
        }

        return $rows;
    }

    /**
     * Real Trial Balance built from posted journal lines — opening/period/ending
     * debit & credit for every account, L0 (Group) through L3 (Detail). L0-L2 rows
     * are rolled up from their L3 descendants; nothing is read from cached_balance.
     *
     * @example
     * Accounting::report()->trialBalanceDetailed($fiscalYear);
     * Accounting::report()->trialBalanceDetailed(
     *     LedgerQuery::make()->from('2025-01-01')->to('2025-06-30')->branch($branchId)
     * );
     */
    public function trialBalanceDetailed(LedgerQuery|FiscalYear|null $criteria = null): TrialBalanceReport
    {
        $query = match (true) {
            $criteria instanceof LedgerQuery => $criteria,
            $criteria instanceof FiscalYear => LedgerQuery::make()->forFiscalYear($criteria),
            default => LedgerQuery::make()->forFiscalYear(FiscalYear::current()),
        };

        $rows = HierarchyRollup::build($query->trialBalanceAggregates());

        return new TrialBalanceReport($rows, $query->resolvedFrom(), $query->resolvedTo());
    }

    /**
     * Period-flow income statement. Closing journals are excluded so a closed year
     * still reports the year's profit. Contra-income (returns/discounts stored as
     * income) reduces revenue through natural-balance presentation.
     */
    public function profitAndLoss(LedgerQuery|FiscalYear|null $criteria = null): ProfitAndLossReport
    {
        $query = clone $this->normalizeStatementCriteria($criteria);
        $query->excludeDocumentTypes('closing');

        return FinancialStatements::profitAndLoss($this->trialBalanceDetailed($query));
    }

    /**
     * Stock statement as of `LedgerQuery::resolvedTo()`. Includes closing journals.
     * Uncleared temporary balances appear as `currentEarnings` so the equation
     * Assets = Liabilities + Equity + Current Earnings holds before year-end close.
     */
    public function balanceSheet(LedgerQuery|FiscalYear|null $criteria = null): BalanceSheetReport
    {
        return FinancialStatements::balanceSheet(
            $this->trialBalanceDetailed($this->normalizeStatementCriteria($criteria))
        );
    }

    /**
     * Cash/bank movement foundation. Not an operating/investing/financing statement.
     * Cash accounts are those mapped by `accounting.reports.cash_system_keys`.
     */
    public function cashMovements(LedgerQuery|FiscalYear|null $criteria = null): CashMovementReport
    {
        $query = $this->normalizeStatementCriteria($criteria);
        $resolver = new CashAccountResolver($this->accountService);
        $cashIds = $resolver->accountIds(
            $query->branchId(),
            $query->isBranchFilterApplied()
        );

        if ($cashIds === []) {
            return new CashMovementReport(
                accounts: collect(),
                inflow: 0.0,
                outflow: 0.0,
                transfers: 0.0,
                netChange: 0.0,
                cashAccountIds: [],
                from: $query->resolvedFrom(),
                to: $query->resolvedTo(),
            );
        }

        $scoped = (clone $query)->forAccounts($cashIds);
        $period = $scoped->periodTotalsByAccount();
        $accounts = Account::query()->whereIn('id', $cashIds)->orderBy('code')->get();

        $lines = $accounts->map(function (Account $account) use ($period) {
            $totals = $period[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $nature = $account->nature instanceof AccountNature
                ? $account->nature
                : AccountNature::from((string) $account->nature);

            return new StatementLine(
                accountId: $account->id,
                parentId: $account->parent_id,
                code: $account->code,
                title: $account->title,
                level: (int) $account->level,
                type: $account->type->value,
                nature: $nature->value,
                amount: $nature->naturalAmount($totals['debit'], $totals['credit']),
                debit: Amount::of($totals['debit'])->toFloat(),
                credit: Amount::of($totals['credit'])->toFloat(),
            );
        })->values();

        $inflow = Amount::sum($lines->map(fn (StatementLine $line) => $line->debit));
        $outflow = Amount::sum($lines->map(fn (StatementLine $line) => $line->credit));
        $transfers = $this->cashTransferTotal($scoped, $cashIds);

        return new CashMovementReport(
            accounts: $lines,
            inflow: $inflow->toFloat(),
            outflow: $outflow->toFloat(),
            transfers: $transfers->toFloat(),
            netChange: $inflow->subtract($outflow)->toFloat(),
            cashAccountIds: $cashIds,
            from: $scoped->resolvedFrom(),
            to: $scoped->resolvedTo(),
        );
    }

    private function normalizeStatementCriteria(LedgerQuery|FiscalYear|null $criteria): LedgerQuery
    {
        return match (true) {
            $criteria instanceof LedgerQuery => $criteria,
            $criteria instanceof FiscalYear => LedgerQuery::make()->forFiscalYear($criteria),
            default => LedgerQuery::make()->forFiscalYear(FiscalYear::current()),
        };
    }

    /**
     * Debit total of posted documents in scope whose every line is a cash account.
     *
     * @param  list<int>  $cashIds
     */
    private function cashTransferTotal(LedgerQuery $query, array $cashIds): Amount
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $documentIds = $query->baseQuery()
            ->select("{$documents}.id")
            ->distinct()
            ->pluck('id')
            ->all();

        if ($documentIds === []) {
            return Amount::zero();
        }

        $mixedIds = DB::table($items)
            ->whereIn('document_id', $documentIds)
            ->whereNotIn('account_id', $cashIds)
            ->distinct()
            ->pluck('document_id')
            ->all();

        $transferIds = array_values(array_diff($documentIds, $mixedIds));
        if ($transferIds === []) {
            return Amount::zero();
        }

        $row = DB::table($items)
            ->whereIn('document_id', $transferIds)
            ->selectRaw('COALESCE(SUM(debit), 0) as transfers')
            ->first();

        return Amount::of($row->transfers ?? 0);
    }

    /**
     * General Ledger: opening -> journal lines -> running balance -> closing, for
     * every account matched by $query (or every posting-level account when the
     * query has no account filter).
     *
     * @example
     * Accounting::report()->generalLedger(
     *     LedgerQuery::make()->forFiscalYear($fiscalYear)->branch($branchId)
     * );
     */
    public function generalLedger(LedgerQuery $query): GeneralLedgerReport
    {
        return new GeneralLedgerReport($this->buildAccountLedgers($query));
    }

    /**
     * Single-account projection over the same ledger foundation as generalLedger().
     * $query must be scoped to exactly one account via LedgerQuery::forAccount().
     *
     * @example
     * Accounting::report()->accountStatement(
     *     LedgerQuery::make()->forAccount($account)->from($from)->to($to)
     * );
     */
    public function accountStatement(LedgerQuery $query): AccountLedger
    {
        $accountId = $this->requireSingleAccountId($query);

        return $this->buildAccountLedgers($query)->get($accountId)
            ?? new AccountLedger($accountId, Amount::zero()->toFloat(), collect(), Amount::zero()->toFloat());
    }

    /**
     * Paginated single-account statement. Meta (opening/closing/period totals) is always
     * computed for the full scope; only journal lines are sliced. Pass $perPage = -1 for all lines.
     *
     * @example
     * Accounting::report()->accountStatementPaginated(
     *     LedgerQuery::make()->forAccount($account)->forFiscalYear($fy),
     *     page: 2,
     *     perPage: 50,
     * );
     */
    public function accountStatementPaginated(
        LedgerQuery $query,
        int $page = 1,
        ?int $perPage = null
    ): PaginatedAccountStatement {
        $accountId = $this->requireSingleAccountId($query);
        $total = $query->countLines();
        [$page, $perPage, $offset] = $this->resolvePagination($page, $perPage, $total);

        $opening = Amount::of($query->openingBalances()[$accountId] ?? 0);
        $periodTotals = $query->periodTotals();
        $closing = $opening->add($periodTotals['balance']);

        $prefix = Amount::of($query->prefixSignedSum($offset));
        $lines = $query->pageLines($offset, $perPage);

        $running = $opening->add($prefix);
        foreach ($lines as $line) {
            $running = $running->add(Amount::signedBalance($line->debit, $line->credit));
            $line->runningBalance = $running->toFloat();
        }

        return new PaginatedAccountStatement(
            accountId: $accountId,
            openingBalance: $opening->toFloat(),
            closingBalance: $closing->toFloat(),
            periodTotals: $periodTotals,
            from: $query->resolvedFrom(),
            to: $query->resolvedTo(),
            lines: $this->makePaginator($lines, $total, $perPage, $page),
        );
    }

    /**
     * Paginated journal lines for a cost center. Requires LedgerQuery::costCenter($id).
     * Meta totals cover the full scope; running balance is not applied across accounts.
     *
     * @example
     * Accounting::report()->costCenterStatementPaginated(
     *     LedgerQuery::make()->costCenter($center)->forFiscalYear($fy),
     *     page: 1,
     *     perPage: 20,
     * );
     */
    public function costCenterStatementPaginated(
        LedgerQuery $query,
        int $page = 1,
        ?int $perPage = null
    ): PaginatedCostCenterStatement {
        if (! $query->isCostCenterFilterApplied() || $query->costCenterId() === null) {
            throw new InvalidArgumentException(
                'costCenterStatementPaginated() requires a LedgerQuery scoped via costCenter($id).'
            );
        }

        $total = $query->countLines();
        [$page, $perPage, $offset] = $this->resolvePagination($page, $perPage, $total);
        $lines = $query->pageLines($offset, $perPage);

        return new PaginatedCostCenterStatement(
            costCenterId: $query->costCenterId(),
            periodTotals: $query->periodTotals(),
            from: $query->resolvedFrom(),
            to: $query->resolvedTo(),
            lines: $this->makePaginator($lines, $total, $perPage, $page),
        );
    }

    /**
     * Level-1 general ledger: paginate posting accounts with opening / period / closing
     * aggregates. Drill into accountStatementPaginated() for lines.
     *
     * @example
     * Accounting::report()->generalLedgerSummary(
     *     LedgerQuery::make()->forFiscalYear($fy)->branch($branchId),
     *     page: 1,
     *     perPage: 20,
     * );
     */
    public function generalLedgerSummary(
        LedgerQuery $query,
        int $page = 1,
        ?int $perPage = null
    ): PaginatedGeneralLedgerSummary {
        $accountIds = $this->resolveAccountIds($query);
        $query = (clone $query)->forAccounts($accountIds);

        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->orderBy('code')
            ->get(['id', 'code', 'title'])
            ->keyBy('id');

        $orderedIds = $accounts->keys()->all();
        $total = count($orderedIds);
        [$page, $perPage, $offset] = $this->resolvePagination($page, $perPage, $total);

        $pageIds = array_slice($orderedIds, $offset, $perPage);
        $pageQuery = (clone $query)->forAccounts($pageIds);
        $openings = $pageQuery->openingBalances();
        $periodByAccount = $pageQuery->periodTotalsByAccount();

        $rows = collect($pageIds)->map(function (int $accountId) use ($accounts, $openings, $periodByAccount) {
            $account = $accounts->get($accountId);
            $opening = Amount::of($openings[$accountId] ?? 0);
            $period = $periodByAccount[$accountId] ?? ['debit' => 0.0, 'credit' => 0.0];
            $periodDebit = Amount::of($period['debit']);
            $periodCredit = Amount::of($period['credit']);

            return new GeneralLedgerSummaryRow(
                accountId: $accountId,
                code: (string) ($account->code ?? ''),
                title: (string) ($account->title ?? ''),
                openingBalance: $opening->toFloat(),
                periodDebit: $periodDebit->toFloat(),
                periodCredit: $periodCredit->toFloat(),
                closingBalance: $opening->add($periodDebit)->subtract($periodCredit)->toFloat(),
            );
        });

        return new PaginatedGeneralLedgerSummary(
            from: $query->resolvedFrom(),
            to: $query->resolvedTo(),
            accounts: $this->makePaginator($rows, $total, $perPage, $page),
        );
    }

    /** @return Collection<int, AccountLedger> keyed by account_id */
    private function buildAccountLedgers(LedgerQuery $query): Collection
    {
        $accountIds = $this->resolveAccountIds($query);
        $query = (clone $query)->forAccounts($accountIds);

        $openings = $query->openingBalances();
        $runningBalances = [];
        foreach ($openings as $accountId => $opening) {
            $runningBalances[$accountId] = Amount::of($opening);
        }
        $lines = collect($accountIds)->mapWithKeys(fn (int $id) => [$id => collect()]);

        foreach ($query->cursor() as $line) {
            $current = $runningBalances[$line->accountId] ?? Amount::zero();
            $current = $current->add(Amount::signedBalance($line->debit, $line->credit));
            $line->runningBalance = $current->toFloat();
            $runningBalances[$line->accountId] = $current;
            $lines[$line->accountId]->push($line);
        }

        return $lines->map(fn (Collection $accountLines, int $accountId) => new AccountLedger(
            accountId: $accountId,
            openingBalance: Amount::of($openings[$accountId] ?? 0)->toFloat(),
            lines: $accountLines,
            closingBalance: ($runningBalances[$accountId] ?? Amount::of($openings[$accountId] ?? 0))->toFloat(),
        ));
    }

    /** @return list<int> */
    private function resolveAccountIds(LedgerQuery $query): array
    {
        $accountIds = $query->accountIds();

        if ($accountIds !== []) {
            return $accountIds;
        }

        $filters = ['level' => AccountHierarchy::postingLevel()];
        if ($query->isBranchFilterApplied()) {
            $filters['branch_id'] = $query->branchId();
        }

        return $this->accountService->search($filters)
            ->pluck('id')
            ->all();
    }

    private function requireSingleAccountId(LedgerQuery $query): int
    {
        $accountIds = $query->accountIds();

        if (count($accountIds) !== 1) {
            throw new InvalidArgumentException(
                'This report requires a LedgerQuery scoped to exactly one account via forAccount().'
            );
        }

        return $accountIds[0];
    }

    /**
     * @return array{0: int, 1: int, 2: int} [page, perPage, offset]
     */
    private function resolvePagination(int $page, ?int $perPage, int $total): array
    {
        $perPage ??= (int) config('accounting.reports.per_page', 50);

        if ($perPage === -1) {
            $pageSize = max($total, 1);

            return [1, $pageSize, 0];
        }

        $pageSize = max(1, $perPage);
        $page = max(1, $page);

        return [$page, $pageSize, ($page - 1) * $pageSize];
    }

    /**
     * @param  Collection<int, mixed>  $items
     */
    private function makePaginator(Collection $items, int $total, int $perPage, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $items->values(),
            $total,
            max($perPage, 1),
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }
}
