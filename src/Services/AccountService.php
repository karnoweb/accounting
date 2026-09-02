<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Exceptions\AccountNotFoundException;
use Karnoweb\Accounting\Exceptions\InvalidAccountHierarchyException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Support\AccountHierarchy;

/**
 * Service for chart-of-accounts: create, find, search, and resolve system accounts.
 */
class AccountService
{
    /**
     * Create a new account. Parent can be given by parent_id or parent_code. Code can be auto-generated.
     *
     * @param array{parent_id?: int, parent_code?: string, code?: string, title: string, description?: string, type: string|AccountType, nature?: string, branch_id?: int|null, is_active?: bool, is_system?: bool, allow_direct_posting?: bool, entity_type?: string|null, entity_id?: int|null, meta?: array|null} $data
     */
    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data) {
            $parent = null;
            $branchId = array_key_exists('branch_id', $data) ? $data['branch_id'] : null;

            if ( ! empty($data['parent_id'])) {
                $parent = Account::findOrFail($data['parent_id']);
            } elseif ( ! empty($data['parent_code'])) {
                $parent = $this->findByCode($data['parent_code'], $branchId);

                if ( ! $parent) {
                    $scope = $branchId !== null ? " (branch {$branchId})" : '';
                    throw new AccountNotFoundException(
                        "Parent account with code {$data['parent_code']}{$scope} not found"
                    );
                }
            }

            // Both sides must agree on branch: a branch-scoped account can only nest
            // under a parent that is either shared (branch_id null) or the same branch.
            // Prevents e.g. branch 4 accounts silently attaching under branch 1's tree.
            if ($parent && $parent->branch_id !== null && $branchId !== null && $parent->branch_id !== $branchId) {
                throw new InvalidAccountHierarchyException(
                    __('accounting::accounting.messages.account_parent_branch_mismatch', [
                        'parent_branch' => $parent->branch_id,
                        'branch' => $branchId,
                    ])
                );
            }

            $level = $parent ? $parent->level + 1 : 0;
            $maxLevel = AccountHierarchy::maxLevel();
            $postingLevel = AccountHierarchy::postingLevel();

            if ($level > $maxLevel) {
                throw new InvalidAccountHierarchyException(
                    __('accounting::accounting.messages.account_level_exceeded', [
                        'level' => $level,
                        'max' => $maxLevel,
                    ])
                );
            }

            if ($parent && $parent->level >= $postingLevel) {
                throw new InvalidAccountHierarchyException(
                    __('accounting::accounting.messages.cannot_nest_under_posting_account')
                );
            }

            if (empty($data['code']) && config('accounting.account.auto_code', true)) {
                $data['code'] = $this->generateCode($parent, $branchId);
            }

            if (empty($data['nature']) && ! empty($data['type'])) {
                $type = $data['type'] instanceof AccountType
                    ? $data['type']
                    : AccountType::from($data['type']);
                $data['nature'] = $type->defaultNature()->value;
            }

            $allowDirectPosting = array_key_exists('allow_direct_posting', $data)
                ? (bool) $data['allow_direct_posting']
                : ($level === $postingLevel);

            if ($allowDirectPosting && $level !== $postingLevel) {
                throw new InvalidAccountHierarchyException(
                    __('accounting::accounting.messages.posting_only_at_posting_level', [
                        'level' => $postingLevel,
                    ])
                );
            }

            $account = Account::create([
                'parent_id' => $parent?->id,
                'branch_id' => $branchId,
                'code' => $data['code'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'level' => $level,
                'type' => $data['type'],
                'nature' => $data['nature'],
                'is_active' => $data['is_active'] ?? true,
                'is_system' => $data['is_system'] ?? false,
                'allow_direct_posting' => $allowDirectPosting,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);

            // Parent accounts must not remain postable once they gain children.
            if ($parent && $parent->allow_direct_posting) {
                $parent->update(['allow_direct_posting' => false]);
            }

            return $account;
        });
    }

    /**
     * @throws \Karnoweb\Accounting\Exceptions\InactiveAccountException
     * @throws \Karnoweb\Accounting\Exceptions\InvalidPostingAccountException
     */
    public function assertPostable(Account|int $account): Account
    {
        $account = $account instanceof Account ? $account : Account::findOrFail($account);
        $account->assertPostable();

        return $account;
    }

    /** Find account by id, or null if not found. */
    public function find(int $id): ?Account
    {
        return Account::find($id);
    }

    /** Find account by id, or throw ModelNotFoundException. */
    public function findOrFail(int $id): Account
    {
        return Account::findOrFail($id);
    }

    /** Find account by code (optionally scoped to a branch), or null if not found. */
    public function findByCode(string $code, ?int $branchId = null): ?Account
    {
        $query = Account::where('code', $code);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->first();
    }

    /** Find account by code (optionally scoped to a branch), or throw AccountNotFoundException. */
    public function findByCodeOrFail(string $code, ?int $branchId = null): Account
    {
        $account = $this->findByCode($code, $branchId);

        if ( ! $account) {
            $scope = $branchId !== null ? " (branch {$branchId})" : '';
            throw new AccountNotFoundException("Account with code {$code}{$scope} not found");
        }

        return $account;
    }

    /** Find account linked to an entity (morph: entity_type, entity_id). */
    public function findByEntity(string $entityType, int $entityId): ?Account
    {
        return Account::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();
    }

    /**
     * Find account by code, preferring an exact branch match and falling back to a
     * shared (branch_id IS NULL) account of the same code.
     *
     * Use this — instead of findByCode() — for any branch-sensitive lookup (system
     * accounts, retained earnings). Multi-branch charts (each branch has its own
     * account for the code) resolve to the right branch; single/shared charts
     * (one account, branch_id null, used by every branch) keep working unchanged.
     */
    public function findByCodeForBranch(string $code, ?int $branchId): ?Account
    {
        if ($branchId !== null) {
            $scoped = $this->findByCode($code, $branchId);
            if ($scoped !== null) {
                return $scoped;
            }
        }

        return Account::where('code', $code)->whereNull('branch_id')->first();
    }

    /** @see findByCodeForBranch() */
    public function findByCodeForBranchOrFail(string $code, ?int $branchId): Account
    {
        $account = $this->findByCodeForBranch($code, $branchId);

        if ( ! $account) {
            throw new AccountNotFoundException("Account with code {$code} (branch {$branchId}) not found");
        }

        return $account;
    }

    /**
     * Get system account by config key (e.g. 'cash', 'bank', 'receivables', 'payables', 'sales_income', 'cost_of_goods', 'refund_expense').
     *
     * Pass $branchId to resolve the account for a specific branch — an exact
     * (code, branch) match wins, falling back to a shared (branch_id null)
     * account. Omitting $branchId keeps the legacy behavior of returning the
     * first account with that code regardless of branch; pass it explicitly
     * whenever the caller has a branch in context (this is what multi-branch
     * callers were missing).
     *
     * @throws InvalidArgumentException When key is not in accounting.account.system_accounts
     */
    public function getSystemAccount(string $key, ?int $branchId = null): Account
    {
        $code = config("accounting.account.system_accounts.{$key}");

        if ( ! $code) {
            throw new InvalidArgumentException("System account key '{$key}' not configured");
        }

        if ($branchId === null) {
            return $this->findByCodeOrFail($code);
        }

        return $this->findByCodeForBranchOrFail($code, $branchId);
    }

    /**
     * Search accounts by query (title/code), type, level, is_active, branch. Returns collection ordered by code.
     *
     * branch_id is only applied when the key is present in $filters (array_key_exists,
     * not isset), and — matching findByCodeForBranch()'s resolution rule — an account
     * usable "in branch X" is either that branch's own account or a shared account
     * (branch_id IS NULL). Passing branch_id => null restricts to shared accounts only.
     * Omitting the key keeps searching across every branch (legacy behavior).
     *
     * @param  array{query?: string, type?: string, level?: int, is_active?: bool, branch_id?: int|null} $filters
     * @return Collection<int, Account>
     */
    public function search(array $filters): Collection
    {
        $query = Account::query();

        if ( ! empty($filters['query'])) {
            $search = $filters['query'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ( ! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['level'])) {
            $query->where('level', $filters['level']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (array_key_exists('branch_id', $filters)) {
            $branchId = $filters['branch_id'];

            if ($branchId === null) {
                $query->whereNull('branch_id');
            } else {
                $query->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                });
            }
        }

        return $query->orderBy('code')->get();
    }

    /**
     * Generate the next account code under a parent.
     *
     * Only considers siblings that already match the expected code length for the
     * new level, so shorter legacy/system codes (e.g. 110201 vs 1102000002) do not
     * collapse the sequence back to a colliding code. When branch_id is set, the
     * sequence is per (parent, branch) because uniqueness is (code, branch_id).
     */
    private function generateCode(?Account $parent, ?int $branchId = null): string
    {
        $codeLengths = config('accounting.account.code_length', [1, 2, 4, 6]);

        if ($parent === null) {
            $expectedLength = $codeLengths[0];
            $query = Account::whereNull('parent_id')
                ->whereRaw('LENGTH(code) = ?', [$expectedLength]);

            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }

            $lastCode = $query->orderByDesc('code')->lockForUpdate()->value('code');
            $nextNumber = $lastCode ? ((int) $lastCode + 1) : 1;

            return str_pad((string) $nextNumber, $expectedLength, '0', STR_PAD_LEFT);
        }

        $newLevel = $parent->level + 1;
        $parentCode = $parent->code;
        $expectedLength = $codeLengths[$newLevel] ?? ($codeLengths[count($codeLengths) - 1] + 2);
        $suffixLength = $expectedLength - strlen($parentCode);

        if ($suffixLength < 1) {
            throw new InvalidAccountHierarchyException(
                __('accounting::accounting.messages.account_level_exceeded', [
                    'level' => $newLevel,
                    'max' => AccountHierarchy::maxLevel(),
                ])
            );
        }

        $query = Account::where('parent_id', $parent->id)
            ->whereRaw('LENGTH(code) = ?', [$expectedLength])
            ->where('code', 'like', $parentCode . '%');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $lastChild = $query->orderByDesc('code')->lockForUpdate()->first();

        if ($lastChild) {
            $lastSuffix = substr($lastChild->code, strlen($parentCode));
            $nextNumber = (int) $lastSuffix + 1;
        } else {
            $nextNumber = 1;
        }

        $suffix = str_pad((string) $nextNumber, $suffixLength, '0', STR_PAD_LEFT);

        return $parentCode . $suffix;
    }
}
