# 14-implementation/14f-events-observers.md

# پیاده‌سازی - Events و Observers

## Implementation - Events & Observers

---

## مقدمه

این بخش شامل کد کامل Event ها و Observer های پکیج حسابداری است. Event ها برای اطلاع‌رسانی رویدادهای مهم و Observer ها برای واکنش خودکار به تغییرات Model ها استفاده می‌شوند.

---

## ۱. Events

### ۱.۱ DocumentCreated Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\Document;

class DocumentCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The document instance.
     */
    public Document $document;

    /**
     * Create a new event instance.
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Get the document.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the document ID.
     */
    public function getDocumentId(): int
    {
        return $this->document->id;
    }

    /**
     * Get the document type.
     */
    public function getDocumentType(): string
    {
        return $this->document->type;
    }

    /**
     * Get the creator user ID.
     */
    public function getCreatorId(): ?int
    {
        return $this->document->created_by;
    }
}
```

---

### ۱.۲ DocumentPosted Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\Document;

class DocumentPosted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The document instance.
     */
    public Document $document;

    /**
     * Create a new event instance.
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Get the document.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get affected account IDs.
     */
    public function getAffectedAccountIds(): array
    {
        return $this->document->items->pluck('account_id')->unique()->values()->toArray();
    }

    /**
     * Get the total amount (debit side).
     */
    public function getTotalAmount(): float
    {
        return $this->document->items->where('sign', 1)->sum('amount');
    }

    /**
     * Get the poster user ID.
     */
    public function getPosterId(): ?int
    {
        return $this->document->posted_by;
    }

    /**
     * Get posted at timestamp.
     */
    public function getPostedAt(): ?\DateTimeInterface
    {
        return $this->document->posted_at;
    }
}
```

---

### ۱.۳ DocumentVoided Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\Document;

class DocumentVoided
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The document instance.
     */
    public Document $document;

    /**
     * The void reason.
     */
    public string $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(Document $document, string $reason = '')
    {
        $this->document = $document;
        $this->reason = $reason;
    }

    /**
     * Get the document.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the void reason.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get affected account IDs.
     */
    public function getAffectedAccountIds(): array
    {
        return $this->document->items->pluck('account_id')->unique()->values()->toArray();
    }

    /**
     * Get the user who voided the document.
     */
    public function getVoidedById(): ?int
    {
        return auth()->id();
    }
}
```

---

### ۱.۴ DocumentStatusChanged Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Enums\DocumentStatus;

class DocumentStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The document instance.
     */
    public Document $document;

    /**
     * The old status.
     */
    public DocumentStatus $oldStatus;

    /**
     * The new status.
     */
    public DocumentStatus $newStatus;

    /**
     * Create a new event instance.
     */
    public function __construct(Document $document, DocumentStatus $oldStatus, DocumentStatus $newStatus)
    {
        $this->document = $document;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
    }

    /**
     * Get the document.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the old status.
     */
    public function getOldStatus(): DocumentStatus
    {
        return $this->oldStatus;
    }

    /**
     * Get the new status.
     */
    public function getNewStatus(): DocumentStatus
    {
        return $this->newStatus;
    }

    /**
     * Check if document was posted.
     */
    public function wasPosted(): bool
    {
        return $this->newStatus === DocumentStatus::POSTED;
    }

    /**
     * Check if document was voided.
     */
    public function wasVoided(): bool
    {
        return $this->newStatus === DocumentStatus::VOIDED;
    }

    /**
     * Check if document was approved.
     */
    public function wasApproved(): bool
    {
        return $this->newStatus === DocumentStatus::APPROVED;
    }
}
```

---

### ۱.۵ AccountCreated Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\Account;

class AccountCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The account instance.
     */
    public Account $account;

    /**
     * Create a new event instance.
     */
    public function __construct(Account $account)
    {
        $this->account = $account;
    }

    /**
     * Get the account.
     */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * Check if account is linked to an entity.
     */
    public function isEntityLinked(): bool
    {
        return $this->account->entity_type !== null && $this->account->entity_id !== null;
    }

    /**
     * Get the entity type.
     */
    public function getEntityType(): ?string
    {
        return $this->account->entity_type;
    }

    /**
     * Get the entity ID.
     */
    public function getEntityId(): ?int
    {
        return $this->account->entity_id;
    }
}
```

---

### ۱.۶ BalanceUpdated Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\Account;

class BalanceUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The account instance.
     */
    public Account $account;

    /**
     * The old balance.
     */
    public float $oldBalance;

    /**
     * The new balance.
     */
    public float $newBalance;

    /**
     * Create a new event instance.
     */
    public function __construct(Account $account, float $oldBalance, float $newBalance)
    {
        $this->account = $account;
        $this->oldBalance = $oldBalance;
        $this->newBalance = $newBalance;
    }

    /**
     * Get the account.
     */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * Get the difference.
     */
    public function getDifference(): float
    {
        return $this->newBalance - $this->oldBalance;
    }

    /**
     * Check if balance increased.
     */
    public function isIncrease(): bool
    {
        return $this->newBalance > $this->oldBalance;
    }

    /**
     * Check if balance decreased.
     */
    public function isDecrease(): bool
    {
        return $this->newBalance < $this->oldBalance;
    }

    /**
     * Check if balance became zero.
     */
    public function becameZero(): bool
    {
        return abs($this->newBalance) < 0.01 && abs($this->oldBalance) >= 0.01;
    }

    /**
     * Check if balance became negative.
     */
    public function becameNegative(): bool
    {
        return $this->newBalance < 0 && $this->oldBalance >= 0;
    }
}
```

---

### ۱.۷ FiscalYearClosed Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\FiscalYear;

class FiscalYearClosed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The fiscal year instance.
     */
    public FiscalYear $fiscalYear;

    /**
     * Create a new event instance.
     */
    public function __construct(FiscalYear $fiscalYear)
    {
        $this->fiscalYear = $fiscalYear;
    }

    /**
     * Get the fiscal year.
     */
    public function getFiscalYear(): FiscalYear
    {
        return $this->fiscalYear;
    }

    /**
     * Get the total documents count.
     */
    public function getDocumentsCount(): int
    {
        return $this->fiscalYear->documents()->count();
    }
}
```

---

### ۱.۸ FiscalYearOpened Event

```php
<?php

namespace YourVendor\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YourVendor\Accounting\Models\FiscalYear;

class FiscalYearOpened
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The fiscal year instance.
     */
    public FiscalYear $fiscalYear;

    /**
     * The previous fiscal year (if any).
     */
    public ?FiscalYear $previousYear;

    /**
     * Create a new event instance.
     */
    public function __construct(FiscalYear $fiscalYear, ?FiscalYear $previousYear = null)
    {
        $this->fiscalYear = $fiscalYear;
        $this->previousYear = $previousYear;
    }

    /**
     * Get the fiscal year.
     */
    public function getFiscalYear(): FiscalYear
    {
        return $this->fiscalYear;
    }

    /**
     * Get the previous fiscal year.
     */
    public function getPreviousYear(): ?FiscalYear
    {
        return $this->previousYear;
    }

    /**
     * Check if this is the first fiscal year.
     */
    public function isFirstYear(): bool
    {
        return $this->previousYear === null;
    }
}
```

---

## ۲. Observers

### ۲.۱ DocumentObserver

```php
<?php

namespace YourVendor\Accounting\Observers;

use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Models\DocumentLog;
use YourVendor\Accounting\Enums\DocumentStatus;
use YourVendor\Accounting\Enums\AuditAction;
use YourVendor\Accounting\Events\DocumentCreated;
use YourVendor\Accounting\Events\DocumentPosted;
use YourVendor\Accounting\Events\DocumentVoided;
use YourVendor\Accounting\Events\DocumentStatusChanged;
use YourVendor\Accounting\Services\BalanceService;

class DocumentObserver
{
    /**
     * Balance service instance.
     */
    protected BalanceService $balanceService;

    /**
     * Create a new observer instance.
     */
    public function __construct(BalanceService $balanceService)
    {
        $this->balanceService = $balanceService;
    }

    /**
     * Handle the Document "creating" event.
     */
    public function creating(Document $document): void
    {
        // تنظیم کاربر ایجادکننده
        if (!$document->created_by) {
            $document->created_by = auth()->id();
        }

        // تنظیم وضعیت پیش‌فرض
        if (!$document->status) {
            $document->status = DocumentStatus::DRAFT;
        }
    }

    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        // ثبت لاگ
        $this->log($document, AuditAction::CREATED, null, $document->toArray());

