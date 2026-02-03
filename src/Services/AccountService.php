<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Exceptions\AccountNotFoundException;
use Karnoweb\Accounting\Models\Account;

class AccountService
{
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

            if (empty($data['code']) && config('accounting.account.auto_code', true)) {
                $data['code'] = $this->generateCode($parent);
            }

            if (empty($data['nature']) && ! empty($data['type'])) {
                $type = $data['type'] instanceof AccountType
                    ? $data['type']
                    : AccountType::from($data['type']);
                $data['nature'] = $type->defaultNature()->value;
            }

            return Account::create([
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
                'allow_direct_posting' => $data['allow_direct_posting'] ?? ($level === 3),
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);
        });
    }

    public function find(int $id): ?Account
    {
        return Account::find($id);
    }

    public function findOrFail(int $id): Account
    {
        return Account::findOrFail($id);
    }

    public function findByCode(string $code): ?Account
    {
        return Account::where('code', $code)->first();
    }

    public function findByCodeOrFail(string $code): Account
    {
        $account = $this->findByCode($code);

        if ( ! $account) {
            throw new AccountNotFoundException("Account with code {$code} not found");
        }

        return $account;
    }

    public function findByEntity(string $entityType, int $entityId): ?Account
    {
        return Account::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();
    }

    public function getSystemAccount(string $key): Account
    {
        $code = config("accounting.account.system_accounts.{$key}");

        if ( ! $code) {
            throw new InvalidArgumentException("System account key '{$key}' not configured");
        }

        return $this->findByCodeOrFail($code);
    }

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
