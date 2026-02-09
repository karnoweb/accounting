# 14-implementation/14d-traits.md

# پیاده‌سازی - Traits

## Implementation - Traits

---

## مقدمه

این بخش شامل کد Trait های پکیج حسابداری است. Trait اصلی `HasAccount` برای اتصال مدل‌های پروژه به سیستم حسابداری استفاده می‌شود.

---

## ۱. HasAccount Trait

```php
<?php

namespace YourVendor\Accounting\Traits;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Models\DocumentItem;
use YourVendor\Accounting\Models\FiscalYear;
use YourVendor\Accounting\Services\AccountService;
use YourVendor\Accounting\Services\BalanceService;
use Carbon\Carbon;

trait HasAccount
{
    /*
    |--------------------------------------------------------------------------
    | Boot Trait
    |--------------------------------------------------------------------------
    */

    /**
     * Boot the trait.
     */
    public static function bootHasAccount(): void
    {
        // ایجاد خودکار حساب پس از ساخت مدل
        static::created(function ($model) {
            if ($model->shouldCreateAccount()) {
                $model->createAccount();
            }
        });

        // حذف حساب پس از حذف مدل (اختیاری)
        static::deleted(function ($model) {
            if ($model->shouldDeleteAccount()) {
                $model->deleteAccount();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the account for this model.
     */
    public function account(): MorphOne
    {
        return $this->morphOne(Account::class, 'entity', 'entity_type', 'entity_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Account Management
    |--------------------------------------------------------------------------
    */

    /**
     * Check if model has an account.
     */
    public function hasAccount(): bool
    {
        return $this->account()->exists();
    }

    /**
     * Get the account ID.
     */
    public function getAccountIdAttribute(): ?int
    {
        return $this->account?->id;
    }

    /**
     * Create account for this model.
     */
    public function createAccount(?array $overrides = []): Account
    {
        if ($this->hasAccount()) {
            return $this->account;
        }

        $config = array_merge($this->accountConfig(), $overrides);

        $accountService = app(AccountService::class);

        // یافتن والد
        $parentCode = $config['parent_code'] ?? null;
        $parent = $parentCode ? $accountService->findByCode($parentCode) : null;

        // ساخت حساب
        $account = $accountService->create([
            'parent_id' => $parent?->id,
            'branch_id' => $config['branch_id'] ?? null,
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

        // رفرش رابطه
        $this->load('account');

        return $account;
    }

    /**
     * Delete the account.
     */
    public function deleteAccount(): bool
    {
        if (!$this->hasAccount()) {
            return true;
        }

        $account = $this->account;

        // بررسی امکان حذف
        if (!$account->canDelete()) {
            return false;
        }

        return $account->delete();
    }

    /**
     * Update account title.
     */
    public function updateAccountTitle(?string $title = null): void
    {
        if (!$this->hasAccount()) {
            return;
        }

        $this->account->update([
            'title' => $title ?? $this->getAccountTitle(),
        ]);
    }

    /**
     * Sync account with model.
     */
    public function syncAccount(): void
    {
        if (!$this->hasAccount()) {
            if ($this->shouldCreateAccount()) {
                $this->createAccount();
            }
            return;
        }

        $this->updateAccountTitle();
    }

    /*
    |--------------------------------------------------------------------------
    | Balance Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get account balance.
     */
    public function balance(?FiscalYear $fiscalYear = null): float
    {
        if (!$this->hasAccount()) {
            return 0;
        }

        return app(BalanceService::class)->getBalance($this->account, $fiscalYear);
    }

    /**
     * Get balance as of specific date.
     */
    public function balanceAsOf(Carbon|string $date, ?FiscalYear $fiscalYear = null): float
    {
        if (!$this->hasAccount()) {
            return 0;
        }

        return app(BalanceService::class)->getBalanceAsOf($this->account, $date, $fiscalYear);
    }

    /**
     * Get total debits.
     */
    public function totalDebits(?FiscalYear $fiscalYear = null): float
    {
        if (!$this->hasAccount()) {
            return 0;
        }

        return app(BalanceService::class)->getDebitTotal($this->account, $fiscalYear);
    }

    /**
     * Get total credits.
     */
    public function totalCredits(?FiscalYear $fiscalYear = null): float
    {
        if (!$this->hasAccount()) {
            return 0;
        }

        return app(BalanceService::class)->getCreditTotal($this->account, $fiscalYear);
    }

    /**
     * Get turnover for period.
     */
    public function turnover(Carbon|string $fromDate, Carbon|string $toDate): array
    {
        if (!$this->hasAccount()) {
            return ['debit' => 0, 'credit' => 0, 'balance' => 0];
        }

        return app(BalanceService::class)->getTurnover($this->account, $fromDate, $toDate);
    }

    /**
     * Refresh cached balance.
     */
    public function refreshBalance(): float
    {
        if (!$this->hasAccount()) {
            return 0;
        }

        return app(BalanceService::class)->refreshCache($this->account);
    }

    /*
    |--------------------------------------------------------------------------
    | Document Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get related documents.
     */
    public function documents(): Collection
    {
        if (!$this->hasAccount()) {
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

    /**
     * Get documents for period.
     */
    public function documentsForPeriod(Carbon|string $fromDate, Carbon|string $toDate): Collection
    {
        if (!$this->hasAccount()) {
            return collect();
        }

        return Document::whereHas('items', function ($query) {
            $query->where('account_id', $this->account->id);
        })
        ->whereBetween('date', [$fromDate, $toDate])
        ->where('status', 'posted')
        ->with('items.account')
        ->orderBy('date')
        ->orderBy('number')
        ->get();
    }

    /**
     * Get document items (transactions).
     */
    public function transactions(?FiscalYear $fiscalYear = null): Collection
    {
        if (!$this->hasAccount()) {
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

    /**
     * Get account statement.
     */
    public function statement(
        Carbon|string|null $fromDate = null,
        Carbon|string|null $toDate = null
    ): array {
        if (!$this->hasAccount()) {
            return [
                'account' => null,
                'opening_balance' => 0,
                'transactions' => collect(),
                'closing_balance' => 0,
            ];
        }

        $fromDate = $fromDate ? Carbon::parse($fromDate) : Carbon::now()->startOfYear();
        $toDate = $toDate ? Carbon::parse($toDate) : Carbon::now();

        // مانده اول دوره
        $openingBalance = $this->balanceAsOf($fromDate->copy()->subDay());

        // تراکنش‌های دوره
        $transactions = DocumentItem::query()
            ->where('account_id', $this->account->id)
            ->whereHas('document', function ($q) use ($fromDate, $toDate) {
                $q->where('status', 'posted')
                  ->whereBetween('date', [$fromDate, $toDate]);
            })
            ->with(['document:id,number,date,type,description,reference'])
            ->orderBy('document.date')
            ->orderBy('document.number')
            ->get();

        // محاسبه مانده پس از هر تراکنش
        $runningBalance = $openingBalance;
        $rows = $transactions->map(function ($item) use (&$runningBalance) {
            $runningBalance += ($item->amount * $item->sign);

            return [
                'date' => $item->document->date->format('Y-m-d'),
                'document_number' => $item->document->number,
                'document_type' => $item->document->type,
                'description' => $item->description ?? $item->document->description,
                'reference' => $item->document->reference,
                'debit' => $item->sign === 1 ? $item->amount : null,
                'credit' => $item->sign === -1 ? $item->amount : null,
                'balance' => $runningBalance,
            ];
        });

        return [
            'account' => $this->account,
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'opening_balance' => $openingBalance,
            'transactions' => $rows,
            'closing_balance' => $runningBalance,
            'totals' => [
                'debit' => $rows->sum('debit'),
                'credit' => $rows->sum('credit'),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration Methods (Override in Model)
    |--------------------------------------------------------------------------
    */

    /**
     * Get account configuration.
     * Override this method in your model.
     */
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

    /**
     * Should auto-create account?
     * Override this method in your model.
     */
    protected function shouldCreateAccount(): bool
    {
        $config = $this->accountConfig();
        return $config['auto_create'] ?? true;
    }

    /**
     * Should delete account when model is deleted?
     * Override this method in your model.
     */
    protected function shouldDeleteAccount(): bool
    {
        return false; // پیش‌فرض: حذف نکن
    }

    /**
     * Get account title.
     * Override this method in your model.
     */
    protected function getAccountTitle(): string
    {
        // تلاش برای یافتن فیلد مناسب
        if (isset($this->name)) {
            return $this->name;
        }

        if (isset($this->title)) {
            return $this->title;
        }

        if (isset($this->full_name)) {
            return $this->full_name;
        }

        // فالبک
        return class_basename($this) . ' #' . $this->getKey();
    }
}
```

