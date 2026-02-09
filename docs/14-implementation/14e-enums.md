# 14-implementation/14e-enums.md

# پیاده‌سازی - Enums

## Implementation - Enums

---

## مقدمه

این بخش شامل کد کامل Enum های پکیج حسابداری است. Enum ها برای تعریف مقادیر ثابت و معتبر استفاده می‌شوند.

---

## ۱. AccountType Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case INCOME = 'income';
    case EXPENSE = 'expense';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::ASSET => __('accounting::accounting.account_types.asset'),
            self::LIABILITY => __('accounting::accounting.account_types.liability'),
            self::EQUITY => __('accounting::accounting.account_types.equity'),
            self::INCOME => __('accounting::accounting.account_types.income'),
            self::EXPENSE => __('accounting::accounting.account_types.expense'),
        };
    }

    /**
     * Get the default nature for this type.
     */
    public function defaultNature(): AccountNature
    {
        return match($this) {
            self::ASSET, self::EXPENSE => AccountNature::DEBIT,
            self::LIABILITY, self::EQUITY, self::INCOME => AccountNature::CREDIT,
        };
    }

    /**
     * Check if this is a permanent account (appears in balance sheet).
     */
    public function isPermanent(): bool
    {
        return in_array($this, [
            self::ASSET,
            self::LIABILITY,
            self::EQUITY,
        ]);
    }

    /**
     * Check if this is a temporary account (appears in income statement).
     */
    public function isTemporary(): bool
    {
        return in_array($this, [
            self::INCOME,
            self::EXPENSE,
        ]);
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match($this) {
            self::ASSET => 'blue',
            self::LIABILITY => 'red',
            self::EQUITY => 'purple',
            self::INCOME => 'green',
            self::EXPENSE => 'orange',
        };
    }

    /**
     * Get icon name.
     */
    public function icon(): string
    {
        return match($this) {
            self::ASSET => 'banknotes',
            self::LIABILITY => 'credit-card',
            self::EQUITY => 'building-office',
            self::INCOME => 'arrow-trending-up',
            self::EXPENSE => 'arrow-trending-down',
        };
    }

    /**
     * Get all types as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Get permanent types.
     */
    public static function permanentTypes(): array
    {
        return [
            self::ASSET,
            self::LIABILITY,
            self::EQUITY,
        ];
    }

    /**
     * Get temporary types.
     */
    public static function temporaryTypes(): array
    {
        return [
            self::INCOME,
            self::EXPENSE,
        ];
    }

    /**
     * Get values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

---

## ۲. AccountNature Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum AccountNature: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::DEBIT => __('accounting::accounting.account_natures.debit'),
            self::CREDIT => __('accounting::accounting.account_natures.credit'),
        };
    }

    /**
     * Get the sign for this nature.
     * Debit = +1, Credit = -1
     */
    public function sign(): int
    {
        return match($this) {
            self::DEBIT => 1,
            self::CREDIT => -1,
        };
    }

    /**
     * Get the opposite nature.
     */
    public function opposite(): self
    {
        return match($this) {
            self::DEBIT => self::CREDIT,
            self::CREDIT => self::DEBIT,
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match($this) {
            self::DEBIT => 'red',
            self::CREDIT => 'green',
        };
    }

    /**
     * Get short label.
     */
    public function shortLabel(): string
    {
        return match($this) {
            self::DEBIT => 'بد',
            self::CREDIT => 'بس',
        };
    }

    /**
     * Get all natures as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Create from sign.
     */
    public static function fromSign(int $sign): self
    {
        return match($sign) {
            1 => self::DEBIT,
            -1 => self::CREDIT,
            default => throw new \InvalidArgumentException("Invalid sign: {$sign}"),
        };
    }

    /**
     * Get values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

---

## ۳. DocumentStatus Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case VOIDED = 'voided';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::DRAFT => __('accounting::accounting.document_statuses.draft'),
            self::PENDING => __('accounting::accounting.document_statuses.pending'),
            self::APPROVED => __('accounting::accounting.document_statuses.approved'),
            self::POSTED => __('accounting::accounting.document_statuses.posted'),
            self::VOIDED => __('accounting::accounting.document_statuses.voided'),
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'yellow',
            self::APPROVED => 'blue',
            self::POSTED => 'green',
            self::VOIDED => 'red',
        };
    }

    /**
     * Get icon name.
     */
    public function icon(): string
    {
        return match($this) {
            self::DRAFT => 'pencil',
            self::PENDING => 'clock',
            self::APPROVED => 'check',
            self::POSTED => 'check-circle',
            self::VOIDED => 'x-circle',
        };
    }

    /**
     * Check if document is editable in this status.
     */
    public function isEditable(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::PENDING,
        ]);
    }

    /**
     * Check if document is deletable in this status.
     */
    public function isDeletable(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Check if document affects balances in this status.
     */
    public function affectsBalance(): bool
    {
        return $this === self::POSTED;
    }

    /**
     * Check if document can be voided in this status.
     */
    public function isVoidable(): bool
    {
        return $this === self::POSTED;
    }

    /**
     * Check if document can be posted from this status.
     */
    public function canPost(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::APPROVED,
        ]);
    }

    /**
     * Get allowed transitions from this status.
     */
    public function allowedTransitions(): array
    {
        return match($this) {
            self::DRAFT => [self::PENDING, self::POSTED],
            self::PENDING => [self::DRAFT, self::APPROVED],
            self::APPROVED => [self::POSTED],
            self::POSTED => [self::VOIDED],
            self::VOIDED => [],
        };
    }

    /**
     * Check if can transition to given status.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions());
    }

    /**
     * Get all statuses as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Get active statuses (not voided).
     */
    public static function activeStatuses(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::APPROVED,
            self::POSTED,
        ];
    }

    /**
     * Get statuses that affect balance.
     */
    public static function balanceAffectingStatuses(): array
    {
        return [self::POSTED];
    }

    /**
     * Get values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

---

## ۴. FiscalYearStatus Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum FiscalYearStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case CLOSED = 'closed';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::DRAFT => __('accounting::accounting.fiscal_year_statuses.draft'),
            self::ACTIVE => __('accounting::accounting.fiscal_year_statuses.active'),
            self::CLOSED => __('accounting::accounting.fiscal_year_statuses.closed'),
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'green',
            self::CLOSED => 'blue',
        };
    }

    /**
     * Get icon name.
     */
    public function icon(): string
    {
        return match($this) {
            self::DRAFT => 'pencil',
            self::ACTIVE => 'play',
            self::CLOSED => 'lock-closed',
        };
    }

    /**
     * Check if documents can be posted in this status.
     */
    public function allowsPosting(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if fiscal year can be edited in this status.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Check if fiscal year can be deleted in this status.
     */
    public function isDeletable(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Check if reports can be generated in this status.
     */
    public function allowsReports(): bool
    {
        return in_array($this, [self::ACTIVE, self::CLOSED]);
    }

    /**
     * Get allowed transitions from this status.
     */
    public function allowedTransitions(): array
    {
        return match($this) {
            self::DRAFT => [self::ACTIVE],
            self::ACTIVE => [self::CLOSED],
            self::CLOSED => [self::ACTIVE], // reopen
        };
    }

    /**
     * Check if can transition to given status.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions());
    }

    /**
     * Get all statuses as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Get values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

---

## ۵. DocumentType Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum DocumentType: string
{
    case SALE = 'sale';
    case PURCHASE = 'purchase';
    case RECEIPT = 'receipt';
    case PAYMENT = 'payment';
    case TRANSFER = 'transfer';
    case OPENING = 'opening';
    case CLOSING = 'closing';
    case ADJUSTMENT = 'adjustment';
    case JOURNAL = 'journal';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::SALE => __('accounting::accounting.document_types.sale'),
            self::PURCHASE => __('accounting::accounting.document_types.purchase'),
            self::RECEIPT => __('accounting::accounting.document_types.receipt'),
            self::PAYMENT => __('accounting::accounting.document_types.payment'),
            self::TRANSFER => __('accounting::accounting.document_types.transfer'),
            self::OPENING => __('accounting::accounting.document_types.opening'),
            self::CLOSING => __('accounting::accounting.document_types.closing'),
            self::ADJUSTMENT => __('accounting::accounting.document_types.adjustment'),
            self::JOURNAL => __('accounting::accounting.document_types.journal'),
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match($this) {
            self::SALE => 'green',
            self::PURCHASE => 'blue',
            self::RECEIPT => 'emerald',
            self::PAYMENT => 'orange',
            self::TRANSFER => 'purple',
            self::OPENING => 'indigo',
            self::CLOSING => 'pink',
            self::ADJUSTMENT => 'yellow',
            self::JOURNAL => 'gray',
        };
    }

    /**
     * Get icon name.
     */
    public function icon(): string
    {
        return match($this) {
            self::SALE => 'shopping-cart',
            self::PURCHASE => 'truck',
            self::RECEIPT => 'arrow-down-tray',
            self::PAYMENT => 'arrow-up-tray',
            self::TRANSFER => 'arrows-right-left',
            self::OPENING => 'play',
            self::CLOSING => 'stop',
            self::ADJUSTMENT => 'adjustments-horizontal',
            self::JOURNAL => 'document-text',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match($this) {
            self::SALE => 'ثبت فروش کالا یا خدمات',
            self::PURCHASE => 'ثبت خرید کالا یا خدمات',
            self::RECEIPT => 'دریافت وجه از مشتری',
            self::PAYMENT => 'پرداخت وجه به تأمین‌کننده',
            self::TRANSFER => 'انتقال بین حساب‌ها',
            self::OPENING => 'سند افتتاحیه سال مالی',
            self::CLOSING => 'سند اختتامیه سال مالی',
            self::ADJUSTMENT => 'اصلاح و تعدیل',
            self::JOURNAL => 'سند عمومی',
        };
    }

    /**
     * Check if this is a system type (opening/closing).
     */
    public function isSystem(): bool
    {
        return in_array($this, [
            self::OPENING,
            self::CLOSING,
        ]);
    }

    /**
     * Check if this type is reversible.
     */
    public function isReversible(): bool
    {
        return !$this->isSystem();
    }

    /**
     * Get all types as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Get user-selectable types (excluding system types).
     */
    public static function userTypes(): array
    {
        return collect(self::cases())
            ->filter(fn($case) => !$case->isSystem())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Get values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

---

## ۶. AuditAction Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum AuditAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case POSTED = 'posted';
    case VOIDED = 'voided';
    case RESTORED = 'restored';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::CREATED => __('accounting::accounting.audit_actions.created'),
            self::UPDATED => __('accounting::accounting.audit_actions.updated'),
            self::SUBMITTED => __('accounting::accounting.audit_actions.submitted'),
            self::APPROVED => __('accounting::accounting.audit_actions.approved'),
            self::REJECTED => __('accounting::accounting.audit_actions.rejected'),
            self::POSTED => __('accounting::accounting.audit_actions.posted'),
            self::VOIDED => __('accounting::accounting.audit_actions.voided'),
            self::RESTORED => __('accounting::accounting.audit_actions.restored'),
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match($this) {
            self::CREATED => 'blue',
            self::UPDATED => 'yellow',
            self::SUBMITTED => 'purple',
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            self::POSTED => 'emerald',
            self::VOIDED => 'red',
            self::RESTORED => 'indigo',
        };
    }

    /**
     * Get icon name.
     */
    public function icon(): string
    {
        return match($this) {
            self::CREATED => 'plus',
            self::UPDATED => 'pencil',
            self::SUBMITTED => 'paper-airplane',
            self::APPROVED => 'check',
            self::REJECTED => 'x-mark',
            self::POSTED => 'check-circle',
            self::VOIDED => 'trash',
            self::RESTORED => 'arrow-path',
        };
    }

    /**
     * Get severity level.
     */
    public function severity(): string
    {
        return match($this) {
            self::CREATED, self::UPDATED => 'info',
            self::SUBMITTED, self::RESTORED => 'notice',
            self::APPROVED, self::POSTED => 'success',
            self::REJECTED, self::VOIDED => 'warning',
        };
    }

    /**
     * Check if action is significant.
     */
    public function isSignificant(): bool
    {
        return in_array($this, [
            self::POSTED,
            self::VOIDED,
            self::APPROVED,
            self::REJECTED,
        ]);
    }

    /**
     * Get all actions as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Get values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

---

## ۷. AccountLevel Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum AccountLevel: int
{
    case GROUP = 0;
    case MAIN = 1;
    case SUBSIDIARY = 2;
    case DETAIL = 3;

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::GROUP => __('accounting::accounting.account_levels.group'),
            self::MAIN => __('accounting::accounting.account_levels.main'),
            self::SUBSIDIARY => __('accounting::accounting.account_levels.subsidiary'),
            self::DETAIL => __('accounting::accounting.account_levels.detail'),
        };
    }

    /**
     * Get Persian label.
     */
    public function persianLabel(): string
    {
        return match($this) {
            self::GROUP => 'گروه',
            self::MAIN => 'کل',
            self::SUBSIDIARY => 'معین',
            self::DETAIL => 'تفصیلی',
        };
    }

    /**
     * Check if posting is allowed at this level.
     */
    public function allowsPosting(): bool
    {
        return $this === self::DETAIL;
    }

    /**
     * Check if children are allowed at this level.
     */
    public function allowsChildren(): bool
    {
        return $this !== self::DETAIL;
    }

    /**
     * Get the next level.
     */
    public function nextLevel(): ?self
    {
        return match($this) {
            self::GROUP => self::MAIN,
            self::MAIN => self::SUBSIDIARY,
            self::SUBSIDIARY => self::DETAIL,
            self::DETAIL => null,
        };
    }

    /**
     * Get the previous level.
     */
    public function previousLevel(): ?self
    {
        return match($this) {
            self::GROUP => null,
            self::MAIN => self::GROUP,
            self::SUBSIDIARY => self::MAIN,
            self::DETAIL => self::SUBSIDIARY,
        };
    }

    /**
     * Get the depth (same as value).
     */
    public function depth(): int
    {
        return $this->value;
    }

    /**
     * Get all levels as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Create from integer.
     */
    public static function fromInt(int $value): self
    {
        return match($value) {
            0 => self::GROUP,
            1 => self::MAIN,
            2 => self::SUBSIDIARY,
            3 => self::DETAIL,
            default => throw new \InvalidArgumentException("Invalid level: {$value}"),
        };
    }

    /**
     * Get values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

---

## ۸. BalanceType Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

enum BalanceType: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
    case ZERO = 'zero';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::DEBIT => 'بدهکار',
            self::CREDIT => 'بستانکار',
            self::ZERO => 'صفر',
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match($this) {
            self::DEBIT => 'red',
            self::CREDIT => 'green',
            self::ZERO => 'gray',
        };
    }

    /**
     * Create from balance amount.
     */
    public static function fromBalance(float $balance): self
    {
        if ($balance > 0) {
            return self::DEBIT;
        }

        if ($balance < 0) {
            return self::CREDIT;
        }

        return self::ZERO;
    }

    /**
     * Create from sign.
     */
    public static function fromSign(int $sign): self
    {
        return match($sign) {
            1 => self::DEBIT,
            -1 => self::CREDIT,
            default => self::ZERO,
        };
    }
}
```

---

## ۹. ReportPeriod Enum

```php
<?php

namespace YourVendor\Accounting\Enums;

use Carbon\Carbon;

enum ReportPeriod: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case LAST_WEEK = 'last_week';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case THIS_QUARTER = 'this_quarter';
    case LAST_QUARTER = 'last_quarter';
    case THIS_YEAR = 'this_year';
    case LAST_YEAR = 'last_year';
    case CUSTOM = 'custom';

    /**
     * Get the label for display.
     */
    public function label(): string
    {
        return match($this) {
            self::TODAY => 'امروز',
            self::YESTERDAY => 'دیروز',
            self::THIS_WEEK => 'این هفته',
            self::LAST_WEEK => 'هفته گذشته',
            self::THIS_MONTH => 'این ماه',
            self::LAST_MONTH => 'ماه گذشته',
            self::THIS_QUARTER => 'این فصل',
            self::LAST_QUARTER => 'فصل گذشته',
            self::THIS_YEAR => 'امسال',
            self::LAST_YEAR => 'سال گذشته',
            self::CUSTOM => 'سفارشی',
        };
    }

    /**
     * Get date range for this period.
     */
    public function getDateRange(): array
    {
        return match($this) {
            self::TODAY => [
                Carbon::today(),
                Carbon::today(),
            ],
            self::YESTERDAY => [
                Carbon::yesterday(),
                Carbon::yesterday(),
            ],
            self::THIS_WEEK => [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ],
            self::LAST_WEEK => [
                Carbon::now()->subWeek()->startOfWeek(),
                Carbon::now()->subWeek()->endOfWeek(),
            ],
            self::THIS_MONTH => [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ],
            self::LAST_MONTH => [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth(),
            ],
            self::THIS_QUARTER => [
                Carbon::now()->startOfQuarter(),
                Carbon::now()->endOfQuarter(),
            ],
            self::LAST_QUARTER => [
                Carbon::now()->subQuarter()->startOfQuarter(),
                Carbon::now()->subQuarter()->endOfQuarter(),
            ],
            self::THIS_YEAR => [
                Carbon::now()->startOfYear(),
                Carbon::now()->endOfYear(),
            ],
            self::LAST_YEAR => [
                Carbon::now()->subYear()->startOfYear(),
                Carbon::now()->subYear()->endOfYear(),
            ],
            self::CUSTOM => [null, null],
        };
    }

    /**
     * Get all periods as options array.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }

    /**
     * Get quick select options (excluding custom).
     */
    public static function quickOptions(): array
    {
        return collect(self::cases())
            ->filter(fn($case) => $case !== self::CUSTOM)
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
```

---

## ۱۰. استفاده از Enum ها

### ۱۰.۱ در Model

```php
use YourVendor\Accounting\Enums\AccountType;
use YourVendor\Accounting\Enums\DocumentStatus;

class Account extends Model
{
    protected $casts = [
        'type' => AccountType::class,
        'status' => DocumentStatus::class,
    ];
}

// استفاده:
$account->type === AccountType::ASSET;
$account->type->label(); // "دارایی"
$account->type->color(); // "blue"
```

### ۱۰.۲ در Validation

```php
use Illuminate\Validation\Rules\Enum;
use YourVendor\Accounting\Enums\AccountType;

$request->validate([
    'type' => ['required', new Enum(AccountType::class)],
]);

// یا با values
$request->validate([
    'type' => ['required', 'in:' . implode(',', AccountType::values())],
]);
```

### ۱۰.۳ در Blade

```html
<select name="type">
    @foreach(\YourVendor\Accounting\Enums\AccountType::options() as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</select>

<span class="badge bg-{{ $document->status->color() }}">
    {{ $document->status->label() }}
</span>
```

### ۱۰.۴ در Query

```php
use YourVendor\Accounting\Enums\AccountType;
use YourVendor\Accounting\Enums\DocumentStatus;

// فیلتر با Enum
Account::where('type', AccountType::ASSET)->get();

// چند مقدار
Document::whereIn('status', [
    DocumentStatus::DRAFT,
    DocumentStatus::PENDING,
])->get();
```

---

## ۱۱. خلاصه Enum ها

| Enum | مقادیر | کاربرد |
|------|--------|--------|
| AccountType | asset, liability, equity, income, expense | نوع حساب |
| AccountNature | debit, credit | ماهیت حساب |
| DocumentStatus | draft, pending, approved, posted, voided | وضعیت سند |
| FiscalYearStatus | draft, active, closed | وضعیت سال مالی |
| DocumentType | sale, purchase, receipt, payment, ... | نوع سند |
| AuditAction | created, updated, posted, voided, ... | عملیات لاگ |
| AccountLevel | 0, 1, 2, 3 | سطح حساب |
| BalanceType | debit, credit, zero | نوع مانده |
| ReportPeriod | today, this_month, ... | دوره گزارش |

---

[→ ادامه: پیاده‌سازی - Events & Observers (14f-events-observers.md)](14f-events-observers.md)

[← بازگشت: پیاده‌سازی - Traits (14d-traits.md)](14d-traits.md)

[⌂ فهرست (00-index.md)](../00-index.md)
