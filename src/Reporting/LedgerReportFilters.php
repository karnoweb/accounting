<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Carbon\Carbon;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Karnoweb\Accounting\Support\Amount;

/**
 * Immutable validated filter contract shared by advanced reports.
 *
 * Date policy: inclusive [from_date, to_date]. When an accounting period is
 * selected, its start/end become the effective range unless a narrower date
 * range entirely inside the period is also supplied. Dates outside the period
 * or fiscal year are rejected — never silently clipped.
 *
 * Search (detailed reports) matches only:
 * document number (exact when numeric), document description, reference,
 * account code, account name. No unbounded wildcard over other columns.
 */
final class LedgerReportFilters
{
    /** @var list<string> */
    public const TURNOVER_SORTS = [
        'account_code',
        'account_name',
        'opening_balance',
        'debit_turnover',
        'credit_turnover',
        'closing_balance',
    ];

    /** @var list<string> */
    public const JOURNAL_SORTS = [
        'document_date',
        'document_number',
        'document_id',
    ];

    /** @var list<string> */
    public const DAILY_GROUPS = [
        'day',
        'day_branch',
        'day_account',
        'day_document_type',
    ];

    /**
     * @param  list<int>  $accountIds
     * @param  list<int>  $costCenterIds
     * @param  list<string>  $documentTypes
     */
    private function __construct(
        public readonly ?string $fromDate,
        public readonly ?string $toDate,
        public readonly ?int $fiscalYearId,
        public readonly ?int $accountingPeriodId,
        public readonly BranchScope $branchScope,
        public readonly array $accountIds,
        public readonly ?string $accountType,
        public readonly array $costCenterIds,
        public readonly array $documentTypes,
        public readonly ?int $documentNumberFrom,
        public readonly ?int $documentNumberTo,
        public readonly ?string $search,
        public readonly ?string $minAmount,
        public readonly ?string $maxAmount,
        public readonly bool $includeZeroActivity,
        public readonly bool $includeZeroBalance,
        public readonly bool $includeChildren,
        public readonly bool $rollupHierarchy,
        public readonly bool $leafOnly,
        public readonly bool $postedOnly,
        public readonly ReportMode $mode,
        public readonly string $sortBy,
        public readonly string $sortDirection,
        public readonly string $groupBy,
        public readonly string $paginationGranularity,
        public readonly ?FiscalYear $fiscalYear,
        public readonly ?AccountingPeriod $accountingPeriod,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input): self
    {
        $fiscalYear = self::resolveFiscalYear($input['fiscal_year_id'] ?? null);
        $period = self::resolvePeriod($input['accounting_period_id'] ?? $input['period_id'] ?? null);

        if ($fiscalYear && $period && (int) $period->fiscal_year_id !== (int) $fiscalYear->id) {
            throw new InvalidReportFilterException(
                'accounting_period_id does not belong to the selected fiscal year.'
            );
        }

        if ($period && ! $fiscalYear) {
            $fiscalYear = $period->fiscalYear ?? FiscalYear::query()->find($period->fiscal_year_id);
        }

        [$from, $to] = self::resolveDates($input, $fiscalYear, $period);

        $accountIds = self::normalizeIds($input['account_ids'] ?? null, 'account_ids');
        if (isset($input['account_id']) && $input['account_id'] !== null && $input['account_id'] !== '') {
            $accountIds[] = self::positiveInt($input['account_id'], 'account_id');
        }
        $accountIds = array_values(array_unique($accountIds));
        self::assertAccountsExist($accountIds);

        $includeChildren = filter_var($input['include_children'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($includeChildren && $accountIds !== []) {
            $accountIds = self::expandAccountSubtree($accountIds);
        }

        $accountType = self::resolveAccountType($input['account_type'] ?? $input['account_category'] ?? null);

        $costCenterIds = self::normalizeIds($input['cost_center_ids'] ?? null, 'cost_center_ids');
        if (isset($input['cost_center_id']) && $input['cost_center_id'] !== null && $input['cost_center_id'] !== '') {
            $costCenterIds[] = self::positiveInt($input['cost_center_id'], 'cost_center_id');
        }
        $costCenterIds = array_values(array_unique($costCenterIds));
        self::assertCostCentersExist($costCenterIds);

        $documentTypes = self::normalizeDocumentTypes($input);
        [$numberFrom, $numberTo] = self::resolveDocumentNumbers($input);
        [$minAmount, $maxAmount] = self::resolveAmounts($input);

        $search = isset($input['search']) ? trim((string) $input['search']) : '';
        $search = $search === '' ? null : $search;

        $mode = self::resolveMode($input['mode'] ?? null);
        $postedOnly = array_key_exists('posted_only', $input)
            ? filter_var($input['posted_only'], FILTER_VALIDATE_BOOLEAN)
            : $mode === ReportMode::Financial;

        $sortBy = (string) ($input['sort_by'] ?? 'account_code');
        $sortDirection = strtolower((string) ($input['sort_direction'] ?? 'asc'));
        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new InvalidReportFilterException('sort_direction must be asc or desc.');
        }

        $groupBy = (string) ($input['group_by'] ?? 'day');
        if (! in_array($groupBy, self::DAILY_GROUPS, true)) {
            throw new InvalidReportFilterException('Unsupported group_by. Allowed: '.implode(', ', self::DAILY_GROUPS).'.');
        }

        $granularity = (string) ($input['pagination_granularity'] ?? 'document');
        if (! in_array($granularity, ['document', 'line'], true)) {
            throw new InvalidReportFilterException('pagination_granularity must be document or line.');
        }

        return new self(
            fromDate: $from,
            toDate: $to,
            fiscalYearId: $fiscalYear?->id,
            accountingPeriodId: $period?->id,
            branchScope: BranchScope::fromInput($input),
            accountIds: $accountIds,
            accountType: $accountType,
            costCenterIds: $costCenterIds,
            documentTypes: $documentTypes,
            documentNumberFrom: $numberFrom,
            documentNumberTo: $numberTo,
            search: $search,
            minAmount: $minAmount,
            maxAmount: $maxAmount,
            includeZeroActivity: filter_var($input['include_zero_activity'] ?? false, FILTER_VALIDATE_BOOLEAN),
            includeZeroBalance: filter_var($input['include_zero_balance'] ?? false, FILTER_VALIDATE_BOOLEAN),
            includeChildren: $includeChildren,
            rollupHierarchy: filter_var($input['rollup_hierarchy'] ?? false, FILTER_VALIDATE_BOOLEAN),
            leafOnly: filter_var($input['leaf_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            postedOnly: $postedOnly,
            mode: $mode,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
            groupBy: $groupBy,
            paginationGranularity: $granularity,
            fiscalYear: $fiscalYear,
            accountingPeriod: $period,
        );
    }

    /** @param  list<string>  $allowed */
    public function assertSort(array $allowed): self
    {
        if (! in_array($this->sortBy, $allowed, true)) {
            throw new InvalidReportFilterException(
                'Unsupported sort_by. Allowed: '.implode(', ', $allowed).'.'
            );
        }

        return $this;
    }

    public function withSort(string $sortBy, string $sortDirection): self
    {
        return new self(
            fromDate: $this->fromDate,
            toDate: $this->toDate,
            fiscalYearId: $this->fiscalYearId,
            accountingPeriodId: $this->accountingPeriodId,
            branchScope: $this->branchScope,
            accountIds: $this->accountIds,
            accountType: $this->accountType,
            costCenterIds: $this->costCenterIds,
            documentTypes: $this->documentTypes,
            documentNumberFrom: $this->documentNumberFrom,
            documentNumberTo: $this->documentNumberTo,
            search: $this->search,
            minAmount: $this->minAmount,
            maxAmount: $this->maxAmount,
            includeZeroActivity: $this->includeZeroActivity,
            includeZeroBalance: $this->includeZeroBalance,
            includeChildren: $this->includeChildren,
            rollupHierarchy: $this->rollupHierarchy,
            leafOnly: $this->leafOnly,
            postedOnly: $this->postedOnly,
            mode: $this->mode,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
            groupBy: $this->groupBy,
            paginationGranularity: $this->paginationGranularity,
            fiscalYear: $this->fiscalYear,
            accountingPeriod: $this->accountingPeriod,
        );
    }

    public function toLedgerQuery(): LedgerQuery
    {
        $query = LedgerQuery::make();

        if ($this->fiscalYear) {
            $query->forFiscalYear($this->fiscalYear);
        }

        if ($this->fromDate !== null) {
            $query->from($this->fromDate);
        }

        if ($this->toDate !== null) {
            $query->to($this->toDate);
        }

        $this->branchScope->apply($query);

        if ($this->accountIds !== []) {
            $query->forAccounts($this->accountIds);
        }

        if ($this->accountType !== null) {
            $query->accountType($this->accountType);
        }

        if ($this->costCenterIds !== []) {
            $query->costCenters($this->costCenterIds);
        }

        if ($this->documentTypes !== []) {
            $query->includeDocumentTypes(...$this->documentTypes);
        }

        $query->documentNumberFrom($this->documentNumberFrom);
        $query->documentNumberTo($this->documentNumberTo);
        $query->search($this->search);
        $query->minAmount($this->minAmount);
        $query->maxAmount($this->maxAmount);
        $query->mode($this->mode);

        if ($this->postedOnly || $this->mode === ReportMode::Financial) {
            $query->postedOnly();
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'fiscal_year_id' => $this->fiscalYearId,
            'accounting_period_id' => $this->accountingPeriodId,
            'period_id' => $this->accountingPeriodId,
            'branch_id' => $this->branchScope->branchId,
            'branch_ids' => $this->branchScope->branchIds,
            'all_branches' => $this->branchScope->mode === BranchScope::MODE_ALL,
            'include_branch_breakdown' => $this->branchScope->includeBreakdown,
            'account_ids' => $this->accountIds,
            'account_type' => $this->accountType,
            'cost_center_ids' => $this->costCenterIds,
            'document_types' => $this->documentTypes,
            'document_number_from' => $this->documentNumberFrom,
            'document_number_to' => $this->documentNumberTo,
            'search' => $this->search,
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'include_zero_activity' => $this->includeZeroActivity,
            'include_zero_balance' => $this->includeZeroBalance,
            'include_children' => $this->includeChildren,
            'rollup_hierarchy' => $this->rollupHierarchy,
            'leaf_only' => $this->leafOnly,
            'posted_only' => $this->postedOnly,
            'mode' => $this->mode->value,
            'sort_by' => $this->sortBy,
            'sort_direction' => $this->sortDirection,
            'group_by' => $this->groupBy,
            'pagination_granularity' => $this->paginationGranularity,
        ];
    }

    private static function resolveFiscalYear(mixed $id): ?FiscalYear
    {
        if ($id === null || $id === '') {
            return null;
        }

        $fiscalYear = FiscalYear::query()->find((int) $id);
        if (! $fiscalYear) {
            throw new InvalidReportFilterException('fiscal_year_id does not exist.');
        }

        return $fiscalYear;
    }

    private static function resolvePeriod(mixed $id): ?AccountingPeriod
    {
        if ($id === null || $id === '') {
            return null;
        }

        $period = AccountingPeriod::query()->find((int) $id);
        if (! $period) {
            throw new InvalidReportFilterException('accounting_period_id does not exist.');
        }

        return $period;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolveDates(array $input, ?FiscalYear $fiscalYear, ?AccountingPeriod $period): array
    {
        $from = self::normalizeDate($input['from_date'] ?? $input['from'] ?? null, 'from_date');
        $to = self::normalizeDate($input['to_date'] ?? $input['to'] ?? null, 'to_date');

        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidReportFilterException('from_date must be less than or equal to to_date.');
        }

        if ($period) {
            $periodStart = Carbon::parse($period->start_date)->toDateString();
            $periodEnd = Carbon::parse($period->end_date)->toDateString();

            if ($from === null && $to === null) {
                $from = $periodStart;
                $to = $periodEnd;
            } else {
                $from ??= $periodStart;
                $to ??= $periodEnd;
                if ($from < $periodStart || $to > $periodEnd) {
                    throw new InvalidReportFilterException(
                        'Explicit date range must stay inside the selected accounting period.'
                    );
                }
            }
        }

        if ($fiscalYear) {
            $fyStart = Carbon::parse($fiscalYear->start_date)->toDateString();
            $fyEnd = Carbon::parse($fiscalYear->end_date)->toDateString();

            if ($from !== null && ($from < $fyStart || $from > $fyEnd)) {
                throw new InvalidReportFilterException('from_date is outside the selected fiscal year.');
            }
            if ($to !== null && ($to < $fyStart || $to > $fyEnd)) {
                throw new InvalidReportFilterException('to_date is outside the selected fiscal year.');
            }
        }

        return [$from, $to];
    }

    private static function normalizeDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidReportFilterException("{$field} is not a valid date.");
        }
    }

    /** @return list<int> */
    private static function normalizeIds(mixed $raw, string $field): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (! is_iterable($raw)) {
            throw new InvalidReportFilterException("{$field} must be an array of ids.");
        }

        $ids = [];
        foreach ($raw as $value) {
            $ids[] = self::positiveInt($value, $field);
        }

        return $ids;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidReportFilterException("{$field} must contain positive integers.");
        }