---

## ۲. مثال‌های استفاده از HasAccount

### ۲.۱ مدل User (مشتری)

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use YourVendor\Accounting\Traits\HasAccount;

class User extends Authenticatable
{
    use HasAccount;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
    ];

    /**
     * تنظیمات حساب حسابداری
     */
    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1103',  // بدهکاران
            'title' => $this->name,
            'type' => 'asset',
            'nature' => 'debit',
            'auto_create' => true,
        ];
    }

    /**
     * عنوان حساب
     */
    protected function getAccountTitle(): string
    {
        return $this->name ?? 'مشتری #' . $this->id;
    }

    /**
     * آیا حساب خودکار ساخته شود؟
     */
    protected function shouldCreateAccount(): bool
    {
        // فقط برای مشتریان (نه ادمین‌ها)
        return !$this->is_admin;
    }
}
```

### ۲.۲ مدل Product (محصول)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Product extends Model
{
    use HasAccount;

    protected $fillable = [
        'name',
        'sku',
        'price',
        'cost_price',
        'stock',
    ];

    /**
     * تنظیمات حساب حسابداری
     */
    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1104',  // موجودی کالا
            'title' => $this->name,
            'type' => 'asset',
            'nature' => 'debit',
            'auto_create' => true,
            'meta' => [
                'sku' => $this->sku,
            ],
        ];
    }

    /**
     * عنوان حساب
     */
    protected function getAccountTitle(): string
    {
        return $this->name . ' (' . $this->sku . ')';
    }

    /**
     * موجودی مالی (ارزش ریالی موجودی)
     */
    public function inventoryValue(): float
    {
        return $this->balance();
    }
}
```

