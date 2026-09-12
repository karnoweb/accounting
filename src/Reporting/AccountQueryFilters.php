<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Karnoweb\Accounting\Exceptions\InvalidAccountHierarchyException;
use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Support\AccountHierarchy;

/**
 * Validated catalog filters for hierarchy and paginated account listing.
 *
 * `level` is the public 1-based level (Level 1..4). Stored `accounts.level`
 * remains 0-based and is never renamed.
 */
final class AccountQueryFilters
{
    /** @var list<string> */
    public const SORTS = ['code', 'name', 'id'];

    /**
     * @param  list<int>|null  $parentIds
     */
    private function __construct(
        public readonly ?int $displayLevel,
        public readonly ?int $parentId,
        public readonly ?string $search,
        public readonly ?string $code,
        public readonly ?bool $isActive,
        public readonly BranchScope $branchScope,
        public readonly string $sortBy,
        public readonly string $sortDirection,
        public readonly ?Account $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input, bool $requireLevel = false): self
    {
        $displayLevel = self::parseDisplayLevel($input['level'] ?? null, $requireLevel);
        $parent = self::resolveParent($input['parent_id'] ?? null);

        if ($displayLevel !== null && $parent !== null) {
            $expectedParentStored = AccountHierarchy::storedLevel($displayLevel) - 1;
            if ($expectedParentStored < 0) {
                throw new InvalidAccountHierarchyException('Level 1 accounts do not accept parent_id.');
            }
            if ((int) $parent->level !== $expectedParentStored) {
                throw new InvalidAccountHierarchyException(
                    'parent_id must belong to the previous public level.'
                );
            }
        }

        $search = isset($input['search']) ? trim((string) $input['search']) : '';
        $search = $search === '' ? null : $search;

        $code = isset($input['code']) ? trim((string) $input['code']) : '';
        $code = $code === '' ? null : $code;

        $isActive = array_key_exists('is_active', $input) && $input['is_active'] !== null && $input['is_active'] !== ''
            ? filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN)
            : null;

        $sortBy = (string) ($input['sort_by'] ?? 'code');
        if ($sortBy === 'account_code') {
            $sortBy = 'code';
        }
        if ($sortBy === 'account_name') {
            $sortBy = 'name';
        }
        if (! in_array($sortBy, self::SORTS, true)) {
            throw new InvalidReportFilterException(
                'Unsupported sort_by. Allowed: '.implode(', ', self::SORTS).'.'
            );
        }