        // Fire event
        event(new DocumentCreated($document));
    }

    /**
     * Handle the Document "updating" event.
     */
    public function updating(Document $document): void
    {
        // ذخیره مقادیر قبلی برای لاگ
        $document->_oldValues = $document->getOriginal();
        $document->_oldStatus = DocumentStatus::tryFrom($document->getOriginal('status'));
    }

    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        $oldStatus = $document->_oldStatus ?? null;
        $newStatus = $document->status;

        // بررسی تغییر وضعیت
        if ($oldStatus && $oldStatus !== $newStatus) {
            $this->handleStatusChange($document, $oldStatus, $newStatus);
        } else {
            // ویرایش معمولی
            $this->log(
                $document,
                AuditAction::UPDATED,
                $document->_oldValues ?? [],
                $document->toArray()
            );
        }
    }

    /**
     * Handle status change.
     */
    protected function handleStatusChange(Document $document, DocumentStatus $oldStatus, DocumentStatus $newStatus): void
    {
        // تعیین نوع عملیات
        $action = match($newStatus) {
            DocumentStatus::PENDING => AuditAction::SUBMITTED,
            DocumentStatus::APPROVED => AuditAction::APPROVED,
            DocumentStatus::POSTED => AuditAction::POSTED,
            DocumentStatus::VOIDED => AuditAction::VOIDED,
            DocumentStatus::DRAFT => $oldStatus === DocumentStatus::PENDING 
                ? AuditAction::REJECTED 
                : AuditAction::UPDATED,
            default => AuditAction::UPDATED,
        };

        // ثبت لاگ
        $this->log(
            $document,
            $action,
            ['status' => $oldStatus->value],
            ['status' => $newStatus->value]
        );

        // Fire event
        event(new DocumentStatusChanged($document, $oldStatus, $newStatus));

        // عملیات خاص برای هر وضعیت
        match($newStatus) {
            DocumentStatus::POSTED => $this->handlePosted($document),
            DocumentStatus::VOIDED => $this->handleVoided($document),
            default => null,
        };
    }

    /**
     * Handle document posted.
     */
    protected function handlePosted(Document $document): void
    {
        // بروزرسانی مانده حساب‌ها
        $this->balanceService->updateAfterDocument($document);

        // Fire event
        event(new DocumentPosted($document));
    }

    /**
     * Handle document voided.
     */
    protected function handleVoided(Document $document): void
    {
        // معکوس کردن اثر بر مانده‌ها
        $this->balanceService->reverseDocument($document);

        // Fire event
        $reason = $this->extractVoidReason($document);
        event(new DocumentVoided($document, $reason));
    }

    /**
     * Extract void reason from notes.
     */
    protected function extractVoidReason(Document $document): string
    {
        if (!$document->notes) {
            return '';
        }

        if (preg_match('/دلیل ابطال:\s*(.+)$/m', $document->notes, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * Handle the Document "deleting" event.
     */
    public function deleting(Document $document): bool
    {
        // جلوگیری از حذف سند ثبت شده
        if ($document->status === DocumentStatus::POSTED) {
            return false;
        }

        return true;
    }

    /**
     * Handle the Document "deleted" event.
     */
    public function deleted(Document $document): void
    {
        // لاگ حذف (برای soft delete)
        if (!$document->isForceDeleting()) {
            $this->log($document, AuditAction::UPDATED, $document->toArray(), null);
        }
    }

    /**
     * Handle the Document "restored" event.
     */
    public function restored(Document $document): void
    {
        $this->log($document, AuditAction::RESTORED, null, $document->toArray());
    }

    /**
     * Log an action.
     */
    protected function log(Document $document, AuditAction $action, ?array $oldValues, ?array $newValues): void
    {
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => auth()->id(),
            'action' => $action->value,
            'description' => $this->getDescription($action, $document),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Get description for action.
     */
    protected function getDescription(AuditAction $action, Document $document): string
    {
        $number = $document->number;
        
        return match($action) {
            AuditAction::CREATED => "سند شماره {$number} ایجاد شد",
            AuditAction::UPDATED => "سند شماره {$number} ویرایش شد",
            AuditAction::SUBMITTED => "سند شماره {$number} برای تأیید ارسال شد",
            AuditAction::APPROVED => "سند شماره {$number} تأیید شد",
            AuditAction::REJECTED => "سند شماره {$number} رد شد",
            AuditAction::POSTED => "سند شماره {$number} ثبت قطعی شد",
            AuditAction::VOIDED => "سند شماره {$number} ابطال شد",
            AuditAction::RESTORED => "سند شماره {$number} بازیابی شد",
        };
    }
}
```

---

### ۲.۲ AccountObserver

```php
<?php

namespace YourVendor\Accounting\Observers;

use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Events\AccountCreated;
use YourVendor\Accounting\Events\BalanceUpdated;
use YourVendor\Accounting\Enums\AccountNature;

class AccountObserver
{
    /**
     * Handle the Account "creating" event.
     */
    public function creating(Account $account): void
    {
        // تنظیم ماهیت پیش‌فرض بر اساس نوع
        if (!$account->nature && $account->type) {
            $account->nature = $account->type->defaultNature();
        }

        // تنظیم allow_direct_posting بر اساس سطح
        if (!isset($account->allow_direct_posting)) {
            $account->allow_direct_posting = $account->level === 3;
        }

        // مقداردهی اولیه مانده
        if (!isset($account->cached_balance)) {
            $account->cached_balance = 0;
        }
    }

    /**
     * Handle the Account "created" event.
     */
    public function created(Account $account): void
    {
        event(new AccountCreated($account));
    }

    /**
     * Handle the Account "updating" event.
     */
    public function updating(Account $account): void
    {
        // ذخیره مانده قبلی
        $account->_oldBalance = $account->getOriginal('cached_balance');
    }

    /**
     * Handle the Account "updated" event.
     */
    public function updated(Account $account): void
    {
        // بررسی تغییر مانده
        if ($account->wasChanged('cached_balance')) {
            $oldBalance = $account->_oldBalance ?? 0;
            $newBalance = $account->cached_balance;

            event(new BalanceUpdated($account, $oldBalance, $newBalance));
        }
    }

    /**
     * Handle the Account "deleting" event.
     */
    public function deleting(Account $account): bool
    {
        // جلوگیری از حذف حساب سیستمی
        if ($account->is_system) {
            return false;
        }

        // جلوگیری از حذف حساب دارای تراکنش
        if ($account->items()->exists()) {
            return false;
        }

        // جلوگیری از حذف حساب دارای زیرمجموعه
        if ($account->children()->exists()) {
            return false;
        }

        return true;
    }
}
```

---

### ۲.۳ FiscalYearObserver

```php
<?php

namespace YourVendor\Accounting\Observers;

use YourVendor\Accounting\Models\FiscalYear;
use YourVendor\Accounting\Enums\FiscalYearStatus;
use YourVendor\Accounting\Events\FiscalYearOpened;
use YourVendor\Accounting\Events\FiscalYearClosed;

class FiscalYearObserver
{
    /**
     * Handle the FiscalYear "updating" event.
     */
    public function updating(FiscalYear $fiscalYear): void
    {
        $fiscalYear->_oldStatus = FiscalYearStatus::tryFrom($fiscalYear->getOriginal('status'));
    }

    /**
     * Handle the FiscalYear "updated" event.
     */
    public function updated(FiscalYear $fiscalYear): void
    {
        $oldStatus = $fiscalYear->_oldStatus ?? null;
        $newStatus = $fiscalYear->status;

        if (!$oldStatus || $oldStatus === $newStatus) {
            return;
        }

        // سال مالی فعال شد
        if ($newStatus === FiscalYearStatus::ACTIVE) {
            $previousYear = FiscalYear::where('end_date', '<', $fiscalYear->start_date)
                ->orderByDesc('end_date')
                ->first();

            event(new FiscalYearOpened($fiscalYear, $previousYear));
        }

        // سال مالی بسته شد
        if ($newStatus === FiscalYearStatus::CLOSED && $oldStatus === FiscalYearStatus::ACTIVE) {
            event(new FiscalYearClosed($fiscalYear));
        }
    }

    /**
     * Handle the FiscalYear "deleting" event.
     */
    public function deleting(FiscalYear $fiscalYear): bool
    {
        // جلوگیری از حذف سال مالی دارای سند
        if ($fiscalYear->documents()->exists()) {
            return false;
        }

        // جلوگیری از حذف سال مالی فعال
        if ($fiscalYear->status === FiscalYearStatus::ACTIVE) {
            return false;
        }

        return true;
    }
}
```

---

## ۳. ثبت Observers در ServiceProvider

```php
<?php

namespace YourVendor\Accounting;

use Illuminate\Support\ServiceProvider;
use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Models\FiscalYear;
use YourVendor\Accounting\Observers\DocumentObserver;
use YourVendor\Accounting\Observers\AccountObserver;
use YourVendor\Accounting\Observers\FiscalYearObserver;

class AccountingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // ثبت Observers
        Document::observe(DocumentObserver::class);
        Account::observe(AccountObserver::class);
        FiscalYear::observe(FiscalYearObserver::class);

        // سایر تنظیمات...
    }
}
```

---

## ۴. Event Listeners (نمونه برای پروژه)

### ۴.۱ ارسال ایمیل پس از ثبت سند

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use YourVendor\Accounting\Events\DocumentPosted;
use App\Mail\InvoiceMail;
use App\Models\User;

class SendInvoiceEmail implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(DocumentPosted $event): void
    {
        $document = $event->getDocument();

        // فقط برای فروش
        if ($document->type !== 'sale') {
            return;
        }

        // یافتن مشتری
        $customerAccount = $document->items
            ->first(fn($item) => $item->account->entity_type === 'user');

        if (!$customerAccount) {
            return;
        }

        $customer = User::find($customerAccount->account->entity_id);

        if ($customer && $customer->email) {
            Mail::to($customer)->send(new InvoiceMail($document));
        }
    }
}
```

---

### ۴.۲ اطلاع‌رسانی به مدیر

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use YourVendor\Accounting\Events\DocumentVoided;
use App\Models\User;
use App\Notifications\DocumentVoidedNotification;

class NotifyManagerOnVoid implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(DocumentVoided $event): void
    {
        $document = $event->getDocument();
        $reason = $event->getReason();

        // یافتن مدیران
        $managers = User::role('manager')->get();

        // ارسال اعلان
        Notification::send($managers, new DocumentVoidedNotification($document, $reason));
    }
}
```

---

### ۴.۳ بروزرسانی داشبورد

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use YourVendor\Accounting\Events\DocumentPosted;
use App\Services\DashboardService;

class UpdateDashboardCache
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(DocumentPosted $event): void
    {
        // پاکسازی کش داشبورد
        Cache::tags(['dashboard', 'accounting'])->flush();

        // بروزرسانی آمار امروز
        $this->dashboardService->refreshTodayStats();
    }
}
```