### ۲.۳ مدل Supplier (تأمین‌کننده)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Supplier extends Model
{
    use HasAccount;

    protected $fillable = [
        'name',
        'contact_name',
        'phone',
        'address',
    ];

    /**
     * تنظیمات حساب حسابداری
     */
    protected function accountConfig(): array
    {
        return [
            'parent_code' => '2101',  // بستانکاران
            'title' => $this->name,
            'type' => 'liability',
            'nature' => 'credit',
            'auto_create' => true,
        ];
    }

    /**
     * عنوان حساب
     */
    protected function getAccountTitle(): string
    {
        return 'تأمین‌کننده: ' . $this->name;
    }

    /**
     * بدهی به تأمین‌کننده
     */
    public function debt(): float
    {
        // مانده بستانکار نشان‌دهنده بدهی است
        return abs($this->balance());
    }
}
```

### ۲.۴ مدل Bank (بانک)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Bank extends Model
{
    use HasAccount;

    protected $fillable = [
        'name',
        'account_number',
        'branch_name',
        'iban',
    ];

    /**
     * تنظیمات حساب حسابداری
     */
    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1102',  // بانک‌ها
            'title' => $this->getAccountTitle(),
            'type' => 'asset',
            'nature' => 'debit',
            'auto_create' => true,
            'meta' => [
                'account_number' => $this->account_number,
                'iban' => $this->iban,
            ],
        ];
    }

    /**
     * عنوان حساب
     */
    protected function getAccountTitle(): string
    {
        return $this->name . ' - ' . $this->account_number;
    }

    /**
     * موجودی بانک
     */
    public function availableBalance(): float
    {
        return $this->balance();
    }

    /**
     * آیا موجودی کافی است؟
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->balance() >= $amount;
    }
}
```

