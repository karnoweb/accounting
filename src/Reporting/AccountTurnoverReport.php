<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Karnoweb\Accounting\Support\Amount;

/**
 * Opening / period turnover / closing per account.
 *
 * Opening = financially effective posted balance strictly before from_date.
 * Period = posted debit/credit from from_date through to_date inclusive.
 * Closing = opening + period movement.
 *
 * Fiscal-year opening journals are ordinary posted rows; they are not treated
 * as a special "report opening" unless they fall before from_date.
 *
 * Hierarchy rollup sums descendant leaf totals once. A parent is never the
 * sum of itself plus children.
 */
final class AccountTurnoverReport
{
    public function __construct(
        private readonly LedgerReportFilters $filters,
        private readonly ReportPagination $pagination,
    ) {}

    /**
     * Full aligned account rows for this filter set, before pagination.
     *
     * @return array{0: list<AccountTurnoverRow>, 1: array<string, string>, 2: list<array<string, mixed>>|null, 3: LedgerReportFilters, 4: LedgerQuery}
     */
    public function compiled(): array
    {
        $filters = $this->filters->assertSort(LedgerReportFilters::TURNOVER_SORTS);
        $query = $this->ledgerQuery($filters);

        if ($filters->displayLevel !== null) {
            return $this->compileCatalogLevel($filters, $query);
        }

        $openings = $query->openingDebitCreditByAccount();
        $period = $query->periodActivityByAccount();
        $branchByAccount = $filters->branchScope->includeBreakdown
            ? $query->periodTotalsByAccountAndBranch()
            : [];

        $leafIds = $this->resolveLeafAccountIds($openings, $period, $filters);
        $accounts = $this->loadChartSlice($leafIds, $filters->rollupHierarchy);
        $rows = $this->buildRows($accounts, $leafIds, $openings, $period, $branchByAccount, $filters);
        $rows = $this->applyZeroFlags($rows, $filters);

        if ($filters->rollupHierarchy) {
            $rows = $this->rollUp($accounts, $rows);
            $rows = $this->applyZeroFlags($rows, $filters);
        } elseif ($filters->leafOnly) {
            $posting = AccountHierarchy::postingLevel();
            $rows = array_values(array_filter($rows, fn (AccountTurnoverRow $row) => $row->level === $posting));
        }

        $reportTotals = $this->totalsFromLeaves($rows, $filters->rollupHierarchy);
        $sorted = $this->sortRows($rows, $filters);

        $branchBreakdown = null;
        if ($filters->branchScope->includeBreakdown) {
            $branchBreakdown = array_values($query->periodTotalsByBranch());
        }

        return [$sorted, $reportTotals, $branchBreakdown, $filters, $query];
    }

    public function build(): AccountTurnoverResult
    {
        $filters = $this->filters->assertSort(LedgerReportFilters::TURNOVER_SORTS);

        if ($filters->displayLevel !== null) {
            return $this->buildCatalogLevel($filters);
        }

        [$sorted, $reportTotals, $branchBreakdown, $filters] = $this->compiled();
        [$pageRows, $pagination] = $this->paginate($sorted);
        $pageTotals = $this->sumRows($pageRows, onlyLeaves: $filters->rollupHierarchy);

        $summary = [
            'total_opening_debit' => $reportTotals['opening_debit'],
            'total_opening_credit' => $reportTotals['opening_credit'],
            'total_period_debit' => $reportTotals['period_debit'],
            'total_period_credit' => $reportTotals['period_credit'],
            'total_closing_debit' => $reportTotals['closing_debit'],
            'total_closing_credit' => $reportTotals['closing_credit'],
            'row_count' => count($sorted),
        ];

        return new AccountTurnoverResult(
            meta: ReportMeta::make(
                'account_turnover',
                $filters->toArray(),
                $filters->branchScope->toArray(),
                $filters->mode->value,
            ),
            summary: $summary,
            data: $pageRows,
            pagination: $pagination,
            pageTotals: $pageTotals,
            reportTotals: $reportTotals,
            branches: $branchBreakdown,
        );
    }