        return (int) $value;
    }

    /** @param  list<int>  $ids */
    private static function assertAccountsExist(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $found = Account::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($ids, $found));
        if ($missing !== []) {
            throw new InvalidReportFilterException('Unknown account id(s): '.implode(', ', $missing).'.');
        }
    }

    /** @param  list<int>  $ids */
    private static function assertCostCentersExist(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $found = CostCenter::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($ids, $found));
        if ($missing !== []) {
            throw new InvalidReportFilterException('Unknown cost_center id(s): '.implode(', ', $missing).'.');
        }
    }

    /** @param  list<int>  $ids @return list<int> */
    private static function expandAccountSubtree(array $ids): array
    {
        $accounts = Account::query()->select(['id', 'parent_id'])->get();
        $children = [];
        foreach ($accounts as $account) {
            if ($account->parent_id === null) {
                continue;
            }
            $children[(int) $account->parent_id][] = (int) $account->id;
        }

        $expanded = [];
        $stack = $ids;
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($expanded[$id])) {
                continue;
            }
            $expanded[$id] = true;
            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_map('intval', array_keys($expanded));
    }

    private static function resolveAccountType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $type = is_string($value) ? strtolower($value) : (string) $value;
        if (! in_array($type, AccountType::values(), true)) {
            throw new InvalidReportFilterException('Unsupported account_type.');
        }

        return $type;
    }

    /** @return list<string> */
    private static function normalizeDocumentTypes(array $input): array
    {
        $types = [];
        if (isset($input['document_type']) && $input['document_type'] !== null && $input['document_type'] !== '') {
            $types[] = (string) $input['document_type'];
        }
        if (isset($input['document_types']) && is_iterable($input['document_types'])) {
            foreach ($input['document_types'] as $type) {
                $types[] = (string) $type;
            }
        }

        $types = array_values(array_unique(array_filter($types, fn (string $type) => $type !== '')));
        $allowed = config('accounting.document.allowed_types', []);
        if (is_array($allowed) && $allowed !== []) {
            foreach ($types as $type) {
                if (! in_array($type, $allowed, true)) {
                    throw new InvalidReportFilterException("Unsupported document_type: {$type}.");
                }
            }
        }

        return $types;
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function resolveDocumentNumbers(array $input): array
    {
        $from = $input['document_number_from'] ?? null;
        $to = $input['document_number_to'] ?? null;
        $from = $from === null || $from === '' ? null : (int) $from;
        $to = $to === null || $to === '' ? null : (int) $to;

        if ($from !== null && $from < 0) {
            throw new InvalidReportFilterException('document_number_from must be >= 0.');
        }
        if ($to !== null && $to < 0) {
            throw new InvalidReportFilterException('document_number_to must be >= 0.');
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidReportFilterException('document_number_from must be <= document_number_to.');
        }

        return [$from, $to];
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function resolveAmounts(array $input): array
    {
        $min = $input['min_amount'] ?? null;
        $max = $input['max_amount'] ?? null;

        try {
            $minAmount = $min === null || $min === '' ? null : Amount::of($min)->toStorage();
            $maxAmount = $max === null || $max === '' ? null : Amount::of($max)->toStorage();
        } catch (\Throwable $e) {
            throw new InvalidReportFilterException('min_amount / max_amount must be valid decimal amounts.', 422, $e);
        }

        if ($minAmount !== null && $maxAmount !== null && Amount::of($minAmount)->compare($maxAmount) > 0) {
            throw new InvalidReportFilterException('min_amount must be <= max_amount.');
        }

        return [$minAmount, $maxAmount];
    }

    private static function resolveMode(mixed $value): ReportMode
    {
        if ($value === null || $value === '') {
            return ReportMode::Financial;
        }

        $mode = ReportMode::tryFrom((string) $value);
        if (! $mode) {
            throw new InvalidReportFilterException('mode must be financial or audit.');
        }

        return $mode;
    }

    public function postingLevelOnly(): bool
    {
        return $this->leafOnly || ! $this->rollupHierarchy;
    }

    public function postingLevel(): int
    {
        return AccountHierarchy::postingLevel();
    }
}