### ۲.۵ مدل Cashier (صندوق)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Cashier extends Model
{
    use HasAccount;

    protected $fillable = [
        'name',
        'location',
        'is_active',
    ];

    /**
     * تنظیمات حساب حسابداری
     */
    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1101',  // موجودی نقد
            'title' => $this->name,
            'type' => 'asset',
            'nature' => 'debit',
            'auto_create' => true,
        ];
    }

    /**
     * عنوان حساب
     */
    protected function getAccountTitle(): string
    {
        return 'صندوق ' . $this->name;
    }

    /**
     * موجودی صندوق
     */
    public function cashOnHand(): float
    {
        return $this->balance();
    }

    /**
     * آیا موجودی کافی است؟
     */
    public function canWithdraw(float $amount): bool
    {
        return $this->balance() >= $amount;
    }
}
```

### ۲.۶ مدل Employee (کارمند)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Employee extends Model
{
    use HasAccount;

    protected $fillable = [
        'name',
        'employee_number',
        'department_id',
        'salary',
    ];

    /**
     * تنظیمات حساب حسابداری
     */
    protected function accountConfig(): array
    {
        return [
            'parent_code' => '2102',  // حساب‌های پرداختنی - کارکنان
            'title' => $this->name,
            'type' => 'liability',
            'nature' => 'credit',
            'auto_create' => true,
        ];
    }

    /**
     * عنوان حساب
     */
    protected function getAccountTitle(): string
    {
        return $this->name . ' (' . $this->employee_number . ')';
    }

    /**
     * بدهی به کارمند (حقوق پرداخت نشده)
     */
    public function unpaidSalary(): float
    {
        return abs($this->balance());
    }
}
```

---

## ۳. HasAccountingScopes Trait

یک Trait کمکی برای Scope های مرتبط با حسابداری:

```php
<?php

namespace YourVendor\Accounting\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasAccountingScopes
{
    /**
     * فقط رکوردهایی که حساب دارند.
     */
    public function scopeHasAccount(Builder $query): Builder
    {
        return $query->whereHas('account');
    }

    /**
     * فقط رکوردهایی که مانده بدهکار دارند.
     */
    public function scopeWithDebitBalance(Builder $query): Builder
    {
        return $query->whereHas('account', function ($q) {
            $q->where('cached_balance', '>', 0);
        });
    }

    /**
     * فقط رکوردهایی که مانده بستانکار دارند.
     */
    public function scopeWithCreditBalance(Builder $query): Builder
    {
        return $query->whereHas('account', function ($q) {
            $q->where('cached_balance', '<', 0);
        });
    }

    /**
     * فقط رکوردهایی که مانده صفر دارند.
     */
    public function scopeWithZeroBalance(Builder $query): Builder
    {
        return $query->whereHas('account', function ($q) {
            $q->where('cached_balance', 0);
        });
    }

    /**
     * فقط رکوردهایی که مانده غیرصفر دارند.
     */
    public function scopeWithNonZeroBalance(Builder $query): Builder
    {
        return $query->whereHas('account', function ($q) {
            $q->where('cached_balance', '!=', 0);
        });
    }

    /**
     * مرتب‌سازی بر اساس مانده.
     */
    public function scopeOrderByBalance(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->join('accounts', function ($join) {
            $join->on('accounts.entity_id', '=', $this->getTable() . '.id')
                 ->where('accounts.entity_type', '=', $this->getMorphClass());
        })
        ->orderBy('accounts.cached_balance', $direction)
        ->select($this->getTable() . '.*');
    }

    /**
     * فیلتر بر اساس حداقل مانده.
     */
    public function scopeMinBalance(Builder $query, float $amount): Builder
    {
        return $query->whereHas('account', function ($q) use ($amount) {
            $q->where('cached_balance', '>=', $amount);
        });
    }

    /**
     * فیلتر بر اساس حداکثر مانده.
     */
    public function scopeMaxBalance(Builder $query, float $amount): Builder
    {
        return $query->whereHas('account', function ($q) use ($amount) {
            $q->where('cached_balance', '<=', $amount);
        });
    }
}
```

### استفاده از HasAccountingScopes

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;
use YourVendor\Accounting\Traits\HasAccountingScopes;

class User extends Model
{
    use HasAccount, HasAccountingScopes;

    // ...
}

// استفاده:
$debtors = User::withDebitBalance()->get();
$settled = User::withZeroBalance()->get();
$topDebtors = User::orderByBalance('desc')->limit(10)->get();
$bigDebtors = User::minBalance(1000000)->get();
```

---

## ۴. SyncsAccountTitle Trait

برای همگام‌سازی خودکار عنوان حساب با تغییرات مدل:

```php
<?php

namespace YourVendor\Accounting\Traits;

trait SyncsAccountTitle
{
    /**
     * Boot the trait.
     */
    public static function bootSyncsAccountTitle(): void
    {
        static::updated(function ($model) {
            if ($model->hasAccount() && $model->shouldSyncAccountTitle()) {
                $model->updateAccountTitle();
            }
        });
    }