    /**
     * @param  array<int, array{opening_debit: string, opening_credit: string}>  $openings
     * @param  array<int, array{debit: string, credit: string, transaction_count: int, document_count: int}>  $period
     * @return list<int>
     */
    private function resolveLeafAccountIds(array $openings, array $period, LedgerReportFilters $filters): array
    {
        $ids = array_values(array_unique(array_merge(array_keys($openings), array_keys($period))));

        if ($filters->accountIds !== []) {
            $ids = array_values(array_intersect($ids, $filters->accountIds));
            if ($filters->includeZeroActivity) {
                $ids = array_values(array_unique(array_merge($ids, $filters->accountIds)));
            }
        } elseif ($filters->includeZeroActivity) {
            $ids = array_values(array_unique(array_merge(
                $ids,
                Account::query()->where('level', AccountHierarchy::postingLevel())->pluck('id')->all()
            )));
        }

        return array_map('intval', $ids);
    }

    /**
     * @param  list<int>  $leafIds
     * @return \Illuminate\Support\Collection<int, Account>
     */
    private function loadChartSlice(array $leafIds, bool $rollup)
    {
        if ($leafIds === []) {
            return collect();
        }

        $accounts = Account::query()
            ->select(['id', 'parent_id', 'code', 'title', 'level', 'type', 'nature'])
            ->whereIn('id', $leafIds)
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if (! $rollup) {
            return $accounts;
        }

        $missing = $accounts->pluck('parent_id')->filter()->unique()->diff($accounts->keys());
        while ($missing->isNotEmpty()) {
            $more = Account::query()
                ->select(['id', 'parent_id', 'code', 'title', 'level', 'type', 'nature'])
                ->whereIn('id', $missing->all())
                ->get()
                ->keyBy('id');
            if ($more->isEmpty()) {
                break;
            }
            $accounts = $accounts->union($more);
            $missing = $more->pluck('parent_id')->filter()->unique()->diff($accounts->keys());
        }

        return $accounts;
    }

    private function ledgerQuery(LedgerReportFilters $filters): LedgerQuery
    {
        $query = $filters->toLedgerQuery();
        if ($filters->displayLevel === null) {
            return $query;
        }

        $catalog = $filters->catalogFilters();

        return $query->constrainAccounts(
            fn ($builder, string $accounts) => $catalog->constrainPostingAccounts($builder, $accounts)
        );
    }

    /**
     * @return array{0: list<AccountTurnoverRow>, 1: array<string, string>, 2: list<array<string, mixed>>|null, 3: LedgerReportFilters, 4: LedgerQuery}
     */
    private function compileCatalogLevel(LedgerReportFilters $filters, LedgerQuery $query): array
    {
        $catalog = new AccountCatalog($filters->catalogFilters());
        $accounts = $catalog->listingQuery()
            ->addSelect(['type', 'nature'])
            ->get()
            ->keyBy('id');

        $rows = $this->rowsForCatalogAccounts($filters, $query, $accounts);
        $reportTotals = $this->catalogReportTotals($query);
        $sorted = $this->sortRows($rows, $filters);
        $branchBreakdown = $filters->branchScope->includeBreakdown
            ? array_values($query->periodTotalsByBranch())
            : null;

        return [$sorted, $reportTotals, $branchBreakdown, $filters, $query];
    }

