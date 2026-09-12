<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Support\Amount;

/**
 * Reusable, deterministic query foundation for accounting reports.
 *
 * Always reads from the posted journal (acc_document_items JOIN acc_documents) —
 * never from Account::cached_balance, parent cached balances, or any operational
 * model. Trial Balance, General Ledger, Account Statement, Profit & Loss,
 * Balance Sheet, cash movements, and turnover all build on this same
 * object so they agree on filters and ordering.
 *
 * Ordering is always: documents.date, documents.number, documents.id,
 * document_items.order, document_items.id — never created_at.
 *
 * @example
 * LedgerQuery::make()
 *     ->forAccount($account)
 *     ->forFiscalYear($fiscalYear)
 *     ->from($from)
 *     ->to($to)
 *     ->branch($branchId)
 *     ->get();
 */
final class LedgerQuery
{
    /** @var list<int> */
    private array $accountIds = [];

    private ?int $fiscalYearId = null;

    private ?FiscalYear $fiscalYear = null;

    private ?string $from = null;

    private ?string $to = null;

    private ?int $branchId = null;

    private bool $branchFilterApplied = false;

    private ?int $costCenterId = null;

    private bool $costCenterFilterApplied = false;

    /** @var list<int> */
    private array $branchIds = [];

    private bool $allBranches = false;

    /** @var list<int> */
    private array $costCenterIds = [];

    /** @var list<string> */
    private array $excludedDocumentTypes = [];

    /** @var list<string> */
    private array $includedDocumentTypes = [];

    private ?int $documentNumberFrom = null;

    private ?int $documentNumberTo = null;

    private ?string $search = null;

    private ?string $minAmount = null;

    private ?string $maxAmount = null;

    private ?string $accountType = null;

    private ReportMode $mode = ReportMode::Financial;

    public static function make(): self
    {
        return new self;
    }

    public function forAccount(Account|int $account): self
    {
        $this->accountIds = [$account instanceof Account ? $account->id : (int) $account];

        return $this;
    }

    /** @param iterable<Account|int> $accounts */
    public function forAccounts(iterable $accounts): self
    {
        $this->accountIds = collect($accounts)
            ->map(fn ($account) => $account instanceof Account ? $account->id : (int) $account)
            ->values()
            ->all();

        return $this;
    }

    public function forFiscalYear(FiscalYear|int|null $fiscalYear): self
    {
        if ($fiscalYear === null) {
            $this->fiscalYear = null;
            $this->fiscalYearId = null;

            return $this;
        }

        if ($fiscalYear instanceof FiscalYear) {
            $this->fiscalYear = $fiscalYear;
            $this->fiscalYearId = $fiscalYear->id;
        } else {
            $this->fiscalYear = null;
            $this->fiscalYearId = $fiscalYear;
        }

        return $this;
    }

    /**
     * Constrain the report window to an AccountingPeriod's start/end dates.
     * Opening balance remains "posted activity before period start" (unchanged).
     */
    public function forAccountingPeriod(AccountingPeriod $period): self
    {
        $this->forFiscalYear($period->fiscal_year_id);
        $this->from($period->start_date);
        $this->to($period->end_date);

        return $this;
    }

    public function from(Carbon|string|null $date): self
    {
        $this->from = $date !== null ? Carbon::parse($date)->toDateString() : null;

        return $this;
    }

    public function to(Carbon|string|null $date): self
    {
        $this->to = $date !== null ? Carbon::parse($date)->toDateString() : null;

        return $this;
    }

    /** Filter by branch. Pass null to explicitly scope to documents without a branch. */
    public function branch(Model|int|null $branch): self
    {
        $this->branchId = $branch instanceof Model ? (int) $branch->getKey() : $branch;
        $this->branchFilterApplied = true;
        $this->branchIds = [];
        $this->allBranches = false;

        return $this;
    }

    /**
     * Restrict to an explicit set of branch ids. Empty list is rejected by BranchScope;
     * this method applies WHERE IN when called directly.
     *
     * @param  iterable<Model|int>  $branches
     */
    public function branches(iterable $branches): self
    {
        $this->branchIds = collect($branches)
            ->map(fn ($branch) => $branch instanceof Model ? (int) $branch->getKey() : (int) $branch)
            ->unique()
            ->values()
            ->all();
        $this->branchFilterApplied = true;
        $this->allBranches = false;
        $this->branchId = count($this->branchIds) === 1 ? $this->branchIds[0] : null;

        return $this;
    }

