# 12-security.md

# امنیت

## Security

---

## مقدمه

این بخش جنبه‌های امنیتی پکیج حسابداری را شرح می‌دهد. شامل Audit Trail، محافظت از داده‌ها، کنترل دسترسی و اعتبارسنجی.

---

## ۱. Audit Trail

### ۱.۱ تعریف

Audit Trail ثبت کامل تمام تغییرات روی اسناد حسابداری است. شامل:
- چه کسی تغییر داد
- چه زمانی تغییر داد
- چه چیزی تغییر کرد
- از چه مقداری به چه مقداری

### ۱.۲ جدول document_logs

| فیلد | نوع | شرح |
|------|-----|-----|
| id | bigint | شناسه |
| document_id | bigint | شناسه سند |
| user_id | bigint | شناسه کاربر |
| action | enum | نوع عملیات |
| description | string | توضیح |
| old_values | json | مقادیر قبلی |
| new_values | json | مقادیر جدید |
| ip_address | string | آدرس IP |
| user_agent | string | مشخصات مرورگر |
| created_at | timestamp | زمان |

### ۱.۳ انواع عملیات

| عملیات | شرح |
|--------|-----|
| created | ایجاد سند |
| updated | ویرایش سند |
| submitted | ارسال برای تأیید |
| approved | تأیید سند |
| rejected | رد سند |
| posted | ثبت قطعی |
| voided | ابطال |
| restored | بازیابی |

### ۱.۴ ثبت خودکار

Audit به صورت خودکار توسط Observer ثبت می‌شود:

```php
<?php

namespace YourVendor\Accounting\Observers;

use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Models\DocumentLog;

class DocumentObserver
{
    public function created(Document $document): void
    {
        $this->log($document, 'created', null, $document->toArray());
    }
    
    public function updating(Document $document): void
    {
        $document->_oldValues = $document->getOriginal();
    }
    
    public function updated(Document $document): void
    {
        if ($document->wasChanged('status')) {
            $action = $this->getStatusAction($document->status);
            $this->log($document, $action, $document->_oldValues, $document->toArray());
        } else {
            $this->log($document, 'updated', $document->_oldValues, $document->toArray());
        }
    }
    
    private function log(Document $document, string $action, ?array $old, ?array $new): void
    {
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $this->getDescription($action, $document),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
    
    private function getStatusAction(string $status): string
    {
        return match($status) {
            'pending' => 'submitted',
            'approved' => 'approved',
            'posted' => 'posted',
            'voided' => 'voided',
            default => 'updated',
        };
    }
    
    private function getDescription(string $action, Document $document): string
    {
        return __("accounting::accounting.audit_actions.{$action}") . 
               " - سند شماره {$document->number}";
    }
}
```

### ۱.۵ مشاهده تاریخچه

```php
// تاریخچه یک سند
$logs = $document->logs()->with('user')->orderBy('created_at')->get();

foreach ($logs as $log) {
    echo "{$log->created_at} | ";
    echo "{$log->user->name} | ";
    echo "{$log->action} | ";
    echo "{$log->ip_address}\n";
}
```

### ۱.۶ گزارش فعالیت کاربر

```php
// فعالیت یک کاربر
$activities = DocumentLog::where('user_id', $userId)
    ->with('document')
    ->whereBetween('created_at', [$from, $to])
    ->orderByDesc('created_at')
    ->get();
```

### ۱.۷ جستجوی تغییرات

```php
// یافتن تغییرات یک فیلد خاص
$changes = DocumentLog::where('action', 'updated')
    ->whereJsonContains('old_values->description', 'متن قبلی')
    ->get();

// یافتن اسناد ابطال شده
$voidedDocs = DocumentLog::where('action', 'voided')
    ->with('document')
    ->get();
```

---

## ۲. محافظت از اسناد ثبت شده

### ۲.۱ قانون اصلی

**سند ثبت شده (posted) قابل ویرایش و حذف نیست.**

### ۲.۲ پیاده‌سازی در Model

```php
<?php

namespace YourVendor\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Exceptions\DocumentNotEditableException;

class Document extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Document $document) {
            if ($document->getOriginal('status') === 'posted') {
                // فقط تغییر به voided مجاز است
                if ($document->status !== 'voided') {
                    throw new DocumentNotEditableException($document);
                }
            }
        });
        
        static::deleting(function (Document $document) {
            if ($document->status === 'posted') {
                throw new DocumentNotEditableException($document);
            }
        });
    }
    
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'pending']);
    }
    
    public function isDeletable(): bool
    {
        return $this->status === 'draft';
    }
    
    public function isVoidable(): bool
    {
        return $this->status === 'posted';
    }
}
```

