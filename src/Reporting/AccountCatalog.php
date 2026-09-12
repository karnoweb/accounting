<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Support\AccountHierarchy;

/**
 * Generic account hierarchy and keyset-paginated listing.
 *
 * Public levels are 1–4. The hierarchy tree never loads Level 4 rows;
 * Level 3 nodes only receive an aggregated `level_4_count`.
 */
final class AccountCatalog
{
    public function __construct(
        private readonly AccountQueryFilters $filters,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function hierarchy(array $input = []): AccountHierarchyResult
    {
        $maxDisplay = AccountQueryFilters::parseHierarchyMaxLevel($input['max_level'] ?? null);
        $filters = AccountQueryFilters::from($input);

        return (new self($filters))->buildTree($maxDisplay);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function paginate(array $input = [], array|ReportPagination|null $pagination = null): AccountPageResult
    {
        $filters = AccountQueryFilters::from($input, requireLevel: true);
        $pagination = $pagination instanceof ReportPagination
            ? $pagination
            : ReportPagination::fromInput(array_merge($input, is_array($pagination) ? $pagination : []));

        return (new self($filters))->page($pagination);
    }

    /**
     * Eloquent listing query for the current filters. Callers may inspect SQL
     * (LIMIT / keyset) without materializing the chart.
     *
     * @return Builder<Account>
     */
    public function listingQuery(): Builder
    {
        $query = Account::query()->select([
            'id', 'parent_id', 'code', 'title', 'level', 'is_active',
        ]);
        $this->filters->applyToAccountQuery($query);
        $column = $this->filters->sortColumn();
        $direction = $this->filters->sortDirection;
        $query->orderBy($column, $direction);
        if ($column !== 'id') {
            $query->orderBy('id', $direction);
        }

        return $query;
    }

    public function page(ReportPagination $pagination): AccountPageResult
    {
        $query = $this->listingQuery();

        if ($pagination->mode === ReportPagination::MODE_OFFSET) {
            $total = (clone $query)->toBase()->getCountForPagination();
            $accounts = $query
                ->offset($pagination->offsetValue())
                ->limit($pagination->perPage)
                ->get();
            $nodes = $this->nodesFromAccounts($accounts);
            $meta = PaginationMeta::offset(
                $pagination->page,
                $pagination->perPage,
                $total,
                $pagination->offsetValue() + count($nodes) < $total,
            );
        } else {
            $this->applyKeyset($query, $pagination->cursor);
            $accounts = $query->limit($pagination->perPage + 1)->get();
            $hasMore = $accounts->count() > $pagination->perPage;
            $accounts = $accounts->take($pagination->perPage)->values();
            $nodes = $this->nodesFromAccounts($accounts);
            $next = $hasMore && $accounts->isNotEmpty()
                ? $this->encodeCursor($accounts->last())
                : null;
            $meta = PaginationMeta::cursor($pagination->perPage, $hasMore, $next);
        }

        return new AccountPageResult(
            data: $nodes,
            pagination: $meta,
            meta: [
                'level' => $this->filters->displayLevel,
                'parent_id' => $this->filters->parentId,
                'branch_scope' => $this->filters->branchScope->toArray(),
            ],
        );
    }

    public function buildTree(int $maxDisplayLevel): AccountHierarchyResult
    {
        $maxStored = AccountHierarchy::storedLevel($maxDisplayLevel);
        $postingStored = AccountHierarchy::postingLevel();

        $query = Account::query()
            ->select(['id', 'parent_id', 'code', 'title', 'level', 'is_active'])
            ->where('level', '<=', $maxStored)
            ->where('level', '<', $postingStored)
            ->orderBy('code')
            ->orderBy('id');

        $this->filters->applyToAccountQuery($query);
        $accounts = $query->get()->keyBy('id');

        $ids = $accounts->pluck('id')->all();
        $childCounts = $ids === []
            ? collect()
            : Account::query()
                ->selectRaw('parent_id, COUNT(*) as aggregate')
                ->whereIn('parent_id', $ids)
                ->groupBy('parent_id')
                ->pluck('aggregate', 'parent_id');

        $level3Stored = AccountHierarchy::storedLevel(3);
        $byParent = [];
        foreach ($accounts as $account) {
            $byParent[$account->parent_id ?? 0][] = $account;
        }

        $makeNode = function ($account) use (&$makeNode, $byParent, $childCounts, $level3Stored, $maxDisplayLevel): AccountNode {
            $display = AccountHierarchy::displayLevel((int) $account->level);
            $childrenCount = (int) ($childCounts[$account->id] ?? 0);
            $children = [];
            if ($display < $maxDisplayLevel) {
                foreach ($byParent[$account->id] ?? [] as $child) {
                    $children[] = $makeNode($child);
                }
            }

            $level4Count = null;
            $hasLevel4 = null;
            if ((int) $account->level === $level3Stored) {
                $level4Count = $childrenCount;
                $hasLevel4 = $childrenCount > 0;
            }

            return new AccountNode(
                id: (int) $account->id,
                code: (string) $account->code,
                name: (string) $account->title,
                level: $display,
                parentId: $account->parent_id !== null ? (int) $account->parent_id : null,
                isActive: (bool) $account->is_active,
                hasChildren: $childrenCount > 0,
                childrenCount: $childrenCount,
                level4Count: $level4Count,
                hasLevel4: $hasLevel4,
                children: $children,
            );
        };

        $roots = [];
        foreach ($byParent[0] ?? [] as $account) {
            $roots[] = $makeNode($account);
        }

        foreach ($accounts as $account) {
            if ($account->parent_id === null) {
                continue;
            }
            if (! $accounts->has($account->parent_id) && ! $accounts->contains('id', $account->parent_id)) {
                $roots[] = $makeNode($account);
            }
        }

        $seen = [];
        $unique = [];
        foreach ($roots as $root) {
            if (isset($seen[$root->id])) {
                continue;
            }
            $seen[$root->id] = true;
            $unique[] = $root;
        }

        return new AccountHierarchyResult(
            data: $unique,
            meta: [
                'max_level' => $maxDisplayLevel,
                'includes_level_4' => false,
                'branch_scope' => $this->filters->branchScope->toArray(),
            ],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accounts
     * @return list<AccountNode>
     */
    public function nodesFromAccounts($accounts): array
    {
        $ids = $accounts->pluck('id')->all();
        $childCounts = $ids === []
            ? collect()
            : Account::query()
                ->selectRaw('parent_id, COUNT(*) as aggregate')
                ->whereIn('parent_id', $ids)
                ->groupBy('parent_id')
                ->pluck('aggregate', 'parent_id');

        $level3Stored = AccountHierarchy::storedLevel(3);
        $nodes = [];
        foreach ($accounts as $account) {
            $childrenCount = (int) ($childCounts[$account->id] ?? 0);
            $level4Count = null;
            $hasLevel4 = null;
            if ((int) $account->level === $level3Stored) {
                $level4Count = $childrenCount;
                $hasLevel4 = $childrenCount > 0;
            }

            $nodes[] = new AccountNode(
                id: (int) $account->id,
                code: (string) $account->code,
                name: (string) $account->title,
                level: AccountHierarchy::displayLevel((int) $account->level),
                parentId: $account->parent_id !== null ? (int) $account->parent_id : null,
                isActive: (bool) $account->is_active,
                hasChildren: $childrenCount > 0,
                childrenCount: $childrenCount,
                level4Count: $level4Count,
                hasLevel4: $hasLevel4,
            );
        }

        return $nodes;
    }

    /**
     * @param  Builder<Account>  $query
     */
    private function applyKeyset(Builder $query, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }

        $keys = CursorCodec::decode($cursor);
        $id = (int) ($keys['id'] ?? 0);
        $column = $this->filters->sortColumn();
        $value = $keys[$column] ?? $keys['code'] ?? '';
        $op = $this->filters->sortDirection === 'desc' ? '<' : '>';

        $query->where(function (Builder $nested) use ($column, $value, $id, $op): void {
            $nested->where($column, $op, $value)
                ->orWhere(function (Builder $same) use ($column, $value, $id, $op): void {
                    $same->where($column, $value)->where('id', $op, $id);
                });
        });
    }

    private function encodeCursor(Account $account): string
    {
        $column = $this->filters->sortColumn();

        return CursorCodec::encode([
            $column => $account->{$column},
            'id' => (int) $account->id,
        ]);
    }
}