    private function buildCatalogLevel(LedgerReportFilters $filters): AccountTurnoverResult
    {
        $query = $this->ledgerQuery($filters);
        $catalog = new AccountCatalog($filters->catalogFilters());
        $page = $catalog->page($this->pagination);
        $accounts = Account::query()
            ->select(['id', 'parent_id', 'code', 'title', 'level', 'type', 'nature'])
            ->whereIn('id', array_map(fn (AccountNode $node) => $node->id, $page->data))
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($page->data as $node) {
            $account = $accounts->get($node->id);
            if ($account) {
                $ordered[$account->id] = $account;
            }
        }

        $rows = $this->rowsForCatalogAccounts($filters, $query, collect($ordered));
        $reportTotals = $this->catalogReportTotals($query);
        $pageTotals = $this->sumRows($rows, onlyLeaves: false);
        $branchBreakdown = $filters->branchScope->includeBreakdown
            ? array_values($query->periodTotalsByBranch())
            : null;

        $summary = [
            'total_opening_debit' => $reportTotals['opening_debit'],
            'total_opening_credit' => $reportTotals['opening_credit'],
            'total_period_debit' => $reportTotals['period_debit'],
            'total_period_credit' => $reportTotals['period_credit'],
            'total_closing_debit' => $reportTotals['closing_debit'],
            'total_closing_credit' => $reportTotals['closing_credit'],
            'row_count' => $page->pagination->total ?? count($rows),
        ];

        return new AccountTurnoverResult(
            meta: ReportMeta::make(
                'account_turnover',
                $filters->toArray(),
                $filters->branchScope->toArray(),
                $filters->mode->value,
            ),
            summary: $summary,
            data: $this->attachHierarchyCounts($rows, $page->data),
            pagination: $page->pagination,
            pageTotals: $pageTotals,
            reportTotals: $reportTotals,
            branches: $branchBreakdown,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accounts
     * @return list<AccountTurnoverRow>
     */
    private function rowsForCatalogAccounts(LedgerReportFilters $filters, LedgerQuery $query, $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $ids = $accounts->keys()->map(fn ($id) => (int) $id)->all();
        $postingDisplay = AccountHierarchy::displayLevel(AccountHierarchy::postingLevel());

        if ($filters->displayLevel === $postingDisplay) {
            $pageQuery = (clone $query)->forAccounts($ids);
            $openings = $pageQuery->openingDebitCreditByAccount();
            $period = $pageQuery->periodActivityByAccount();
            $branchByAccount = $filters->branchScope->includeBreakdown
                ? $pageQuery->periodTotalsByAccountAndBranch()
                : [];

            return $this->buildRows($accounts, $ids, $openings, $period, $branchByAccount, $filters);
        }

        return $this->rollPageAncestors($filters, $query, $accounts);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $ancestors
     * @return list<AccountTurnoverRow>
     */
    private function rollPageAncestors(LedgerReportFilters $filters, LedgerQuery $query, $ancestors): array
    {
        $ancestorIds = $ancestors->keys()->map(fn ($id) => (int) $id)->all();
        $catalog = $filters->catalogFilters();
        $pageQuery = $filters->toLedgerQuery()->constrainAccounts(function ($builder, string $accounts) use ($catalog, $ancestorIds): void {
            $catalog->constrainPostingAccounts($builder, $accounts);
            $catalog->restrictToAncestorIds($builder, $accounts, $ancestorIds);
        });
        $leafOpenings = $pageQuery->openingDebitCreditByAccount();
        $leafPeriod = $pageQuery->periodActivityByAccount();
        $leafIds = array_values(array_unique(array_merge(array_keys($leafOpenings), array_keys($leafPeriod))));
        $leafToAncestor = $this->mapLeavesToAncestors($leafIds, $ancestorIds);

        $metrics = [];
        foreach ($ancestorIds as $id) {
            $metrics[$id] = [
                'opening_debit' => Amount::zero(),
                'opening_credit' => Amount::zero(),
                'period_debit' => Amount::zero(),
                'period_credit' => Amount::zero(),
                'transaction_count' => 0,
                'document_count' => 0,
                'branches' => [],
            ];
        }

        foreach ($leafToAncestor as $leafId => $ancestorId) {
            if (! isset($metrics[$ancestorId])) {
                continue;
            }
            $opening = $leafOpenings[$leafId] ?? ['opening_debit' => '0.00', 'opening_credit' => '0.00'];
            $activity = $leafPeriod[$leafId] ?? ['debit' => '0.00', 'credit' => '0.00', 'transaction_count' => 0, 'document_count' => 0];
            $bucket = &$metrics[$ancestorId];
            $bucket['opening_debit'] = $bucket['opening_debit']->add($opening['opening_debit']);
            $bucket['opening_credit'] = $bucket['opening_credit']->add($opening['opening_credit']);
            $bucket['period_debit'] = $bucket['period_debit']->add($activity['debit']);
            $bucket['period_credit'] = $bucket['period_credit']->add($activity['credit']);
            $bucket['transaction_count'] += (int) $activity['transaction_count'];
            $bucket['document_count'] += (int) $activity['document_count'];
            unset($bucket);
        }

        $rows = [];
        foreach ($ancestors as $account) {
            $m = $metrics[(int) $account->id];
            $rows[] = AccountTurnoverRow::fromMetrics(
                $account,
                $m['opening_debit'],
                $m['opening_credit'],
                $m['period_debit'],
                $m['period_credit'],
                $m['transaction_count'],
                $m['document_count'],
                $m['branches'],
            );
        }

        return $rows;
    }

    /**
     * @param  list<int>  $leafIds
     * @param  list<int>  $ancestorIds
     * @return array<int, int>
     */
    private function mapLeavesToAncestors(array $leafIds, array $ancestorIds): array
    {
        if ($leafIds === [] || $ancestorIds === []) {
            return [];
        }

        $loaded = Account::query()
            ->select(['id', 'parent_id', 'level'])
            ->whereIn('id', array_values(array_unique(array_merge($leafIds, $ancestorIds))))
            ->get()
            ->keyBy('id');

        $missing = $loaded->pluck('parent_id')->filter()->unique()->diff($loaded->keys());
        while ($missing->isNotEmpty()) {
            $more = Account::query()
                ->select(['id', 'parent_id', 'level'])
                ->whereIn('id', $missing->all())
                ->get()
                ->keyBy('id');
            if ($more->isEmpty()) {
                break;
            }
            $loaded = $loaded->union($more);
            $missing = $more->pluck('parent_id')->filter()->unique()->diff($loaded->keys());
        }

        $ancestorSet = array_flip($ancestorIds);
        $map = [];
        foreach ($leafIds as $leafId) {
            $current = $loaded->get($leafId);
            while ($current) {
                if (isset($ancestorSet[(int) $current->id])) {
                    $map[$leafId] = (int) $current->id;
                    break;
                }
                $current = $current->parent_id ? $loaded->get($current->parent_id) : null;
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function catalogReportTotals(LedgerQuery $query): array
    {
        $opening = $query->openingTotalsExact();
        $period = $query->periodTotalsExact();
        $openingDebit = Amount::of($opening['opening_debit']);
        $openingCredit = Amount::of($opening['opening_credit']);
        $periodDebit = Amount::of($period['debit']);
        $periodCredit = Amount::of($period['credit']);

        return [
            'opening_debit' => $openingDebit->toStorage(),
            'opening_credit' => $openingCredit->toStorage(),
            'period_debit' => $periodDebit->toStorage(),
            'period_credit' => $periodCredit->toStorage(),
            'closing_debit' => $openingDebit->add($periodDebit)->toStorage(),
            'closing_credit' => $openingCredit->add($periodCredit)->toStorage(),
        ];
    }

    /**
     * @param  list<AccountTurnoverRow>  $rows
     * @param  list<AccountNode>  $nodes
     * @return list<AccountTurnoverRow>
     */
    private function attachHierarchyCounts(array $rows, array $nodes): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            $byId[$node->id] = $node;
        }

        $attached = [];
        foreach ($rows as $row) {
            $node = $byId[$row->accountId] ?? null;
            if (! $node) {
                $attached[] = $row;
                continue;
            }

            $attached[] = new AccountTurnoverRow(
                accountId: $row->accountId,
                accountCode: $row->accountCode,
                accountName: $row->accountName,
                accountType: $row->accountType,
                normalBalance: $row->normalBalance,
                level: $row->level,
                parentId: $row->parentId,
                openingDebit: $row->openingDebit,
                openingCredit: $row->openingCredit,
                openingBalance: $row->openingBalance,
                periodDebitTurnover: $row->periodDebitTurnover,
                periodCreditTurnover: $row->periodCreditTurnover,
                netMovement: $row->netMovement,
                closingDebit: $row->closingDebit,
                closingCredit: $row->closingCredit,
                closingBalance: $row->closingBalance,
                transactionCount: $row->transactionCount,
                documentCount: $row->documentCount,
                branches: $row->branches,
                childrenCount: $node->childrenCount,
                hasChildren: $node->hasChildren,
                level4Count: $node->level4Count,
                hasLevel4: $node->hasLevel4,
            );
        }

        return $attached;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accounts
     * @param  list<int>  $leafIds
     * @return list<AccountTurnoverRow>
     */
    private function buildRows($accounts, array $leafIds, array $openings, array $period, array $branchByAccount, LedgerReportFilters $filters): array
    {
        $rows = [];
        foreach ($leafIds as $accountId) {
            $account = $accounts->get($accountId);
            if (! $account) {
                continue;
            }

            if ($filters->accountType !== null && ($account->type->value ?? (string) $account->type) !== $filters->accountType) {
                continue;
            }

            if ($filters->search !== null && ! $this->accountMatchesSearch($account, $filters->search)) {
                continue;
            }

            $opening = $openings[$accountId] ?? ['opening_debit' => '0.00', 'opening_credit' => '0.00'];
            $activity = $period[$accountId] ?? ['debit' => '0.00', 'credit' => '0.00', 'transaction_count' => 0, 'document_count' => 0];
            $branches = [];
            foreach ($branchByAccount[$accountId] ?? [] as $branchKey => $totals) {
                $branches[] = [
                    'branch_id' => $branchKey === 'none' ? null : (int) $branchKey,
                    'debit' => $totals['debit'],
                    'credit' => $totals['credit'],
                ];
            }

            $rows[] = AccountTurnoverRow::fromMetrics(
                $account,
                Amount::of($opening['opening_debit']),
                Amount::of($opening['opening_credit']),
                Amount::of($activity['debit']),
                Amount::of($activity['credit']),
                $activity['transaction_count'],
                $activity['document_count'],
                $branches,
            );
        }

        return $rows;
    }

    private function accountMatchesSearch(Account $account, string $search): bool
    {
        $needle = mb_strtolower($search);

        return str_contains(mb_strtolower((string) $account->code), $needle)
            || str_contains(mb_strtolower((string) $account->title), $needle);
    }

    /**
     * @param  list<AccountTurnoverRow>  $rows
     * @return list<AccountTurnoverRow>
     */
    private function applyZeroFlags(array $rows, LedgerReportFilters $filters): array
    {
        return array_values(array_filter($rows, function (AccountTurnoverRow $row) use ($filters) {
            $hasActivity = ! Amount::of($row->periodDebitTurnover)->isZero()
                || ! Amount::of($row->periodCreditTurnover)->isZero();
            $hasOpening = ! Amount::of($row->openingBalance)->isZero();
            $hasClosing = ! Amount::of($row->closingBalance)->isZero();

            if ($hasActivity || $hasOpening || $hasClosing) {
                if (! $filters->includeZeroBalance && ! $hasClosing && ! $hasActivity) {
                    return false;
                }

                return true;
            }

            return $filters->includeZeroActivity || $filters->includeZeroBalance;
        }));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accounts
     * @param  list<AccountTurnoverRow>  $leafRows
     * @return list<AccountTurnoverRow>
     */
    private function rollUp($accounts, array $leafRows): array
    {
        $byId = [];
        foreach ($leafRows as $row) {
            $byId[$row->accountId] = $row;
        }

        $metrics = [];
        foreach ($accounts as $account) {
            $existing = $byId[$account->id] ?? null;
            $metrics[$account->id] = [
                'opening_debit' => Amount::of($existing?->openingDebit ?? 0),
                'opening_credit' => Amount::of($existing?->openingCredit ?? 0),
                'period_debit' => Amount::of($existing?->periodDebitTurnover ?? 0),
                'period_credit' => Amount::of($existing?->periodCreditTurnover ?? 0),
                'transaction_count' => $existing?->transactionCount ?? 0,
                'document_count' => $existing?->documentCount ?? 0,
                'branches' => $existing?->branches ?? [],
            ];
        }

        foreach ($accounts->sortByDesc('level') as $account) {
            if ($account->parent_id === null || ! isset($metrics[$account->parent_id])) {
                continue;
            }

            $parent = &$metrics[$account->parent_id];
            $child = $metrics[$account->id];
            $parent['opening_debit'] = $parent['opening_debit']->add($child['opening_debit']);
            $parent['opening_credit'] = $parent['opening_credit']->add($child['opening_credit']);
            $parent['period_debit'] = $parent['period_debit']->add($child['period_debit']);
            $parent['period_credit'] = $parent['period_credit']->add($child['period_credit']);
            $parent['transaction_count'] += $child['transaction_count'];
            $parent['document_count'] += $child['document_count'];
            $parent['branches'] = $this->mergeBranchTotals($parent['branches'], $child['branches']);
            unset($parent);
        }

        $rows = [];
        foreach ($accounts as $account) {
            $m = $metrics[$account->id];
            $rows[] = AccountTurnoverRow::fromMetrics(
                $account,
                $m['opening_debit'],
                $m['opening_credit'],
                $m['period_debit'],
                $m['period_credit'],
                $m['transaction_count'],
                $m['document_count'],
                $m['branches'],
            );
        }

        return $rows;
    }

    /**
     * @param  list<array{branch_id: ?int, debit: string, credit: string}>  $left
     * @param  list<array{branch_id: ?int, debit: string, credit: string}>  $right
     * @return list<array{branch_id: ?int, debit: string, credit: string}>
     */
    private function mergeBranchTotals(array $left, array $right): array
    {
        $merged = [];
        foreach (array_merge($left, $right) as $row) {
            $key = $row['branch_id'] ?? 'none';
            $merged[$key] ??= ['branch_id' => $row['branch_id'], 'debit' => Amount::zero(), 'credit' => Amount::zero()];
            $merged[$key]['debit'] = Amount::of($merged[$key]['debit'])->add($row['debit']);
            $merged[$key]['credit'] = Amount::of($merged[$key]['credit'])->add($row['credit']);
        }

        return array_values(array_map(fn (array $row) => [
            'branch_id' => $row['branch_id'],
            'debit' => Amount::of($row['debit'])->toStorage(),
            'credit' => Amount::of($row['credit'])->toStorage(),
        ], $merged));
    }

    /**
     * @param  list<AccountTurnoverRow>  $rows
     * @return array<string, string>
     */
    private function totalsFromLeaves(array $rows, bool $rolledUp): array
    {
        $posting = AccountHierarchy::postingLevel();
        $source = $rolledUp
            ? array_values(array_filter($rows, fn (AccountTurnoverRow $row) => $row->level === $posting))
            : $rows;

        return $this->sumRows($source, onlyLeaves: false);
    }

    /**
     * @param  list<AccountTurnoverRow>  $rows
     * @return array<string, string>
     */
    private function sumRows(array $rows, bool $onlyLeaves): array
    {
        if ($onlyLeaves) {
            $posting = AccountHierarchy::postingLevel();
            $rows = array_values(array_filter($rows, fn (AccountTurnoverRow $row) => $row->level === $posting));
        }

        $openingDebit = Amount::zero();
        $openingCredit = Amount::zero();
        $periodDebit = Amount::zero();
        $periodCredit = Amount::zero();
        $closingDebit = Amount::zero();
        $closingCredit = Amount::zero();

        foreach ($rows as $row) {
            $openingDebit = $openingDebit->add($row->openingDebit);
            $openingCredit = $openingCredit->add($row->openingCredit);
            $periodDebit = $periodDebit->add($row->periodDebitTurnover);
            $periodCredit = $periodCredit->add($row->periodCreditTurnover);
            $closingDebit = $closingDebit->add($row->closingDebit);
            $closingCredit = $closingCredit->add($row->closingCredit);
        }

        return [
            'opening_debit' => $openingDebit->toStorage(),
            'opening_credit' => $openingCredit->toStorage(),
            'period_debit' => $periodDebit->toStorage(),
            'period_credit' => $periodCredit->toStorage(),
            'closing_debit' => $closingDebit->toStorage(),
            'closing_credit' => $closingCredit->toStorage(),
        ];
    }

    /**
     * @param  list<AccountTurnoverRow>  $rows
     * @return list<AccountTurnoverRow>
     */
    private function sortRows(array $rows, LedgerReportFilters $filters): array
    {
        usort($rows, function (AccountTurnoverRow $a, AccountTurnoverRow $b) use ($filters) {
            $direction = $filters->sortDirection === 'desc' ? -1 : 1;
            $left = $a->sortValue($filters->sortBy);
            $right = $b->sortValue($filters->sortBy);

            if (in_array($filters->sortBy, ['opening_balance', 'debit_turnover', 'credit_turnover', 'closing_balance'], true)) {
                $cmp = Amount::of($left)->compare($right);
            } else {
                $cmp = $left <=> $right;
            }

            if ($cmp !== 0) {
                return $cmp * $direction;
            }

            return $a->accountId <=> $b->accountId;
        });

        return $rows;
    }

    /**
     * Account-level pagination after aggregation. Account charts are bounded;
     * ledger lines are never loaded into PHP.
     *
     * @param  list<AccountTurnoverRow>  $rows
     * @return array{0: list<AccountTurnoverRow>, 1: PaginationMeta}
     */
    private function paginate(array $rows): array
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
                if ($row->accountId === $cursorId) {
                    $start = $index + 1;
                    break;
                }
            }
        }

        $slice = array_slice($rows, $start, $pagination->perPage + 1);
        $hasMore = count($slice) > $pagination->perPage;
        $slice = array_slice($slice, 0, $pagination->perPage);
        $next = $hasMore && $slice !== []
            ? CursorCodec::encode(['account_id' => $slice[array_key_last($slice)]->accountId])
            : null;
        $previous = $start > 0 && isset($rows[$start])
            ? CursorCodec::encode(['account_id' => $rows[max(0, $start - 1)]->accountId])
            : ($start > 0 ? CursorCodec::encode(['account_id' => $rows[$start - 1]->accountId]) : null);

        return [$slice, PaginationMeta::cursor($pagination->perPage, $hasMore, $next, $previous)];
    }
}