### ۲.۳ بررسی قبل از عملیات

```php
// در Controller
public function update(Request $request, Document $document)
{
    if (!$document->isEditable()) {
        return back()->withError(__('accounting::accounting.messages.document_not_editable'));
    }
    
    // ادامه ویرایش
}

public function destroy(Document $document)
{
    if (!$document->isDeletable()) {
        return back()->withError(__('accounting::accounting.messages.document_not_deletable'));
    }
    
    $document->delete();
}
```

### ۲.۴ ابطال سند

تنها راه اصلاح سند ثبت شده، ابطال و ثبت سند جدید است:

```php
public function voidDocument(Document $document, string $reason): void
{
    if (!$document->isVoidable()) {
        throw new InvalidDocumentStatusException($document);
    }
    
    DB::transaction(function () use ($document, $reason) {
        // ابطال سند
        $document->update([
            'status' => 'voided',
            'notes' => ($document->notes ?? '') . "\n\nدلیل ابطال: {$reason}",
        ]);
        
        // معکوس کردن اثر بر مانده‌ها
        app(BalanceService::class)->reverseDocument($document);
        
        // ثبت در Audit
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => auth()->id(),
            'action' => 'voided',
            'description' => "ابطال: {$reason}",
        ]);
    });
}
```

---

## ۳. محدودیت سال مالی

### ۳.۱ قوانین

| قانون | شرح |
|-------|-----|
| تاریخ سند | باید در بازه سال مالی باشد |
| سال بسته | ثبت سند جدید ممنوع |
| یک سال فعال | در هر لحظه فقط یک سال فعال |

### ۳.۲ بررسی در DocumentService

```php
<?php

namespace YourVendor\Accounting\Services;

use YourVendor\Accounting\Exceptions\ClosedFiscalYearException;
use YourVendor\Accounting\Models\FiscalYear;
use Carbon\Carbon;

class DocumentService
{
    public function create(array $data): Document
    {
        $date = Carbon::parse($data['date']);
        $fiscalYear = $this->getFiscalYearForDate($date);
        
        // بررسی وجود سال مالی
        if (!$fiscalYear) {
            throw new \Exception("سال مالی برای تاریخ {$date->format('Y-m-d')} یافت نشد.");
        }
        
        // بررسی وضعیت سال مالی
        if ($fiscalYear->status === 'closed') {
            throw new ClosedFiscalYearException($fiscalYear);
        }
        
        if ($fiscalYear->status === 'draft') {
            throw new \Exception("سال مالی هنوز فعال نشده است.");
        }
        
        $data['fiscal_year_id'] = $fiscalYear->id;
        
        return $this->createDocument($data);
    }
    
    private function getFiscalYearForDate(Carbon $date): ?FiscalYear
    {
        return FiscalYear::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();
    }
}
```

### ۳.۳ Validation Rule

```php
<?php

namespace YourVendor\Accounting\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use YourVendor\Accounting\Models\FiscalYear;
use Carbon\Carbon;

class ValidFiscalYearDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $date = Carbon::parse($value);
        
        $fiscalYear = FiscalYear::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();
        
        if (!$fiscalYear) {
            $fail(__('accounting::accounting.validation.date_out_of_fiscal_year'));
            return;
        }
        
        if ($fiscalYear->status === 'closed') {
            $fail(__('accounting::accounting.messages.fiscal_year_closed_error'));
            return;
        }
        
        if ($fiscalYear->status !== 'active') {
            $fail('سال مالی فعال نیست.');
        }
    }
}
```

استفاده:

```php
$request->validate([
    'date' => ['required', 'date', new ValidFiscalYearDate()],
]);
```

### ۳.۴ محافظت از سال مالی بسته

