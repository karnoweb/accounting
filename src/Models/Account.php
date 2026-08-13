<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Karnoweb\Accounting\Enums\AccountNature;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Exceptions\InactiveAccountException;
use Karnoweb\Accounting\Exceptions\InvalidPostingAccountException;
use Karnoweb\Accounting\Exceptions\SystemAccountException;
use Karnoweb\Accounting\Support\AccountHierarchy;

class Account extends BaseModel
{
    use SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = [
        'parent_id',
        'branch_id',
        'code',
        'title',
        'description',
        'level',
        'type',
        'nature',
        'is_active',
        'is_system',
        'allow_direct_posting',
        'entity_type',
        'entity_id',
        'cached_balance',
        'balance_updated_at',
        'meta',
    ];

    protected $attributes = [
        'level' => 0,
        'is_active' => true,
        'is_system' => false,
        'allow_direct_posting' => true,
        'cached_balance' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'nature' => AccountNature::class,
            'level' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'allow_direct_posting' => 'boolean',
            'cached_balance' => 'decimal:2',
            'balance_updated_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Account $account) {
            if ($account->is_system && $account->isDirty(['code', 'type', 'nature'])) {
                throw new SystemAccountException(
                    __('accounting::accounting.messages.system_account_protected')
                );
            }
        });

        static::deleting(function (Account $account) {
            if ($account->is_system) {
                throw new SystemAccountException(
                    __('accounting::accounting.messages.system_account_protected')
                );
            }

            if ($account->items()->exists()) {
                throw new Exception(
                    __('accounting::accounting.messages.account_has_transactions')
                );
            }

            if ($account->children()->exists()) {
                throw new Exception(
                    __('accounting::accounting.messages.account_has_children')
                );
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            config('accounting.branch.model'),
            config('accounting.branch.foreign_key', 'branch_id')
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string|AccountType $type): Builder
    {
        $typeValue = $type instanceof AccountType ? $type->value : $type;

        return $query->where('type', $typeValue);
    }

    public function scopeOfLevel(Builder $query, int $level): Builder
    {
        return $query->where('level', $level);
    }

    public function getNaturalBalanceAttribute(): float
    {
        return $this->nature === AccountNature::DEBIT
            ? (float) $this->cached_balance
            : -(float) $this->cached_balance;
    }

    public function balance(?FiscalYear $fiscalYear = null): float
    {
        $query = $this->items()
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) ($query->selectRaw('COALESCE(SUM(amount * sign), 0) as balance')->value('balance') ?? 0);
    }

    /**
     * Whether this account may receive journal lines under package posting rules.
     */
    public function isPostable(): bool
    {
        if ( ! $this->is_active) {
            return false;
        }

        if ( ! $this->allow_direct_posting) {
            return false;
        }

        if ($this->level !== AccountHierarchy::postingLevel()) {
            return false;
        }

        if ($this->relationLoaded('children')) {
            return $this->children->isEmpty();
        }

        return ! $this->children()->exists();
    }

    /**
     * @throws InactiveAccountException
     * @throws InvalidPostingAccountException
     */
    public function assertPostable(): void
    {
        if ( ! $this->is_active && config('accounting.validation.check_account_active', true)) {
            throw new InactiveAccountException($this);
        }

        if ( ! $this->isPostable()) {
            throw new InvalidPostingAccountException($this);
        }
    }

    public function canDelete(): bool
    {
        if ($this->is_system) {
            return false;
        }

        if ($this->items()->exists()) {
            return false;
        }

        return ! ($this->children()->exists());
    }

    public function refreshBalance(): float
    {
        $balance = $this->balance();

        $this->update([
            'cached_balance' => $balance,
            'balance_updated_at' => now(),
        ]);

        return $balance;
    }
}
