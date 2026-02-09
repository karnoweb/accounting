# 14-implementation/14a-models.md

# پیاده‌سازی - Models

## Implementation - Models

---

## مقدمه

این بخش شامل کد کامل Model های پکیج حسابداری است.

---

## ۱. Account Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use YourVendor\Accounting\Enums\AccountType;
use YourVendor\Accounting\Enums\AccountNature;
use YourVendor\Accounting\Exceptions\SystemAccountException;

class Account extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'accounts';

    /**
     * The attributes that are mass assignable.
     */
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

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
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

    /**
     * The attributes that should have default values.
     */
    protected $attributes = [
        'level' => 0,
        'is_active' => true,
        'is_system' => false,
        'allow_direct_posting' => true,
        'cached_balance' => 0,
    ];

    /**
     * Boot the model.
     */
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
                throw new \Exception(
                    __('accounting::accounting.messages.account_has_transactions')
                );
            }

            if ($account->children()->exists()) {
                throw new \Exception(
                    __('accounting::accounting.messages.account_has_children')
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the parent account.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /**
     * Get the child accounts.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    /**
     * Get all descendants recursively.
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get the branch.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the document items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class);
    }

    /**
     * Get the related entity.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to active accounts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to specific type.
     */
    public function scopeOfType(Builder $query, string|AccountType $type): Builder
    {
        $typeValue = $type instanceof AccountType ? $type->value : $type;
        return $query->where('type', $typeValue);
    }

    /**
     * Scope to specific level.
     */
    public function scopeOfLevel(Builder $query, int $level): Builder
    {
        return $query->where('level', $level);
    }

    /**
     * Scope to specific branch.
     */
    public function scopeOfBranch(Builder $query, int|Branch $branch): Builder
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;
        return $query->where('branch_id', $branchId);
    }

    /**
     * Scope to system accounts.
     */
    public function scopeSystemAccounts(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope to postable accounts (level 3).
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('level', 3)->where('allow_direct_posting', true);
    }

    /**
     * Scope to accounts with balance.
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->where('cached_balance', '!=', 0);
    }

    /**
     * Scope to accounts of specific entity type.
     */
    public function scopeOfEntityType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return $this->type->label();
    }

    /**
     * Get nature label.
     */
    public function getNatureLabelAttribute(): string
    {
        return $this->nature->label();
    }

    /**
     * Get level label.
     */
    public function getLevelLabelAttribute(): string
    {
        return __("accounting::accounting.account_levels.{$this->level}");
    }

    /**
     * Get full code with ancestors.
     */
    public function getFullCodeAttribute(): string
    {
        return $this->code;
    }

    /**
     * Get full title with ancestors.
     */
    public function getFullTitleAttribute(): string
    {
        $titles = collect([$this->title]);
        $parent = $this->parent;

        while ($parent) {
            $titles->prepend($parent->title);
            $parent = $parent->parent;
        }

        return $titles->implode(' > ');
    }

    /**
     * Get natural balance (positive in nature direction).
     */
    public function getNaturalBalanceAttribute(): float
    {
        return $this->nature === AccountNature::DEBIT
            ? $this->cached_balance
            : -$this->cached_balance;
    }

    /**
     * Get balance warning if abnormal.
     */
    public function getBalanceWarningAttribute(): ?string
    {
        if ($this->hasNormalBalance()) {
            return null;
        }

        return __('accounting::accounting.messages.abnormal_balance', [
            'account' => $this->title,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate real-time balance.
     */
    public function balance(?FiscalYear $fiscalYear = null): float
    {
        $query = $this->items()
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->selectRaw('SUM(amount * sign) as balance')
            ->value('balance') ?? 0;
    }

    /**
     * Get balance as of specific date.
     */
    public function balanceAsOf($date, ?FiscalYear $fiscalYear = null): float
    {
        $query = $this->items()
            ->whereHas('document', function ($q) use ($date, $fiscalYear) {
                $q->where('status', 'posted')
                  ->where('date', '<=', $date);
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->selectRaw('SUM(amount * sign) as balance')
            ->value('balance') ?? 0;
    }

    /**
     * Get balance in specific fiscal year.
     */
    public function balanceInFiscalYear(FiscalYear $fiscalYear): float
    {
        return $this->balance($fiscalYear);
    }

    /**
     * Get turnover for period.
     */
    public function turnover($fromDate, $toDate): array
    {
        $query = $this->items()
            ->whereHas('document', function ($q) use ($fromDate, $toDate) {
                $q->where('status', 'posted')
                  ->whereBetween('date', [$fromDate, $toDate]);
            });

        $result = $query->selectRaw('
            SUM(CASE WHEN sign = 1 THEN amount ELSE 0 END) as debit,
            SUM(CASE WHEN sign = -1 THEN amount ELSE 0 END) as credit
        ')->first();

        return [
            'debit' => (float) ($result->debit ?? 0),
            'credit' => (float) ($result->credit ?? 0),
            'balance' => (float) (($result->debit ?? 0) - ($result->credit ?? 0)),
        ];
    }

    /**
     * Get total debits.
     */
    public function totalDebits(?FiscalYear $fiscalYear = null): float
    {
        $query = $this->items()
            ->where('sign', 1)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->sum('amount');
    }

    /**
     * Get total credits.
     */
    public function totalCredits(?FiscalYear $fiscalYear = null): float
    {
        $query = $this->items()
            ->where('sign', -1)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->sum('amount');
    }

    /**
     * Check if balance is normal (in nature direction).
     */
    public function hasNormalBalance(): bool
    {
        return $this->natural_balance >= 0;
    }

    /**
     * Check if this is a leaf account (no children).
     */
    public function isLeaf(): bool
    {
        return !$this->children()->exists();
    }

    /**
     * Check if this is a root account (no parent).
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Check if is ancestor of another account.
     */
    public function isAncestorOf(Account $account): bool
    {
        $parent = $account->parent;

        while ($parent) {
            if ($parent->id === $this->id) {
                return true;
            }
            $parent = $parent->parent;
        }

        return false;
    }

    /**
     * Check if is descendant of another account.
     */
    public function isDescendantOf(Account $account): bool
    {
        return $account->isAncestorOf($this);
    }

    /**
     * Get the path from root to this account.
     */
    public function getPath(): array
    {
        $path = collect([$this]);
        $parent = $this->parent;

        while ($parent) {
            $path->prepend($parent);
            $parent = $parent->parent;
        }

        return $path->toArray();
    }

    /**
     * Check if account can be deleted.
     */
    public function canDelete(): bool
    {
        if ($this->is_system) {
            return false;
        }

        if ($this->items()->exists()) {
            return false;
        }

        if ($this->children()->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Refresh cached balance.
     */
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
```

---

## ۲. Document Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use YourVendor\Accounting\Enums\DocumentStatus;
use YourVendor\Accounting\Exceptions\DocumentNotEditableException;
use YourVendor\Accounting\Events\DocumentCreated;
use YourVendor\Accounting\Events\DocumentPosted;
use YourVendor\Accounting\Events\DocumentVoided;

class Document extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'documents';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'fiscal_year_id',
        'branch_id',
        'number',
        'reference',
        'date',
        'type',
        'status',
        'description',
        'notes',
        'source_type',
        'source_id',
        'posted_at',
        'created_by',
        'approved_by',
        'posted_by',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status' => DocumentStatus::class,
        'date' => 'date',
        'posted_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * The attributes that should have default values.
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * The event map for the model.
     */
    protected $dispatchesEvents = [
        'created' => DocumentCreated::class,
    ];

    /**
     * Temporary storage for old values.
     */
    public array $_oldValues = [];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::updating(function (Document $document) {
            $document->_oldValues = $document->getOriginal();

            if ($document->getOriginal('status') === 'posted') {
                if ($document->status !== DocumentStatus::VOIDED) {
                    throw new DocumentNotEditableException($document);
                }
            }
        });

        static::deleting(function (Document $document) {
            if ($document->status === DocumentStatus::POSTED) {
                throw new DocumentNotEditableException($document);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the fiscal year.
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * Get the branch.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the document items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class)->orderBy('order');
    }

    /**
     * Get the document logs.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(DocumentLog::class)->orderBy('created_at');
    }

    /**
     * Get the creator user.
     */
    public function createdBy(): BelongsTo
    {
        $userModel = config('accounting.user.model', 'App\\Models\\User');
        return $this->belongsTo($userModel, 'created_by');
    }

    /**
     * Get the approver user.
     */
    public function approvedBy(): BelongsTo
    {
        $userModel = config('accounting.user.model', 'App\\Models\\User');
        return $this->belongsTo($userModel, 'approved_by');
    }

    /**
     * Get the poster user.
     */
    public function postedBy(): BelongsTo
    {
        $userModel = config('accounting.user.model', 'App\\Models\\User');
        return $this->belongsTo($userModel, 'posted_by');
    }

    /**
     * Get the source model.
     */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to posted documents.
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    /**
     * Scope to draft documents.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to pending documents.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to specific type.
     */
    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        if (is_array($type)) {
            return $query->whereIn('type', $type);
        }
        return $query->where('type', $type);
    }

    /**
     * Scope to specific fiscal year.
     */
    public function scopeOfFiscalYear(Builder $query, int|FiscalYear $fiscalYear): Builder
    {
        $fiscalYearId = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;
        return $query->where('fiscal_year_id', $fiscalYearId);
    }

    /**
     * Scope to specific branch.
     */
    public function scopeOfBranch(Builder $query, int|Branch $branch): Builder
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;
        return $query->where('branch_id', $branchId);
    }

    /**
     * Scope to date range.
     */
    public function scopeInDateRange(Builder $query, $fromDate, $toDate): Builder
    {
        return $query->whereBetween('date', [$fromDate, $toDate]);
    }

    /**
     * Scope to today's documents.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('date', today());
    }

    /**
     * Scope to this month's documents.
     */
    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereMonth('date', now()->month)
                     ->whereYear('date', now()->year);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    /**
     * Get type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return __("accounting::accounting.document_types.{$this->type}");
    }

    /**
     * Get status color.
     */
    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }

    /**
     * Get total debit amount.
     */
    public function getDebitTotalAttribute(): float
    {
        return (float) $this->items->where('sign', 1)->sum('amount');
    }

    /**
     * Get total credit amount.
     */
    public function getCreditTotalAttribute(): float
    {
        return (float) $this->items->where('sign', -1)->sum('amount');
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if document is balanced.
     */
    public function isBalanced(): bool
    {
        $balance = $this->items->sum(fn($item) => $item->amount * $item->sign);
        return abs($balance) < 0.01;
    }

    /**
     * Check if document is posted.
     */
    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::POSTED;
    }

    /**
     * Check if document is editable.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [DocumentStatus::DRAFT, DocumentStatus::PENDING]);
    }

    /**
     * Check if document is deletable.
     */
    public function isDeletable(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    /**
     * Check if document is voidable.
     */
    public function isVoidable(): bool
    {
        return $this->status === DocumentStatus::POSTED;
    }

    /**
     * Get total amounts.
     */
    public function getTotal(): array
    {
        $debit = $this->items->where('sign', 1)->sum('amount');
        $credit = $this->items->where('sign', -1)->sum('amount');

        return [
            'debit' => (float) $debit,
            'credit' => (float) $credit,
            'balance' => (float) ($debit - $credit),
        ];
    }

    /**
     * Get affected accounts.
     */
    public function getAffectedAccounts(): array
    {
        return $this->items->pluck('account_id')->unique()->values()->toArray();
    }

    /**
     * Post the document.
     */
    public function post(): self
    {
        if (!$this->isBalanced()) {
            throw new \YourVendor\Accounting\Exceptions\UnbalancedDocumentException(
                $this->debit_total,
                $this->credit_total
            );
        }

        $this->update([
            'status' => DocumentStatus::POSTED,
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ]);

        event(new DocumentPosted($this));

        return $this;
    }

    /**
     * Void the document.
     */
    public function void(string $reason = ''): self
    {
        if (!$this->isVoidable()) {
            throw new \Exception(__('accounting::accounting.messages.document_not_voidable'));
        }

        $this->update([
            'status' => DocumentStatus::VOIDED,
            'notes' => $this->notes . "\n\nدلیل ابطال: {$reason}",
        ]);

        event(new DocumentVoided($this, $reason));

        return $this;
    }

    /**
     * Submit for approval.
     */
    public function submit(): self
    {
        if ($this->status !== DocumentStatus::DRAFT) {
            throw new \Exception('فقط اسناد پیش‌نویس قابل ارسال هستند.');
        }

        $this->update(['status' => DocumentStatus::PENDING]);

        return $this;
    }

    /**
     * Approve the document.
     */
    public function approve(): self
    {
        if ($this->status !== DocumentStatus::PENDING) {
            throw new \Exception('فقط اسناد در انتظار قابل تأیید هستند.');
        }

        $this->update([
            'status' => DocumentStatus::APPROVED,
            'approved_by' => auth()->id(),
        ]);

        return $this;
    }

    /**
     * Reject the document.
     */
    public function reject(string $reason = ''): self
    {
        if ($this->status !== DocumentStatus::PENDING) {
            throw new \Exception('فقط اسناد در انتظار قابل رد هستند.');
        }

        $this->update([
            'status' => DocumentStatus::DRAFT,
            'notes' => $this->notes . "\n\nدلیل رد: {$reason}",
        ]);

        return $this;
    }

    /**
     * Duplicate the document.
     */
    public function duplicate(): self
    {
        $newDocument = $this->replicate(['number', 'status', 'posted_at', 'created_by', 'approved_by', 'posted_by']);
        $newDocument->status = DocumentStatus::DRAFT;
        $newDocument->date = now();
        $newDocument->created_by = auth()->id();
        $newDocument->save();

        foreach ($this->items as $item) {
            $newItem = $item->replicate();
            $newItem->document_id = $newDocument->id;
            $newItem->save();
        }

        return $newDocument;
    }

    /**
     * Create reverse document.
     */
    public function reverse(string $description = ''): self
    {
        $reverseDocument = $this->replicate(['number', 'status', 'posted_at', 'created_by', 'approved_by', 'posted_by']);
        $reverseDocument->status = DocumentStatus::DRAFT;
        $reverseDocument->date = now();
        $reverseDocument->description = $description ?: "برگشت سند شماره {$this->number}";
        $reverseDocument->reference = "REV-{$this->number}";
        $reverseDocument->created_by = auth()->id();
        $reverseDocument->save();

        foreach ($this->items as $item) {
            $newItem = $item->replicate();
            $newItem->document_id = $reverseDocument->id;
            $newItem->sign = $item->sign * -1; // Reverse the sign
            $newItem->save();
        }

        return $reverseDocument;
    }
}
```

---

## ۳. DocumentItem Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentItem extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'document_items';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'document_id',
        'account_id',
        'cost_center_id',
        'amount',
        'sign',
        'debit',
        'credit',
        'description',
        'order',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'sign' => 'integer',
        'order' => 'integer',
        'meta' => 'array',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (DocumentItem $item) {
            // Auto-calculate debit and credit
            $item->debit = $item->sign === 1 ? $item->amount : 0;
            $item->credit = $item->sign === -1 ? $item->amount : 0;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the account.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the cost center.
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get signed amount.
     */
    public function getSignedAmountAttribute(): float
    {
        return $this->amount * $this->sign;
    }

    /**
     * Check if item is debit.
     */
    public function getIsDebitAttribute(): bool
    {
        return $this->sign === 1;
    }

    /**
     * Check if item is credit.
     */
    public function getIsCreditAttribute(): bool
    {
        return $this->sign === -1;
    }

    /**
     * Get sign label.
     */
    public function getSignLabelAttribute(): string
    {
        return $this->sign === 1
            ? __('accounting::accounting.general.debit')
            : __('accounting::accounting.general.credit');
    }
}
```

---

## ۴. FiscalYear Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use YourVendor\Accounting\Enums\FiscalYearStatus;
use Carbon\Carbon;

class FiscalYear extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'fiscal_years';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'start_date',
        'end_date',
        'status',
        'is_current',
        'opening_done',
        'opened_at',
        'closed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status' => FiscalYearStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'opening_done' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * The attributes that should have default values.
     */
    protected $attributes = [
        'status' => 'draft',
        'is_current' => false,
        'opening_done' => false,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the documents.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to active fiscal years.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to closed fiscal years.
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope to current fiscal year.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /**
     * Scope to fiscal year containing date.
     */
    public function scopeContainingDate(Builder $query, $date): Builder
    {
        return $query->where('start_date', '<=', $date)
                     ->where('end_date', '>=', $date);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if fiscal year is active.
     */
    public function isActive(): bool
    {
        return $this->status === FiscalYearStatus::ACTIVE;
    }

    /**
     * Check if fiscal year is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === FiscalYearStatus::CLOSED;
    }

    /**
     * Check if date is within this fiscal year.
     */
    public function containsDate($date): bool
    {
        $date = Carbon::parse($date);
        return $date->between($this->start_date, $this->end_date);
    }

    /**
     * Get remaining days.
     */
    public function getDaysRemaining(): int
    {
        if ($this->end_date->isPast()) {
            return 0;
        }

        return now()->diffInDays($this->end_date);
    }

    /**
     * Get progress percentage.
     */
    public function getProgress(): float
    {
        $totalDays = $this->start_date->diffInDays($this->end_date);
        $passedDays = $this->start_date->diffInDays(now());

        if ($passedDays <= 0) {
            return 0;
        }

        if ($passedDays >= $totalDays) {
            return 100;
        }

        return round(($passedDays / $totalDays) * 100, 2);
    }

    /**
     * Get documents count.
     */
    public function documentsCount(): int
    {
        return $this->documents()->count();
    }

    /**
     * Activate the fiscal year.
     */
    public function activate(): self
    {
        if ($this->status !== FiscalYearStatus::DRAFT) {
            throw new \Exception('فقط سال مالی پیش‌نویس قابل فعال‌سازی است.');
        }

        // Deactivate other active fiscal years
        static::where('status', 'active')->update([
            'status' => FiscalYearStatus::CLOSED,
            'is_current' => false,
        ]);

        $this->update([
            'status' => FiscalYearStatus::ACTIVE,
            'is_current' => true,
            'opened_at' => now(),
        ]);

        return $this;
    }

    /**
     * Close the fiscal year.
     */
    public function close(): self
    {
        if ($this->status !== FiscalYearStatus::ACTIVE) {
            throw new \Exception('فقط سال مالی فعال قابل بستن است.');
        }

        $draftCount = $this->documents()->where('status', 'draft')->count();
        if ($draftCount > 0) {
            throw new \Exception("تعداد {$draftCount} سند پیش‌نویس وجود دارد.");
        }

        $this->update([
            'status' => FiscalYearStatus::CLOSED,
            'is_current' => false,
            'closed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Reopen the fiscal year.
     */
    public function reopen(): self
    {
        if ($this->status !== FiscalYearStatus::CLOSED) {
            throw new \Exception('فقط سال مالی بسته قابل بازگشایی است.');
        }

        static::where('status', 'active')->update([
            'status' => FiscalYearStatus::CLOSED,
            'is_current' => false,
        ]);

        $this->update([
            'status' => FiscalYearStatus::ACTIVE,
            'is_current' => true,
            'closed_at' => null,
        ]);

        return $this;
    }

    /**
     * Get current fiscal year.
     */
    public static function current(): ?self
    {
        return static::where('is_current', true)->first()
            ?? static::where('status', 'active')->first();
    }

    /**
     * Find fiscal year by date.
     */
    public static function findByDate($date): ?self
    {
        return static::containingDate($date)->first();
    }
}
```

---

## ۵. Branch Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Branch extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'branches';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'title',
        'is_active',
        'is_default',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    /**
     * The attributes that should have default values.
     */
    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the accounts.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Get the documents.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to active branches.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to default branch.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Set as default branch.
     */
    public function setAsDefault(): self
    {
        static::where('is_default', true)->update(['is_default' => false]);

        $this->update(['is_default' => true]);

        return $this;
    }

    /**
     * Get default branch.
     */
    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::active()->first();
    }
}
```

---

## ۶. CostCenter Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class CostCenter extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'cost_centers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'title',
        'description',
        'is_active',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    /**
     * The attributes that should have default values.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the document items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to active cost centers.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get total expenses.
     */
    public function getTotalExpenses(?FiscalYear $fiscalYear = null): float
    {
        $query = $this->items()
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            })
            ->whereHas('account', function ($q) {
                $q->where('type', 'expense');
            });

        return (float) $query->sum('amount');
    }

    /**
     * Get total income.
     */
    public function getTotalIncome(?FiscalYear $fiscalYear = null): float
    {
        $query = $this->items()
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            })
            ->whereHas('account', function ($q) {
                $q->where('type', 'income');
            });

        return (float) $query->sum('amount');
    }
}
```

---

## ۷. DocumentLog Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'document_logs';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'document_id',
        'user_id',
        'action',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (DocumentLog $log) {
            $log->created_at = $log->created_at ?? now();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        $userModel = config('accounting.user.model', 'App\\Models\\User');
        return $this->belongsTo($userModel, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get action label.
     */
    public function getActionLabelAttribute(): string
    {
        return __("accounting::accounting.audit_actions.{$this->action}");
    }
}
```

---

## ۸. خلاصه Model ها

| Model | جدول | روابط اصلی |
|-------|------|------------|
| Account | accounts | parent, children, branch, items |
| Document | documents | fiscalYear, branch, items, logs |
| DocumentItem | document_items | document, account, costCenter |
| FiscalYear | fiscal_years | documents |
| Branch | branches | accounts, documents |
| CostCenter | cost_centers | items |
| DocumentLog | document_logs | document, user |

---

[→ ادامه: پیاده‌سازی - Migrations (14b-migrations.md)](14b-migrations.md)

[← بازگشت: مثال‌ها (13-examples.md)](../13-examples.md)

[⌂ فهرست (00-index.md)](../00-index.md)
