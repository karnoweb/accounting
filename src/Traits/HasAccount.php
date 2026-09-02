<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\BalanceService;

trait HasAccount
{
    public static function bootHasAccount(): void
    {
        static::created(function ($model) {
            if ($model->shouldCreateAccount()) {
                $model->createAccount();
            }
        });

        static::deleted(function ($model) {
            if ($model->shouldDeleteAccount()) {
                $model->deleteAccount();
            }
        });
    }

    public function account(): MorphOne
    {
        return $this->morphOne(Account::class, 'entity', 'entity_type', 'entity_id');
    }

    public function hasAccount(): bool
    {
        return $this->account()->exists();
    }

    public function getAccountIdAttribute(): ?int
    {
        return $this->account?->id;
    }

    public function createAccount(?array $overrides = []): Account
    {
        if ($this->hasAccount()) {
            return $this->account;
        }

        $config = array_merge($this->accountConfig(), $overrides);

        $accountService = app(AccountService::class);

        $parentCode = $config['parent_code'] ?? null;
        $branchId = $config['branch_id'] ?? null;
        $parent = $parentCode ? $accountService->findByCode($parentCode, $branchId) : null;

        $account = $accountService->create([
            'parent_id' => $parent?->id,
            'branch_id' => $branchId,
            'code' => $config['code'] ?? null,
            'title' => $config['title'] ?? $this->getAccountTitle(),
            'description' => $config['description'] ?? null,
            'type' => $config['type'] ?? 'asset',
            'nature' => $config['nature'] ?? 'debit',
            'is_active' => $config['is_active'] ?? true,
            'is_system' => false,
            'allow_direct_posting' => $config['allow_direct_posting'] ?? true,
            'entity_type' => $this->getMorphClass(),
            'entity_id' => $this->getKey(),
            'meta' => $config['meta'] ?? null,
        ]);

        $this->load('account');

        return $account;
    }

    public function deleteAccount(): bool
    {
        if ( ! $this->hasAccount()) {
            return true;
        }

        $account = $this->account;

        if ( ! $account->canDelete()) {
            return false;
        }

        return $account->delete();
    }

    public function balance(?FiscalYear $fiscalYear = null): float
    {
        if ( ! $this->hasAccount()) {
            return 0.0;
        }

        return app(BalanceService::class)->getBalance($this->account, $fiscalYear);
    }

    public function balanceAsOf(Carbon|string $date, ?FiscalYear $fiscalYear = null): float
    {
        if ( ! $this->hasAccount()) {
            return 0.0;
        }

        return app(BalanceService::class)->getBalanceAsOf($this->account, $date, $fiscalYear);
    }

    public function documents(): Collection
    {
        if ( ! $this->hasAccount()) {
            return collect();
        }

        return Document::whereHas('items', function ($query) {
            $query->where('account_id', $this->account->id);
        })
            ->with('items.account')
            ->orderByDesc('date')
            ->orderByDesc('number')
            ->get();
    }

    public function transactions(?FiscalYear $fiscalYear = null): Collection
    {
        if ( ! $this->hasAccount()) {
            return collect();
        }

        $query = DocumentItem::query()
            ->where('account_id', $this->account->id)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            })
            ->with(['document' => function ($q) {
                $q->select('id', 'number', 'date', 'type', 'description', 'reference');
            }])
            ->orderBy('created_at');

        return $query->get();
    }

    protected function accountConfig(): array
    {
        return [
            'parent_code' => null,
            'title' => $this->getAccountTitle(),
            'type' => 'asset',
            'nature' => 'debit',
            'branch_id' => null,
            'is_active' => true,
            'allow_direct_posting' => true,
            'meta' => null,
        ];
    }

    protected function shouldCreateAccount(): bool
    {
        $config = $this->accountConfig();

        return $config['auto_create'] ?? true;
    }

    protected function shouldDeleteAccount(): bool
    {
        return false;
    }

    protected function getAccountTitle(): string
    {
        if (isset($this->name)) {
            return (string) $this->name;
        }

        if (isset($this->title)) {
            return (string) $this->title;
        }

        if (isset($this->full_name)) {
            return (string) $this->full_name;
        }

        return class_basename($this) . ' #' . $this->getKey();
    }
}