    /**
     * Explicit all-branches mode: no branch predicate is applied.
     * Host/tenant scope remains the caller's responsibility.
     */
    public function allBranches(): self
    {
        $this->allBranches = true;
        $this->branchFilterApplied = false;
        $this->branchId = null;
        $this->branchIds = [];

        return $this;
    }

    /** Filter by cost center. Pass null to explicitly scope to lines without a cost center. */
    public function costCenter(CostCenter|int|null $center): self
    {
        $this->costCenterId = $center instanceof CostCenter ? $center->id : ($center !== null ? (int) $center : null);
        $this->costCenterFilterApplied = true;
        $this->costCenterIds = [];

        return $this;
    }

    /**
     * @param  iterable<CostCenter|int>  $centers
     */
    public function costCenters(iterable $centers): self
    {
        $this->costCenterIds = collect($centers)
            ->map(fn ($center) => $center instanceof CostCenter ? $center->id : (int) $center)
            ->unique()
            ->values()
            ->all();
        $this->costCenterFilterApplied = true;
        $this->costCenterId = count($this->costCenterIds) === 1 ? $this->costCenterIds[0] : null;

        return $this;
    }

    public function includeDocumentTypes(string ...$types): self
    {
        $this->includedDocumentTypes = array_values(array_unique(array_filter($types, fn (string $type) => $type !== '')));

        return $this;
    }

    public function documentNumberFrom(?int $number): self
    {
        $this->documentNumberFrom = $number;

        return $this;
    }

    public function documentNumberTo(?int $number): self
    {
        $this->documentNumberTo = $number;

        return $this;
    }

    /**
     * Restrict listing/search to: document number (exact when numeric),
     * description, reference, account code, account title.
     */
    public function search(?string $term): self
    {
        $this->search = $term !== null && trim($term) !== '' ? trim($term) : null;

        return $this;
    }

    public function minAmount(int|float|string|null $amount): self
    {
        $this->minAmount = $amount !== null && $amount !== '' ? Amount::of($amount)->toStorage() : null;

        return $this;
    }

    public function maxAmount(int|float|string|null $amount): self
    {
        $this->maxAmount = $amount !== null && $amount !== '' ? Amount::of($amount)->toStorage() : null;

        return $this;
    }

    public function accountType(?string $type): self
    {
        $this->accountType = $type !== null && $type !== '' ? $type : null;

        return $this;
    }