    /**
     * Check if should sync account title.
     */
    protected function shouldSyncAccountTitle(): bool
    {
        $watchFields = $this->accountTitleFields();

        foreach ($watchFields as $field) {
            if ($this->isDirty($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fields that affect account title.
     * Override in your model.
     */
    protected function accountTitleFields(): array
    {
        return ['name', 'title'];
    }
}
```

### استفاده از SyncsAccountTitle

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;
use YourVendor\Accounting\Traits\SyncsAccountTitle;

class User extends Model
{
    use HasAccount, SyncsAccountTitle;

    /**
     * فیلدهایی که روی عنوان حساب اثر دارند.
     */
    protected function accountTitleFields(): array
    {
        return ['name', 'company_name'];
    }

    protected function getAccountTitle(): string
    {
        return $this->company_name 
            ? $this->name . ' - ' . $this->company_name
            : $this->name;
    }
}

// حالا با تغییر name یا company_name، عنوان حساب هم بروز می‌شود
$user->update(['name' => 'نام جدید']);
// account.title هم به "نام جدید" تغییر می‌کند
```

---

## ۵. HasAccountBalance Trait

Trait مجزا برای نمایش مانده در لیست‌ها:

```php
<?php

namespace YourVendor\Accounting\Traits;

trait HasAccountBalance
{
    /**
     * Append balance to model.
     */
    protected function initializeHasAccountBalance(): void
    {
        $this->append(['account_balance', 'formatted_balance']);
    }

    /**
     * Get account balance attribute.
     */
    public function getAccountBalanceAttribute(): float
    {
        return $this->balance();
    }

    /**
     * Get formatted balance attribute.
     */
    public function getFormattedBalanceAttribute(): string
    {
        $balance = $this->balance();
        $formatted = number_format(abs($balance));

        if ($balance > 0) {
            return $formatted . ' بدهکار';
        } elseif ($balance < 0) {
            return $formatted . ' بستانکار';
        }

        return 'تسویه';
    }

    /**
     * Get balance status.
     */
    public function getBalanceStatusAttribute(): string
    {
        $balance = $this->balance();

        if ($balance > 0) {
            return 'debit';
        } elseif ($balance < 0) {
            return 'credit';
        }

        return 'settled';
    }

    /**
     * Get balance color for UI.
     */
    public function getBalanceColorAttribute(): string
    {
        return match($this->balance_status) {
            'debit' => 'red',
            'credit' => 'green',
            'settled' => 'gray',
        };
    }
}
```

### استفاده از HasAccountBalance

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;
use YourVendor\Accounting\Traits\HasAccountBalance;

class User extends Model
{
    use HasAccount, HasAccountBalance;
}

// در API یا View:
$user->account_balance;     // 1500000
$user->formatted_balance;   // "1,500,000 بدهکار"
$user->balance_status;      // "debit"
$user->balance_color;       // "red"
```

---

## ۶. خلاصه Trait ها

| Trait | مسئولیت | استفاده |
|-------|---------|---------|
| HasAccount | ایجاد و مدیریت حساب، مانده، تراکنش‌ها | اجباری |
| HasAccountingScopes | Scope های مفید Query | اختیاری |
| SyncsAccountTitle | همگام‌سازی عنوان حساب | اختیاری |
| HasAccountBalance | نمایش مانده در API | اختیاری |

---

## ۷. ترکیب Trait ها

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;
use YourVendor\Accounting\Traits\HasAccountingScopes;
use YourVendor\Accounting\Traits\SyncsAccountTitle;
use YourVendor\Accounting\Traits\HasAccountBalance;

class Customer extends Model
{
    use HasAccount;
    use HasAccountingScopes;
    use SyncsAccountTitle;
    use HasAccountBalance;

    protected $fillable = ['name', 'email', 'phone'];

    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1103',
            'title' => $this->name,
            'type' => 'asset',
            'nature' => 'debit',
        ];
    }

    protected function accountTitleFields(): array
    {
        return ['name'];
    }

    protected function getAccountTitle(): string
    {
        return $this->name;
    }
}
```

---

[→ ادامه: پیاده‌سازی - Enums (14e-enums.md)](14e-enums.md)

[← بازگشت: پیاده‌سازی - Services (14c-services.md)](14c-services.md)

[⌂ فهرست (00-index.md)](../00-index.md)