        $sortDirection = strtolower((string) ($input['sort_direction'] ?? 'asc'));
        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new InvalidReportFilterException('sort_direction must be asc or desc.');
        }

        return new self(
            displayLevel: $displayLevel,
            parentId: $parent?->id,
            search: $search,
            code: $code,
            isActive: $isActive,
            branchScope: BranchScope::fromInput($input),
            sortBy: $sortBy,
            sortDirection: $sortDirection,
            parent: $parent,
        );
    }

    public static function parseHierarchyMaxLevel(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return AccountHierarchy::hierarchyMaxDisplayLevel();
        }

        if (! is_numeric($raw) || (int) $raw != $raw) {
            throw new InvalidAccountHierarchyException('max_level must be 1, 2, or 3.');
        }

        $max = (int) $raw;
        $allowed = AccountHierarchy::hierarchyMaxDisplayLevel();
        if ($max < 1 || $max > $allowed) {
            throw new InvalidAccountHierarchyException(
                "max_level cannot exceed {$allowed}; Level ".($allowed + 1).' is never included in the hierarchy tree.'
            );
        }

        return $max;
    }

    public function storedLevel(): ?int
    {
        return $this->displayLevel === null ? null : AccountHierarchy::storedLevel($this->displayLevel);
    }

    public function sortColumn(): string
    {
        return match ($this->sortBy) {
            'name' => 'title',
            'id' => 'id',
            default => 'code',
        };
    }

    /**
     * Apply catalog predicates to an Eloquent account query (soft-deletes respected).
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function applyToAccountQuery(Builder $query): Builder
    {
        $stored = $this->storedLevel();
        if ($stored !== null) {
            $query->where('level', $stored);
        }

        if ($this->parentId !== null) {
            $query->where('parent_id', $this->parentId);
        }

        if ($this->code !== null) {
            $query->where('code', $this->code);
        }

        if ($this->search !== null) {
            $likeContains = '%'.self::escapeLike($this->search).'%';
            $likePrefix = self::escapeLike($this->search).'%';
            $query->where(function (Builder $nested) use ($likeContains, $likePrefix): void {
                $nested->where('code', 'like', $likePrefix)
                    ->orWhere('title', 'like', $likeContains);
            });
        }

        if ($this->isActive !== null) {
            $query->where('is_active', $this->isActive);
        }

        $this->applyBranchScope($query);

        return $query;
    }

    /**
     * Apply the same predicates to a query-builder account alias (ledger joins).
     */
    public function applyToAccountTable(QueryBuilder $query, string $accounts): void
    {
        $stored = $this->storedLevel();
        if ($stored !== null) {
            $query->where("{$accounts}.level", $stored);
        }

        if ($this->parentId !== null) {
            $query->where("{$accounts}.parent_id", $this->parentId);
        }

        if ($this->code !== null) {
            $query->where("{$accounts}.code", $this->code);
        }

        if ($this->search !== null) {
            $likeContains = '%'.self::escapeLike($this->search).'%';
            $likePrefix = self::escapeLike($this->search).'%';
            $query->where(function (QueryBuilder $nested) use ($accounts, $likeContains, $likePrefix): void {
                $nested->where("{$accounts}.code", 'like', $likePrefix)
                    ->orWhere("{$accounts}.title", 'like', $likeContains);
            });
        }

        if ($this->isActive !== null) {
            $query->where("{$accounts}.is_active", $this->isActive);
        }

        $this->applyBranchScopeToTable($query, $accounts);
    }

    /**
     * Restrict a posting-level account join to this catalog scope.
     *
     * Level 4 filters the posting account directly. Levels 1–3 restrict to
     * posting descendants of matching ancestors (SQL subqueries, no PHP load).
     */
    public function constrainPostingAccounts(QueryBuilder $query, string $accounts): void
    {
        $posting = AccountHierarchy::postingLevel();
        $query->where("{$accounts}.level", $posting);

        if ($this->displayLevel === null || $this->displayLevel === AccountHierarchy::displayLevel($posting)) {
            $direct = clone $this;
            $query->where(function (QueryBuilder $nested) use ($accounts, $direct): void {
                $direct->applyToAccountTable($nested, $accounts);
            });

            return;
        }

        $ancestorStored = AccountHierarchy::storedLevel($this->displayLevel);
        $depth = $posting - $ancestorStored;
        $table = (new Account)->getTable();

        $ancestors = Account::query()->toBase()->select('id')->where('level', $ancestorStored);
        $this->applyToAccountTable($ancestors, $table);

        if ($depth === 1) {
            $query->whereIn("{$accounts}.parent_id", $ancestors);

            return;
        }

        if ($depth === 2) {
            $mid = Account::query()->toBase()
                ->select('id')
                ->where('level', $ancestorStored + 1)
                ->whereIn('parent_id', $ancestors);
            $query->whereIn("{$accounts}.parent_id", $mid);

            return;
        }

        $mid1 = Account::query()->toBase()
            ->select('id')
            ->where('level', $ancestorStored + 1)
            ->whereIn('parent_id', $ancestors);
        $mid2 = Account::query()->toBase()
            ->select('id')
            ->where('level', $ancestorStored + 2)
            ->whereIn('parent_id', $mid1);
        $query->whereIn("{$accounts}.parent_id", $mid2);
    }

    /**
     * Further restrict posting accounts to descendants of a page of ancestors.
     *
     * @param  list<int>  $ancestorIds
     */
    public function restrictToAncestorIds(QueryBuilder $query, string $accounts, array $ancestorIds): void
    {
        if ($ancestorIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $posting = AccountHierarchy::postingLevel();
        $display = $this->displayLevel ?? AccountHierarchy::displayLevel($posting);
        $depth = $posting - AccountHierarchy::storedLevel($display);

        if ($depth <= 0) {
            $query->whereIn("{$accounts}.id", $ancestorIds);

            return;
        }

        if ($depth === 1) {
            $query->whereIn("{$accounts}.parent_id", $ancestorIds);

            return;
        }

        $mid = Account::query()->toBase()
            ->select('id')
            ->whereIn('parent_id', $ancestorIds);
        if ($depth === 2) {
            $query->whereIn("{$accounts}.parent_id", $mid);

            return;
        }

        $mid2 = Account::query()->toBase()
            ->select('id')
            ->whereIn('parent_id', $mid);
        $query->whereIn("{$accounts}.parent_id", $mid2);
    }

    /**
     * @param  Builder<Account>|QueryBuilder  $query
     */
    private function applyBranchScope(Builder $query): void
    {
        if (! $this->branchScope->isConstrained()) {
            return;
        }

        $ids = $this->branchScope->branchIds;
        $query->where(function (Builder $nested) use ($ids): void {
            $nested->whereIn('branch_id', $ids)->orWhereNull('branch_id');
        });
    }

    private function applyBranchScopeToTable(QueryBuilder $query, string $accounts): void
    {
        if (! $this->branchScope->isConstrained()) {
            return;
        }

        $ids = $this->branchScope->branchIds;
        $query->where(function (QueryBuilder $nested) use ($accounts, $ids): void {
            $nested->whereIn("{$accounts}.branch_id", $ids)->orWhereNull("{$accounts}.branch_id");
        });
    }

    private static function parseDisplayLevel(mixed $raw, bool $required): ?int
    {
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new InvalidAccountHierarchyException('level is required and must be 1, 2, 3, or 4.');
            }

            return null;
        }

        if (! is_numeric($raw) || (int) $raw != $raw) {
            throw new InvalidAccountHierarchyException('level must be 1, 2, 3, or 4.');
        }

        $level = (int) $raw;
        $max = AccountHierarchy::displayMaxLevel();
        if ($level < 1 || $level > $max) {
            throw new InvalidAccountHierarchyException("level must be between 1 and {$max}.");
        }

        return $level;
    }

    private static function resolveParent(mixed $raw): ?Account
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw) || (int) $raw <= 0) {
            throw new InvalidReportFilterException('parent_id must be a positive integer.');
        }

        $parent = Account::query()->find((int) $raw);
        if (! $parent) {
            throw new InvalidReportFilterException('parent_id does not exist.');
        }

        return $parent;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