```php
class FiscalYear extends Model
{
    public function close(): void
    {
        if ($this->status !== 'active') {
            throw new \Exception('فقط سال مالی فعال قابل بستن است.');
        }
        
        // بررسی اسناد پیش‌نویس
        $draftCount = $this->documents()->where('status', 'draft')->count();
        if ($draftCount > 0) {
            throw new \Exception("تعداد {$draftCount} سند پیش‌نویس وجود دارد.");
        }
        
        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }
    
    public function reopen(): void
    {
        if ($this->status !== 'closed') {
            throw new \Exception('فقط سال مالی بسته قابل بازگشایی است.');
        }
        
        // غیرفعال کردن سال فعال فعلی
        FiscalYear::where('status', 'active')->update(['status' => 'closed']);
        
        $this->update([
            'status' => 'active',
            'closed_at' => null,
        ]);
    }
}
```

---

## ۴. محافظت از حساب‌های سیستمی

### ۴.۱ تعریف حساب سیستمی

حساب‌های سیستمی حساب‌هایی هستند که:
- توسط Seeder ایجاد می‌شوند
- برای عملکرد سیستم ضروری هستند
- قابل حذف یا تغییر اساسی نیستند

### ۴.۲ پیاده‌سازی

```php
class Account extends Model
{
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
            
            // بررسی وجود تراکنش
            if ($account->items()->exists()) {
                throw new \Exception('حساب دارای تراکنش است و قابل حذف نیست.');
            }
        });
    }
    
    public function isSystemAccount(): bool
    {
        return $this->is_system;
    }
    
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
}
```

### ۴.۳ علامت‌گذاری در Seeder

```php
class DefaultAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'code' => '1',
                'title' => 'دارایی‌ها',
                'is_system' => true,  // حساب سیستمی
                // ...
            ],
            // ...
        ];
    }
}
```

---

## ۵. کنترل دسترسی (Authorization)

### ۵.۱ Permission ها

| Permission | شرح |
|------------|-----|
| accounting.view | مشاهده اسناد و حساب‌ها |
| accounting.create | ایجاد سند |
| accounting.edit | ویرایش سند پیش‌نویس |
| accounting.post | ثبت قطعی سند |
| accounting.void | ابطال سند |
| accounting.approve | تأیید سند |
| accounting.reports | مشاهده گزارش‌ها |
| accounting.settings | تنظیمات حسابداری |
| accounting.fiscal-year | مدیریت سال مالی |
| accounting.accounts | مدیریت حساب‌ها |

### ۵.۲ Policy برای Document

```php
<?php

namespace App\Policies;

use App\Models\User;
use YourVendor\Accounting\Models\Document;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('accounting.view');
    }
    
    public function view(User $user, Document $document): bool
    {
        if (!$user->hasPermission('accounting.view')) {
            return false;
        }
        
        // بررسی شعبه
        if ($user->branch_id && $document->branch_id !== $user->branch_id) {
            return false;
        }
        
        return true;
    }
    
    public function create(User $user): bool
    {
        return $user->hasPermission('accounting.create');
    }
    
    public function update(User $user, Document $document): bool
    {
        if (!$user->hasPermission('accounting.edit')) {
            return false;
        }
        
        if (!$document->isEditable()) {
            return false;
        }
        
        // فقط سازنده یا ادمین
        if ($document->created_by !== $user->id && !$user->isAdmin()) {
            return false;
        }
        
        return true;
    }
    
    public function delete(User $user, Document $document): bool
    {
        if (!$document->isDeletable()) {
            return false;
        }
        
        return $document->created_by === $user->id || $user->isAdmin();
    }
    
    public function post(User $user, Document $document): bool
    {
        if (!$user->hasPermission('accounting.post')) {
            return false;
        }
        
        return in_array($document->status, ['draft', 'approved']);
    }
    
    public function approve(User $user, Document $document): bool
    {
        if (!$user->hasPermission('accounting.approve')) {
            return false;
        }
        
        return $document->status === 'pending';
    }
    
    public function void(User $user, Document $document): bool
    {
        if (!$user->hasPermission('accounting.void')) {
            return false;
        }
        
        return $document->isVoidable();
    }
}
```

### ۵.۳ Policy برای Account

```php
<?php

namespace App\Policies;

use App\Models\User;
use YourVendor\Accounting\Models\Account;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('accounting.view');
    }
    
    public function view(User $user, Account $account): bool
    {
        return $user->hasPermission('accounting.view');
    }
    
    public function create(User $user): bool
    {
        return $user->hasPermission('accounting.accounts');
    }
    
    public function update(User $user, Account $account): bool
    {
        if (!$user->hasPermission('accounting.accounts')) {
            return false;
        }
        
        // حساب سیستمی محدودیت بیشتری دارد
        if ($account->is_system && !$user->isAdmin()) {
            return false;
        }
        
        return true;
    }
    
    public function delete(User $user, Account $account): bool
    {
        if (!$user->hasPermission('accounting.accounts')) {
            return false;
        }
        
        return $account->canDelete();
    }
}
```