    public function mode(ReportMode $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Documented no-op: the ledger is always posted-only. Voiding a document moves
     * its status away from 'posted', so a single status filter already excludes it.
     */
    public function postedOnly(): self
    {
        return $this;
    }

    /** @see postedOnly() */
    public function excludeVoided(): self
    {
        return $this;
    }

    /**
     * Drop posted documents of these types from both period and opening queries.
     * Profit & Loss uses this to ignore `closing` journals so year-end P&L stays a flow statement.
     */
    public function excludeDocumentTypes(string ...$types): self
    {
        $this->excludedDocumentTypes = array_values(array_unique(array_filter($types, fn (string $type) => $type !== '')));

        return $this;
    }

    /** @return list<string> */
    public function excludedDocumentTypes(): array
    {
        return $this->excludedDocumentTypes;
    }

    /** @return list<int> */
    public function accountIds(): array
    {
        return $this->accountIds;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function isBranchFilterApplied(): bool
    {
        return $this->branchFilterApplied;
    }

    public function costCenterId(): ?int
    {
        return $this->costCenterId;
    }

    public function isCostCenterFilterApplied(): bool
    {
        return $this->costCenterFilterApplied;
    }

    /** @return list<int> */
    public function branchIds(): array
    {
        if ($this->branchIds !== []) {
            return $this->branchIds;
        }

        if ($this->branchFilterApplied && $this->branchId !== null) {
            return [$this->branchId];
        }

        return [];
    }

    public function isAllBranches(): bool
    {
        return $this->allBranches;
    }

    /** @return list<int> */
    public function costCenterIds(): array
    {
        if ($this->costCenterIds !== []) {
            return $this->costCenterIds;
        }

        if ($this->costCenterFilterApplied && $this->costCenterId !== null) {
            return [$this->costCenterId];
        }

        return [];
    }

    /** @return list<string> */
    public function includedDocumentTypes(): array
    {
        return $this->includedDocumentTypes;
    }

    public function documentNumberFromValue(): ?int
    {
        return $this->documentNumberFrom;
    }

    public function documentNumberToValue(): ?int
    {
        return $this->documentNumberTo;
    }

    public function searchTerm(): ?string
    {
        return $this->search;
    }

    public function minAmountValue(): ?string
    {
        return $this->minAmount;
    }

    public function maxAmountValue(): ?string
    {
        return $this->maxAmount;
    }

    public function accountTypeValue(): ?string
    {
        return $this->accountType;
    }

    public function reportMode(): ReportMode
    {
        return $this->mode;
    }

    public function fiscalYearId(): ?int
    {
        return $this->fiscalYearId;
    }

    public function resolvedFiscalYear(): ?FiscalYear
    {
        if ($this->fiscalYear) {
            return $this->fiscalYear;
        }

        if ($this->fiscalYearId !== null) {
            return $this->fiscalYear = FiscalYear::find($this->fiscalYearId);
        }

        return null;
    }

    /** Explicit `from`, or the scoped fiscal year's start date when omitted. */
    public function resolvedFrom(): ?string
    {
        if ($this->from !== null) {
            return $this->from;
        }

        $fiscalYear = $this->resolvedFiscalYear();

        return $fiscalYear ? Carbon::parse($fiscalYear->start_date)->toDateString() : null;
    }

    /** Explicit `to`, or the scoped fiscal year's end date when omitted. */
    public function resolvedTo(): ?string
    {
        if ($this->to !== null) {
            return $this->to;
        }

        $fiscalYear = $this->resolvedFiscalYear();

        return $fiscalYear ? Carbon::parse($fiscalYear->end_date)->toDateString() : null;
    }

    /**
     * Journal-line query for the requested period: posted items joined to their
     * documents, scoped by account(s), branch, fiscal year and [from, to].
     */
    public function baseQuery(): QueryBuilder
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $query = DB::table($items)
            ->join($documents, "{$documents}.id", '=', "{$items}.document_id");

        $this->applySharedFilters($query, $items, $documents, financial: true);

        return $query;
    }

    /**
     * Document/line listing query. Financial mode stays posted-only.
     * Audit mode includes draft/pending/approved/voided rows for inspection
     * and must not be used for official financial totals.
     */
    public function listingQuery(): QueryBuilder
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $query = DB::table($items)
            ->join($documents, "{$documents}.id", '=', "{$items}.document_id");

        $this->applySharedFilters($query, $items, $documents, financial: $this->mode === ReportMode::Financial);

        return $query;
    }

    /**
     * Balance accumulated strictly before `from`.
     *
     * FY-scoped queries (`fiscalYearId` set) isolate opening to that same fiscal
     * year so prior years cannot leak into FY reports. Date-only queries keep
     * lifetime opening: posted + date < from, with no fiscal_year_id filter.
     */
    private function openingQuery(): QueryBuilder
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $query = DB::table($items)
            ->join($documents, "{$documents}.id", '=', "{$items}.document_id");

        $this->applySharedFilters($query, $items, $documents, financial: true, applyDates: false, applyListing: false);
        $query->whereDate("{$documents}.date", '<', $this->resolvedFrom() ?? '0001-01-01');

