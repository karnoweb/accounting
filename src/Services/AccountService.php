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
            if ( ! empty($data['parent_id'])) {
                $parent = Account::findOrFail($data['parent_id']);
            } elseif ( ! empty($data['parent_code'])) {
                $parent = Account::where('code', $data['parent_code'])->firstOrFail();
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
                $data['code'] = $this->generateCode($parent);
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
                'branch_id' => $data['branch_id'] ?? null,
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

    /** Find account by code, or null if not found. */
    public function findByCode(string $code): ?Account
    {
        return Account::where('code', $code)->first();
    }

    /** Find account by code, or throw AccountNotFoundException. */
    public function findByCodeOrFail(string $code): Account
    {
        $account = $this->findByCode($code);

        if ( ! $account) {
            throw new AccountNotFoundException("Account with code {$code} not found");
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
     * Get system account by config key (e.g. 'cash', 'bank', 'receivables', 'payables', 'sales_income', 'cost_of_goods', 'refund_expense').
     *
     * @throws InvalidArgumentException When key is not in accounting.account.system_accounts
     */
    public function getSystemAccount(string $key): Account
    {
        $code = config("accounting.account.system_accounts.{$key}");

        if ( ! $code) {
            throw new InvalidArgumentException("System account key '{$key}' not configured");
        }

        return $this->findByCodeOrFail($code);
    }

    /**
     * Search accounts by query (title/code), type, level, is_active. Returns collection ordered by code.
     *
     * @param  array{query?: string, type?: string, level?: int, is_active?: bool} $filters
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

        return $query->orderBy('code')->get();
    }

    private function generateCode(?Account $parent): string
    {
        $codeLengths = config('accounting.account.code_length', [1, 2, 4, 6]);

        if ($parent === null) {
            $lastCode = Account::whereNull('parent_id')
                ->orderByDesc('code')
                ->value('code');

            $nextNumber = $lastCode ? ((int) $lastCode + 1) : 1;

            return str_pad((string) $nextNumber, $codeLengths[0], '0', STR_PAD_LEFT);
        }

        $newLevel = $parent->level + 1;
        $parentCode = $parent->code;
        $expectedLength = $codeLengths[$newLevel] ?? ($codeLengths[count($codeLengths) - 1] + 2);

        $lastChild = Account::where('parent_id', $parent->id)
            ->orderByDesc('code')
            ->first();

        if ($lastChild) {
            $lastSuffix = substr($lastChild->code, strlen($parentCode));
            $nextNumber = (int) $lastSuffix + 1;
        } else {
            $nextNumber = 1;
        }

        $suffixLength = $expectedLength - strlen($parentCode);
        $suffix = str_pad((string) $nextNumber, $suffixLength, '0', STR_PAD_LEFT);

        return $parentCode . $suffix;
    }
}