---

### ۴.۴ بررسی مانده غیرطبیعی

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use YourVendor\Accounting\Events\BalanceUpdated;
use App\Models\User;
use App\Notifications\AbnormalBalanceNotification;

class CheckAbnormalBalance
{
    /**
     * Handle the event.
     */
    public function handle(BalanceUpdated $event): void
    {
        $account = $event->getAccount();

        // بررسی مانده منفی برای دارایی‌ها
        if ($account->type->value === 'asset' && $event->becameNegative()) {
            $this->reportAbnormalBalance($account, $event->newBalance);
        }

        // بررسی موجودی کم صندوق
        if ($account->entity_type === 'cashier' && $event->newBalance < 100000) {
            $this->reportLowCashBalance($account, $event->newBalance);
        }
    }

    protected function reportAbnormalBalance($account, $balance): void
    {
        Log::warning("Abnormal balance detected", [
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_title' => $account->title,
            'balance' => $balance,
        ]);

        $admins = User::role('admin')->get();
        Notification::send($admins, new AbnormalBalanceNotification($account, $balance));
    }

    protected function reportLowCashBalance($account, $balance): void
    {
        Log::info("Low cash balance", [
            'account_id' => $account->id,
            'balance' => $balance,
        ]);
    }
}
```

---

### ۴.۵ ثبت در لاگ پروژه

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use YourVendor\Accounting\Events\DocumentPosted;
use YourVendor\Accounting\Events\DocumentVoided;
use YourVendor\Accounting\Events\FiscalYearClosed;

class LogAccountingEvents
{
    /**
     * Handle DocumentPosted.
     */
    public function handleDocumentPosted(DocumentPosted $event): void
    {
        $document = $event->getDocument();

        Log::channel('accounting')->info('Document posted', [
            'document_id' => $document->id,
            'document_number' => $document->number,
            'type' => $document->type,
            'amount' => $event->getTotalAmount(),
            'user_id' => $event->getPosterId(),
        ]);
    }

    /**
     * Handle DocumentVoided.
     */
    public function handleDocumentVoided(DocumentVoided $event): void
    {
        $document = $event->getDocument();

        Log::channel('accounting')->warning('Document voided', [
            'document_id' => $document->id,
            'document_number' => $document->number,
            'reason' => $event->getReason(),
            'user_id' => $event->getVoidedById(),
        ]);
    }

    /**
     * Handle FiscalYearClosed.
     */
    public function handleFiscalYearClosed(FiscalYearClosed $event): void
    {
        $fiscalYear = $event->getFiscalYear();

        Log::channel('accounting')->info('Fiscal year closed', [
            'fiscal_year_id' => $fiscalYear->id,
            'title' => $fiscalYear->title,
            'documents_count' => $event->getDocumentsCount(),
        ]);
    }

    /**
     * Subscribe to events.
     */
    public function subscribe($events): array
    {
        return [
            DocumentPosted::class => 'handleDocumentPosted',
            DocumentVoided::class => 'handleDocumentVoided',
            FiscalYearClosed::class => 'handleFiscalYearClosed',
        ];
    }
}
```