### ۵.۴ ثبت Policy ها

در `AuthServiceProvider`:

```php
protected $policies = [
    \YourVendor\Accounting\Models\Document::class => \App\Policies\DocumentPolicy::class,
    \YourVendor\Accounting\Models\Account::class => \App\Policies\AccountPolicy::class,
];
```

### ۵.۵ استفاده در Controller

```php
class DocumentController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Document::class);
        
        $documents = Document::paginate();
        return view('accounting.documents.index', compact('documents'));
    }
    
    public function store(Request $request)
    {
        $this->authorize('create', Document::class);
        
        // ایجاد سند
    }
    
    public function post(Document $document)
    {
        $this->authorize('post', $document);
        
        $document->post();
        
        return back()->withSuccess(__('accounting::accounting.messages.document_posted'));
    }
    
    public function void(Request $request, Document $document)
    {
        $this->authorize('void', $document);
        
        $document->void($request->reason);
        
        return back()->withSuccess(__('accounting::accounting.messages.document_voided'));
    }
}
```

### ۵.۶ استفاده در Blade

```html
@can('create', \YourVendor\Accounting\Models\Document::class)
    <a href="{{ route('accounting.documents.create') }}">سند جدید</a>
@endcan

@can('post', $document)
    <button type="submit">ثبت قطعی</button>
@endcan

@can('void', $document)
    <button type="submit" class="text-red-500">ابطال</button>
@endcan
```

---

## ۶. اعتبارسنجی (Validation)

### ۶.۱ قوانین اساسی

| قانون | شرح |
|-------|-----|
| بالانس سند | مجموع بدهکار = مجموع بستانکار |
| حساب فعال | حساب باید فعال باشد |
| سطح حساب | فقط سطح تفصیلی قابل ثبت |
| مبلغ مثبت | مبلغ باید بزرگ‌تر از صفر باشد |
| تاریخ معتبر | تاریخ در بازه سال مالی |

### ۶.۲ DocumentValidator

```php
<?php

namespace YourVendor\Accounting\Validators;

use YourVendor\Accounting\Exceptions\UnbalancedDocumentException;
use YourVendor\Accounting\Exceptions\InactiveAccountException;
use YourVendor\Accounting\Models\Account;

class DocumentValidator
{
    public function validate(array $data): array
    {
        $errors = [];
        
        // بررسی فیلدهای اصلی
        if (empty($data['date'])) {
            $errors['date'] = __('accounting::accounting.validation.date_required');
        }
        
        if (empty($data['type'])) {
            $errors['type'] = __('accounting::accounting.validation.type_required');
        }
        
        // بررسی آیتم‌ها
        if (empty($data['items']) || count($data['items']) < 2) {
            $errors['items'] = __('accounting::accounting.validation.items_required', ['min' => 2]);
        } else {
            $itemErrors = $this->validateItems($data['items']);
            $errors = array_merge($errors, $itemErrors);
        }
        
        // بررسی بالانس
        if (empty($errors) && !$this->isBalanced($data['items'])) {
            $totals = $this->calculateTotals($data['items']);
            $errors['balance'] = __('accounting::accounting.validation.document_not_balanced', [
                'debit' => number_format($totals['debit']),
                'credit' => number_format($totals['credit']),
            ]);
        }
        
        return $errors;
    }
    
    private function validateItems(array $items): array
    {
        $errors = [];
        
        foreach ($items as $index => $item) {
            // بررسی حساب
            if (empty($item['account_id'])) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.validation.account_required');
                continue;
            }
            
            $account = Account::find($item['account_id']);
            
            if (!$account) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.validation.account_invalid');
                continue;
            }
            
            if (!$account->is_active) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.messages.account_inactive');
            }
            
            if ($account->level !== 3) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.messages.account_not_postable');
            }
            
            // بررسی مبلغ
            if (empty($item['amount']) || $item['amount'] <= 0) {
                $errors["items.{$index}.amount"] = __('accounting::accounting.validation.amount_positive');
            }
            
            $maxAmount = config('accounting.validation.max_amount');
            if ($maxAmount && $item['amount'] > $maxAmount) {
                $errors["items.{$index}.amount"] = __('accounting::accounting.validation.amount_max', [
                    'max' => number_format($maxAmount),
                ]);
            }
        }
        
        return $errors;
    }
    
    private function isBalanced(array $items): bool
    {
        $totals = $this->calculateTotals($items);
        return abs($totals['debit'] - $totals['credit']) < 0.01;
    }
    
    private function calculateTotals(array $items): array
    {
        $debit = 0;
        $credit = 0;
        
        foreach ($items as $item) {
            if (($item['sign'] ?? 1) === 1) {
                $debit += $item['amount'] ?? 0;
            } else {
                $credit += $item['amount'] ?? 0;
            }
        }
        
        return ['debit' => $debit, 'credit' => $credit];
    }
}
```