        return $query;
    }

    private function applySharedFilters(
        QueryBuilder $query,
        string $items,
        string $documents,
        bool $financial,
        bool $applyDates = true,
        bool $applyListing = true
    ): void {
        if ($financial) {
            $query->where("{$documents}.status", DocumentStatus::POSTED->value);
        }

        $this->applyAccountFilter($query, $items);
        $this->applyAccountTypeFilter($query, $items);
        $this->applyBranchFilter($query, $documents);
        $this->applyCostCenterFilter($query, $items);
        $this->applyDocumentTypeExclusion($query, $documents);
        $this->applyDocumentTypeInclusion($query, $documents);

        if ($applyListing) {
            $this->applyDocumentNumberFilter($query, $documents);
            $this->applySearchFilter($query, $items, $documents);
        }

        if ($applyDates) {
            $from = $this->resolvedFrom();
            $to = $this->resolvedTo();

            if ($from !== null) {
                $query->whereDate("{$documents}.date", '>=', $from);
            }

            if ($to !== null) {
                $query->whereDate("{$documents}.date", '<=', $to);
            }
        }

        if ($this->fiscalYearId !== null) {
            $query->where("{$documents}.fiscal_year_id", $this->fiscalYearId);
        }
    }

    private function applyAccountFilter(QueryBuilder $query, string $items): void
    {
        if ($this->accountIds !== []) {
            $query->whereIn("{$items}.account_id", $this->accountIds);
        }
    }

    private function applyAccountTypeFilter(QueryBuilder $query, string $items): void
    {
        if ($this->accountType === null) {
            return;
        }

        $accounts = (new Account)->getTable();
        $this->ensureAccountJoin($query, $items, $accounts);
        $query->where("{$accounts}.type", $this->accountType);
    }

    private function applyBranchFilter(QueryBuilder $query, string $documents): void
    {
        if ($this->allBranches || ! $this->branchFilterApplied) {
            return;
        }

        if ($this->branchIds !== []) {
            $query->whereIn("{$documents}.branch_id", $this->branchIds);

            return;
        }

        if ($this->branchId === null) {
            $query->whereNull("{$documents}.branch_id");
        } else {
            $query->where("{$documents}.branch_id", $this->branchId);
        }
    }

    private function applyDocumentTypeExclusion(QueryBuilder $query, string $documents): void
    {
        if ($this->excludedDocumentTypes === []) {
            return;
        }

        $query->whereNotIn("{$documents}.type", $this->excludedDocumentTypes);
    }

    private function applyDocumentTypeInclusion(QueryBuilder $query, string $documents): void
    {
        if ($this->includedDocumentTypes === []) {
            return;
        }

        $query->whereIn("{$documents}.type", $this->includedDocumentTypes);
    }

    private function applyDocumentNumberFilter(QueryBuilder $query, string $documents): void
    {
        if ($this->documentNumberFrom !== null) {
            $query->where("{$documents}.number", '>=', $this->documentNumberFrom);
        }

        if ($this->documentNumberTo !== null) {
            $query->where("{$documents}.number", '<=', $this->documentNumberTo);
        }
    }

    private function applySearchFilter(QueryBuilder $query, string $items, string $documents): void
    {
        if ($this->search === null) {
            return;
        }

        $accounts = (new Account)->getTable();
        $this->ensureAccountJoin($query, $items, $accounts);
        $like = '%'.$this->escapeLike($this->search).'%';

        $query->where(function (QueryBuilder $nested) use ($documents, $accounts, $like) {
            $nested->where("{$documents}.description", 'like', $like)
                ->orWhere("{$documents}.reference", 'like', $like)
                ->orWhere("{$accounts}.code", 'like', $like)
                ->orWhere("{$accounts}.title", 'like', $like);

            if (preg_match('/^\d+$/', $this->search) === 1) {
                $nested->orWhere("{$documents}.number", (int) $this->search);
            }
        });
    }

    private function applyCostCenterFilter(QueryBuilder $query, string $items): void
    {
        if (! $this->costCenterFilterApplied) {
            return;
        }

        if ($this->costCenterIds !== []) {
            $query->whereIn("{$items}.cost_center_id", $this->costCenterIds);

            return;
        }

        if ($this->costCenterId === null) {
            $query->whereNull("{$items}.cost_center_id");
        } else {
            $query->where("{$items}.cost_center_id", $this->costCenterId);
        }
    }

    private function ensureAccountJoin(QueryBuilder $query, string $items, string $accounts): void
    {
        $joins = $query->joins ?? [];
        foreach ($joins as $join) {
            if (isset($join->table) && $join->table === $accounts) {
                return;
            }
        }

        $query->leftJoin($accounts, "{$accounts}.id", '=', "{$items}.account_id");
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Net opening balance (debit - credit) per account, before `from`.
     *
     * @return array<int, float>
     */
    public function openingBalances(): array
    {
        $balances = array_fill_keys($this->accountIds, 0.0);

        if ($this->resolvedFrom() === null) {
            return $balances;
        }

        $items = (new DocumentItem)->getTable();

        $rows = $this->openingQuery()
            ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit - {$items}.credit), 0) as balance")
            ->groupBy("{$items}.account_id")
            ->get();

        foreach ($rows as $row) {
            $balances[(int) $row->account_id] = Amount::of($row->balance)->toFloat();
        }

        return $balances;
    }

    /**
     * Debit/credit totals for the [from, to] period, per account.
     *
     * @return array<int, array{debit: float, credit: float}>
     */
    public function periodTotalsByAccount(): array
    {
        $items = (new DocumentItem)->getTable();

        $totals = array_fill_keys($this->accountIds, ['debit' => 0.0, 'credit' => 0.0]);

        $rows = $this->baseQuery()
            ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit")
            ->groupBy("{$items}.account_id")
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->account_id] = [
                'debit' => Amount::of($row->debit)->toFloat(),
                'credit' => Amount::of($row->credit)->toFloat(),
            ];
        }

        return $totals;
    }

    /**
     * Debit/credit/balance for the whole scoped period (every matching account combined).
     *
     * @return array{debit: float, credit: float, balance: float}
     */
    public function periodTotals(): array
    {
        $items = (new DocumentItem)->getTable();

        $row = $this->baseQuery()
            ->selectRaw("COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit")
            ->first();

        $debit = Amount::of($row->debit ?? 0);
        $credit = Amount::of($row->credit ?? 0);

        return [
            'debit' => $debit->toFloat(),
            'credit' => $credit->toFloat(),
            'balance' => $debit->subtract($credit)->toFloat(),
        ];
    }

    /**
     * Per-account opening/period debit & credit — the Trial Balance building block.
     * Only L3 (posting-level) accounts ever appear here, because only they can
     * carry document_items under the package's detail-only posting invariant.
     *
     * @return array<int, array{opening_debit: float, opening_credit: float, period_debit: float, period_credit: float}>
     */
    public function trialBalanceAggregates(): array
    {
        $result = [];

        if ($this->resolvedFrom() !== null) {
            $items = (new DocumentItem)->getTable();

            $openingRows = $this->openingQuery()
                ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit), 0) as opening_debit, COALESCE(SUM({$items}.credit), 0) as opening_credit")
                ->groupBy("{$items}.account_id")
                ->get();

            foreach ($openingRows as $row) {
                $result[(int) $row->account_id]['opening_debit'] = Amount::of($row->opening_debit)->toFloat();
                $result[(int) $row->account_id]['opening_credit'] = Amount::of($row->opening_credit)->toFloat();
            }
        }

        foreach ($this->periodTotalsByAccount() as $accountId => $totals) {
            $result[$accountId]['period_debit'] = $totals['debit'];
            $result[$accountId]['period_credit'] = $totals['credit'];
        }

        foreach ($result as $accountId => $row) {
            $result[$accountId] = [
                'opening_debit' => $row['opening_debit'] ?? 0.0,
                'opening_credit' => $row['opening_credit'] ?? 0.0,
                'period_debit' => $row['period_debit'] ?? 0.0,
                'period_credit' => $row['period_credit'] ?? 0.0,
            ];
        }

        return $result;
    }

    /**
     * Period activity plus transaction/document counts per account (SQL aggregation).
     *
     * @return array<int, array{debit: string, credit: string, transaction_count: int, document_count: int}>
     */
    public function periodActivityByAccount(): array
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $rows = $this->baseQuery()
            ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit, COUNT({$items}.id) as transaction_count, COUNT(DISTINCT {$documents}.id) as document_count")
            ->groupBy("{$items}.account_id")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->account_id] = [
                'debit' => Amount::of($row->debit)->toStorage(),
                'credit' => Amount::of($row->credit)->toStorage(),
                'transaction_count' => (int) $row->transaction_count,
                'document_count' => (int) $row->document_count,
            ];
        }

        return $result;
    }

    /**
     * Opening debit/credit per account as decimal strings.
     *
     * @return array<int, array{opening_debit: string, opening_credit: string}>
     */
    public function openingDebitCreditByAccount(): array
    {
        $result = [];

        if ($this->resolvedFrom() === null) {
            return $result;
        }

        $items = (new DocumentItem)->getTable();
        $rows = $this->openingQuery()
            ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit), 0) as opening_debit, COALESCE(SUM({$items}.credit), 0) as opening_credit")
            ->groupBy("{$items}.account_id")
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->account_id] = [
                'opening_debit' => Amount::of($row->opening_debit)->toStorage(),
                'opening_credit' => Amount::of($row->opening_credit)->toStorage(),
            ];
        }

        return $result;
    }

    /**
     * Period debit/credit per branch (documents.branch_id). Used for breakdown, not row duplication.
     *
     * @return array<int|string, array{branch_id: ?int, debit: string, credit: string}>
     */
    public function periodTotalsByBranch(): array
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $rows = $this->baseQuery()
            ->selectRaw("{$documents}.branch_id as branch_id, COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit")
            ->groupBy("{$documents}.branch_id")
            ->orderBy("{$documents}.branch_id")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $branchId = $row->branch_id === null ? null : (int) $row->branch_id;
            $key = $branchId ?? 'none';
            $result[$key] = [
                'branch_id' => $branchId,
                'debit' => Amount::of($row->debit)->toStorage(),
                'credit' => Amount::of($row->credit)->toStorage(),
            ];
        }

        return $result;
    }

    /**
     * Period debit/credit per account and branch.
     *
     * @return array<int, array<int|string, array{debit: string, credit: string}>>
     */
    public function periodTotalsByAccountAndBranch(): array
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $rows = $this->baseQuery()
            ->selectRaw("{$items}.account_id as account_id, {$documents}.branch_id as branch_id, COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit")
            ->groupBy("{$items}.account_id", "{$documents}.branch_id")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $branchKey = $row->branch_id === null ? 'none' : (int) $row->branch_id;
            $result[(int) $row->account_id][$branchKey] = [
                'debit' => Amount::of($row->debit)->toStorage(),
                'credit' => Amount::of($row->credit)->toStorage(),
            ];
        }

        return $result;
    }

    /**
     * Period totals as decimal strings (financial / posted only).
     *
     * @return array{debit: string, credit: string, line_count: int, document_count: int}
     */
    public function periodTotalsExact(): array
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $row = $this->baseQuery()
            ->selectRaw("COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit, COUNT({$items}.id) as line_count, COUNT(DISTINCT {$documents}.id) as document_count")
            ->first();

        return [
            'debit' => Amount::of($row->debit ?? 0)->toStorage(),
            'credit' => Amount::of($row->credit ?? 0)->toStorage(),
            'line_count' => (int) ($row->line_count ?? 0),
            'document_count' => (int) ($row->document_count ?? 0),
        ];
    }

    /**
     * Deterministically ordered journal lines for the scoped period.
     *
     * @return Collection<int, LedgerLine>
     */
    public function get(): Collection
    {
        return collect($this->orderedDetailQuery()->get())
            ->map(fn ($row) => LedgerLine::fromRow($row));
    }

    /**
     * Lazily stream journal lines without materializing the whole result set —
     * use for wide date ranges / whole-fiscal-year General Ledger scans.
     *
     * @return LazyCollection<int, LedgerLine>
     */
    public function cursor(): LazyCollection
    {
        return LazyCollection::make(function () {
            foreach ($this->orderedDetailQuery()->cursor() as $row) {
                yield LedgerLine::fromRow($row);
            }
        });
    }

    /** Total journal lines in the scoped period (posted, ordered scope). */
    public function countLines(): int
    {
        $items = (new DocumentItem)->getTable();

        return (int) $this->baseQuery()->count("{$items}.id");
    }

    /**
     * Sum of signed amounts (debit - credit) for the first $offset ordered lines.
     * Used to resume runningBalance mid-statement without loading prior pages.
     */
    public function prefixSignedSum(int $offset): float
    {
        if ($offset <= 0) {
            return 0.0;
        }

        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $sub = $this->baseQuery()
            ->select(["{$items}.debit", "{$items}.credit"])
            ->orderBy("{$documents}.date")
            ->orderBy("{$documents}.number")
            ->orderBy("{$documents}.id")
            ->orderBy("{$items}.order")
            ->orderBy("{$items}.id")
            ->limit($offset);

        $row = DB::query()
            ->fromSub($sub, 'skipped')
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as prefix')
            ->first();

        return Amount::of($row->prefix ?? 0)->toFloat();
    }

    /**
     * One page of deterministically ordered journal lines.
     *
     * @return Collection<int, LedgerLine>
     */
    public function pageLines(int $offset, int $limit): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        return collect($this->orderedDetailQuery()->offset(max(0, $offset))->limit($limit)->get())
            ->map(fn ($row) => LedgerLine::fromRow($row));
    }

    private function orderedDetailQuery(): QueryBuilder
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        return $this->baseQuery()
            ->select([
                "{$items}.id as item_id",
                "{$items}.document_id",
                "{$items}.account_id",
                "{$items}.cost_center_id",
                "{$items}.debit",
                "{$items}.credit",
                "{$items}.order as item_order",
                "{$documents}.number as document_number",
                "{$documents}.type as document_type",
                "{$documents}.description as document_description",
                "{$documents}.reference",
                "{$documents}.source_type",
                "{$documents}.source_id",
                "{$documents}.date",
                "{$documents}.fiscal_year_id",
                "{$documents}.branch_id",
            ])
            ->orderBy("{$documents}.date")
            ->orderBy("{$documents}.number")
            ->orderBy("{$documents}.id")
            ->orderBy("{$items}.order")
            ->orderBy("{$items}.id");
    }
}