---

## ۵. ثبت Listeners در پروژه

### ۵.۱ در EventServiceProvider

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use YourVendor\Accounting\Events\DocumentPosted;
use YourVendor\Accounting\Events\DocumentVoided;
use YourVendor\Accounting\Events\BalanceUpdated;
use YourVendor\Accounting\Events\FiscalYearClosed;
use App\Listeners\SendInvoiceEmail;
use App\Listeners\NotifyManagerOnVoid;
use App\Listeners\UpdateDashboardCache;
use App\Listeners\CheckAbnormalBalance;
use App\Listeners\LogAccountingEvents;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     */
    protected $listen = [
        DocumentPosted::class => [
            SendInvoiceEmail::class,
            UpdateDashboardCache::class,
        ],
        DocumentVoided::class => [
            NotifyManagerOnVoid::class,
        ],
        BalanceUpdated::class => [
            CheckAbnormalBalance::class,
        ],
    ];

    /**
     * The subscriber classes to register.
     */
    protected $subscribe = [
        LogAccountingEvents::class,
    ];
}
```

---

## ۶. خلاصه

### ۶.۱ Events

| Event | زمان Fire شدن | Payload |
|-------|---------------|---------|
| DocumentCreated | پس از ایجاد سند | document |
| DocumentPosted | پس از ثبت قطعی | document |
| DocumentVoided | پس از ابطال | document, reason |
| DocumentStatusChanged | پس از تغییر وضعیت | document, oldStatus, newStatus |
| AccountCreated | پس از ایجاد حساب | account |
| BalanceUpdated | پس از تغییر مانده | account, oldBalance, newBalance |
| FiscalYearOpened | پس از فعال‌سازی سال | fiscalYear, previousYear |
| FiscalYearClosed | پس از بستن سال | fiscalYear |

### ۶.۲ Observers

| Observer | Model | عملیات |
|----------|-------|--------|
| DocumentObserver | Document | لاگ، بروزرسانی مانده، events |
| AccountObserver | Account | تنظیم پیش‌فرض‌ها، events |
| FiscalYearObserver | FiscalYear | events، جلوگیری از حذف |

---

[→ ادامه: پیاده‌سازی - Config & Provider (14g-config-provider.md)](14g-config-provider.md)

[← بازگشت: پیاده‌سازی - Enums (14e-enums.md)](14e-enums.md)

[⌂ فهرست (00-index.md)](../00-index.md)