### ۶.۳ Form Request

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use YourVendor\Accounting\Rules\ValidFiscalYearDate;
use YourVendor\Accounting\Rules\BalancedDocument;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Document::class);
    }
    
    public function rules(): array
    {
        $allowedTypes = implode(',', config('accounting.document.allowed_types'));
        
        return [
            'date' => ['required', 'date', new ValidFiscalYearDate()],
            'type' => "required|in:{$allowedTypes}",
            'description' => 'nullable|string|max:500',
            'branch_id' => 'nullable|exists:branches,id',
            'items' => ['required', 'array', 'min:2', new BalancedDocument()],
            'items.*.account_id' => 'required|exists:accounts,id',
            'items.*.amount' => 'required|numeric|min:0.01',
            'items.*.sign' => 'required|in:1,-1',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.cost_center_id' => 'nullable|exists:cost_centers,id',
        ];
    }
    
    public function messages(): array
    {
        return [
            'date.required' => __('accounting::accounting.validation.date_required'),
            'type.required' => __('accounting::accounting.validation.type_required'),
            'items.required' => __('accounting::accounting.validation.items_required', ['min' => 2]),
            'items.*.amount.min' => __('accounting::accounting.validation.amount_positive'),
        ];
    }
}
```

### ۶.۴ Rule سفارشی بالانس

```php
<?php

namespace YourVendor\Accounting\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BalancedDocument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('آیتم‌ها باید آرایه باشند.');
            return;
        }
        
        $debit = 0;
        $credit = 0;
        
        foreach ($value as $item) {
            $amount = $item['amount'] ?? 0;
            $sign = $item['sign'] ?? 1;
            
            if ($sign === 1) {
                $debit += $amount;
            } else {
                $credit += $amount;
            }
        }
        
        if (abs($debit - $credit) >= 0.01) {
            $fail(__('accounting::accounting.validation.document_not_balanced', [
                'debit' => number_format($debit),
                'credit' => number_format($credit),
            ]));
        }
    }
}
```

---

## ۷. محافظت از مانده‌ها

### ۷.۱ بررسی موجودی قبل از ثبت

```php
class BalanceGuard
{
    public function check(array $items): array
    {
        $warnings = [];
        
        foreach ($items as $item) {
            $account = Account::find($item['account_id']);
            
            // برای حساب‌های دارایی (مثل صندوق و بانک)
            if ($account->type === 'asset' && $item['sign'] === -1) {
                $projectedBalance = $account->cached_balance - $item['amount'];
                
                if ($projectedBalance < 0 && !config('accounting.account.allow_negative_balance')) {
                    $warnings[] = [
                        'account' => $account,
                        'message' => __('accounting::accounting.messages.insufficient_balance'),
                        'current_balance' => $account->cached_balance,
                        'required' => $item['amount'],
                    ];
                }
            }
        }
        
        return $warnings;
    }
    
    public function enforce(array $items): void
    {
        $warnings = $this->check($items);
        
        if (!empty($warnings)) {
            throw new InsufficientBalanceException($warnings[0]['account'], $warnings[0]['required']);
        }
    }
}
```

### ۷.۲ استفاده در Service

```php
class DocumentService
{
    public function __construct(
        private BalanceGuard $balanceGuard
    ) {}
    
    public function post(Document $document): void
    {
        // بررسی موجودی
        $this->balanceGuard->enforce($document->items->toArray());
        
        // ثبت سند
        $document->update(['status' => 'posted', 'posted_at' => now()]);
        
        // بروزرسانی مانده‌ها
        app(BalanceService::class)->updateAfterDocument($document);
    }
}
```

---

## ۸. محافظت در سطح دیتابیس

### ۸.۱ Trigger برای بالانس

```sql
-- MySQL Trigger
DELIMITER //

CREATE TRIGGER check_document_balance
BEFORE UPDATE ON documents
FOR EACH ROW
BEGIN
    DECLARE total_balance DECIMAL(15,2);
    
    IF NEW.status = 'posted' AND OLD.status != 'posted' THEN
        SELECT SUM(amount * sign) INTO total_balance
        FROM document_items
        WHERE document_id = NEW.id;
        
        IF ABS(total_balance) > 0.01 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Document is not balanced';
        END IF;
    END IF;
END //

DELIMITER ;
```

### ۸.۲ Constraint برای تاریخ

```sql
-- Check constraint (MySQL 8+)
ALTER TABLE documents
ADD CONSTRAINT check_date_in_fiscal_year
CHECK (
    date >= (SELECT start_date FROM fiscal_years WHERE id = fiscal_year_id)
    AND date <= (SELECT end_date FROM fiscal_years WHERE id = fiscal_year_id)
);
```

### ۸.۳ Foreign Key با Restrict

```php
// Migration
$table->foreignId('fiscal_year_id')
    ->constrained('fiscal_years')
    ->restrictOnDelete();  // جلوگیری از حذف سال مالی دارای سند
```

---

## ۹. Rate Limiting

### ۹.۱ محدودیت تعداد سند

```php
// در RouteServiceProvider
RateLimiter::for('accounting-documents', function (Request $request) {
    return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
});

// در Route
Route::middleware(['throttle:accounting-documents'])
    ->post('/documents', [DocumentController::class, 'store']);
```

### ۹.۲ محدودیت گزارش‌های سنگین

```php
RateLimiter::for('accounting-reports', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

---

## ۱۰. Logging امنیتی

### ۱۰.۱ لاگ عملیات حساس

```php
class SecurityLogger
{
    public function logSensitiveAction(string $action, array $data = []): void
    {
        Log::channel('accounting-security')->info($action, [
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'data' => $data,
            'timestamp' => now()->toISOString(),
        ]);
    }
}

// استفاده
$securityLogger->logSensitiveAction('document.voided', [
    'document_id' => $document->id,
    'document_number' => $document->number,
    'reason' => $reason,
]);

$securityLogger->logSensitiveAction('fiscal_year.closed', [
    'fiscal_year_id' => $fiscalYear->id,
]);
```

### ۱۰.۲ تنظیم Log Channel

در `config/logging.php`:

```php
'channels' => [
    'accounting-security' => [
        'driver' => 'daily',
        'path' => storage_path('logs/accounting-security.log'),
        'level' => 'info',
        'days' => 365,  // نگهداری یک ساله
    ],
],
```

---

## ۱۱. خلاصه نکات امنیتی

| موضوع | نکته کلیدی |
|-------|------------|
| Audit Trail | ثبت خودکار تمام تغییرات |
| سند ثبت شده | غیرقابل ویرایش و حذف |
| ابطال | تنها راه اصلاح سند ثبت شده |
| سال مالی بسته | ثبت سند ممنوع |
| حساب سیستمی | حذف و تغییر اساسی ممنوع |
| Authorization | Policy برای هر عملیات |
| Validation | بالانس، تاریخ، مبلغ، حساب |
| موجودی | بررسی قبل از ثبت |
| Rate Limiting | محدودیت تعداد درخواست |
| Logging | ثبت عملیات حساس |

---

## ۱۲. چک‌لیست امنیتی

| مورد | وضعیت |
|------|-------|
| Audit Trail فعال است | ☐ |
| Policy ها تعریف شده‌اند | ☐ |
| Validation کامل است | ☐ |
| سند ثبت شده محافظت شده | ☐ |
| سال مالی بسته محافظت شده | ☐ |
| حساب سیستمی محافظت شده | ☐ |
| Rate Limiting فعال است | ☐ |
| Log امنیتی تنظیم شده | ☐ |
| Backup منظم وجود دارد | ☐ |

---

[→ ادامه: مثال‌ها (13-examples.md)](13-examples.md)

[← بازگشت: چندزبانگی (11-multi-language.md)](11-multi-language.md)

[⌂ فهرست (00-index.md)](00-index.md)
