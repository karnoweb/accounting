# 07-integration.md

# یکپارچه‌سازی با پروژه

## Integration

---

## مقدمه

این بخش نحوه اتصال پکیج حسابداری به پروژه شما را شرح می‌دهد. شامل استفاده از Trait، ایجاد حساب، ثبت سند و واکنش به رویدادها.

---

## ۱. HasAccount Trait

### ۱.۱ معرفی

`HasAccount` یک Trait است که به مدل‌های پروژه امکان داشتن حساب حسابداری می‌دهد.

### ۱.۲ قابلیت‌ها

| قابلیت | شرح |
|--------|-----|
| ساخت خودکار حساب | هنگام ایجاد رکورد |
| دسترسی به حساب | از طریق relation |
| محاسبه مانده | دریافت مانده لحظه‌ای |
| لیست اسناد | دریافت اسناد مرتبط |

### ۱.۳ نحوه استفاده

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class User extends Model
{
    use HasAccount;
}
```

### ۱.۴ پیکربندی Trait

برای سفارشی‌سازی رفتار Trait، متدهای زیر را Override کنید:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class User extends Model
{
    use HasAccount;

    /**
     * تنظیمات حساب مرتبط
     */
    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1103',           // کد حساب والد
            'code_prefix' => 'USR',            // پیشوند کد
            'title' => $this->name,            // عنوان حساب
            'type' => 'asset',                 // نوع حساب
            'nature' => 'debit',               // ماهیت حساب
            'auto_create' => true,             // ساخت خودکار
        ];
    }

    /**
     * آیا حساب به صورت خودکار ساخته شود؟
     */
    protected function shouldCreateAccount(): bool
    {
        return true;
    }

    /**
     * عنوان حساب
     */
    protected function getAccountTitle(): string
    {
        return $this->name ?? 'کاربر ' . $this->id;
    }
}
```

---

## ۲. اتصال مدل‌های مختلف

### ۲.۱ اتصال User

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use YourVendor\Accounting\Traits\HasAccount;

class User extends Authenticatable
{
    use HasAccount;

    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1103',       // حساب‌های دریافتنی
            'title' => $this->name,
            'type' => 'asset',
            'nature' => 'debit',
        ];
    }
}
```

**کاربرد:**
- ثبت بدهی مشتری هنگام فروش
- ثبت پرداخت مشتری
- مشاهده مانده حساب مشتری

### ۲.۲ اتصال Product

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Product extends Model
{
    use HasAccount;

    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1104',       // موجودی کالا
            'title' => $this->name,
            'type' => 'asset',
            'nature' => 'debit',
        ];
    }
}
```

**کاربرد:**
- ثبت خرید کالا (افزایش موجودی)
- ثبت فروش کالا (کاهش موجودی)
- گزارش موجودی کالا

### ۲.۳ اتصال Bank

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Bank extends Model
{
    use HasAccount;

    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1102',       // بانک‌ها
            'title' => $this->name . ' - ' . $this->account_number,
            'type' => 'asset',
            'nature' => 'debit',
        ];
    }
}
```

**کاربرد:**
- ثبت واریز به حساب
- ثبت برداشت از حساب
- مشاهده موجودی بانک

### ۲.۴ اتصال Cashier (صندوق)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Cashier extends Model
{
    use HasAccount;

    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1101',       // موجودی نقد
            'title' => $this->name,
            'type' => 'asset',
            'nature' => 'debit',
        ];
    }
}
```

**کاربرد:**
- ثبت دریافت نقدی
- ثبت پرداخت نقدی
- مشاهده موجودی صندوق

### ۲.۵ اتصال Supplier (تأمین‌کننده)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YourVendor\Accounting\Traits\HasAccount;

class Supplier extends Model
{
    use HasAccount;

    protected function accountConfig(): array
    {
        return [
            'parent_code' => '2101',       // حساب‌های پرداختنی
            'title' => $this->name,
            'type' => 'liability',
            'nature' => 'credit',
        ];
    }
}
```

**کاربرد:**
- ثبت خرید از تأمین‌کننده
- ثبت پرداخت به تأمین‌کننده
- مشاهده بدهی به تأمین‌کننده

---

## ۳. API های Trait

### ۳.۱ دسترسی به حساب

```php
$user = User::find(1);

// دریافت حساب مرتبط
$account = $user->account;

// بررسی وجود حساب
if ($user->hasAccount()) {
    // ...
}

// دریافت شناسه حساب
$accountId = $user->account_id;
```

### ۳.۲ مانده حساب

```php
$user = User::find(1);

// مانده کلی
$balance = $user->balance();

// مانده در سال مالی خاص
$balance = $user->balance($fiscalYear);

// مانده تا تاریخ مشخص
$balance = $user->balanceAsOf('2024-06-30');

// مانده بدهکار و بستانکار جداگانه
$debits = $user->totalDebits();
$credits = $user->totalCredits();
```

### ۳.۳ اسناد مرتبط

```php
$user = User::find(1);

// همه اسناد
$documents = $user->documents();

// اسناد یک نوع خاص
$sales = $user->documents()->where('type', 'sale')->get();

// اسناد در بازه زمانی
$documents = $user->documents()
    ->whereBetween('date', ['2024-01-01', '2024-03-31'])
    ->get();

// آخرین سند
$lastDocument = $user->documents()->latest('date')->first();
```

### ۳.۴ گردش حساب

```php
$user = User::find(1);

// گردش حساب
$transactions = $user->transactions();

// گردش در بازه زمانی
$transactions = $user->transactions('2024-01-01', '2024-03-31');

// گردش با مانده
$statement = $user->statement('2024-01-01', '2024-03-31');
```

### ۳.۵ ساخت دستی حساب

اگر `auto_create` غیرفعال باشد:

```php
$user = User::find(1);

// ساخت حساب
$user->createAccount();

// ساخت با تنظیمات سفارشی
$user->createAccount([
    'title' => 'حساب ویژه ' . $user->name,
    'parent_code' => '1103',
]);
```

---

## ۴. ایجاد حساب دستی (بدون Trait)

### ۴.۱ استفاده از Service

```php
use YourVendor\Accounting\Services\AccountService;

$accountService = app(AccountService::class);

// ایجاد حساب
$account = $accountService->create([
    'parent_code' => '1102',
    'code' => '110203',
    'title' => 'بانک ملت - شعبه مرکزی',
    'type' => 'asset',
    'nature' => 'debit',
    'is_active' => true,
]);
```

### ۴.۲ استفاده از Facade

```php
use YourVendor\Accounting\Facades\Accounting;

$account = Accounting::account()->create([
    'parent_code' => '1102',
    'title' => 'بانک ملت - شعبه مرکزی',
    'type' => 'asset',
    'nature' => 'debit',
]);
```

### ۴.۳ استفاده از Model

```php
use YourVendor\Accounting\Models\Account;

$parent = Account::where('code', '1102')->first();

$account = Account::create([
    'parent_id' => $parent->id,
    'code' => '110203',
    'title' => 'بانک ملت - شعبه مرکزی',
    'level' => 3,
    'type' => 'asset',
    'nature' => 'debit',
    'is_active' => true,
    'allow_direct_posting' => true,
]);
```

---

## ۵. ثبت سند حسابداری

### ۵.۱ استفاده از Facade (روش پیشنهادی)

```php
use YourVendor\Accounting\Facades\Accounting;

// سند فروش ساده
$document = Accounting::document()
    ->type('sale')
    ->date(now())
    ->description('فروش به مشتری')
    ->debit($customer->account, 1000000, 'بدهکار شدن مشتری')
    ->credit($salesIncomeAccount, 1000000, 'درآمد فروش')
    ->post();
```

### ۵.۲ سند با چند آیتم

```php
// سند فروش با بهای تمام شده
$document = Accounting::document()
    ->type('sale')
    ->date(now())
    ->description('فروش کالا')
    // ثبت فروش
    ->debit($customer->account, 1500000)
    ->credit($salesIncomeAccount, 1500000)
    // ثبت بهای تمام شده
    ->debit($costOfGoodsAccount, 1000000)
    ->credit($product->account, 1000000)
    ->post();
```

### ۵.۳ سند با مرکز هزینه

```php
$document = Accounting::document()
    ->type('expense')
    ->date(now())
    ->description('پرداخت اجاره')
    ->debit($rentExpenseAccount, 5000000)
        ->costCenter($projectA)
    ->credit($bankAccount, 5000000)
    ->post();
```

### ۵.۴ سند با شعبه

```php
$document = Accounting::document()
    ->type('sale')
    ->date(now())
    ->branch($tehranBranch)
    ->description('فروش شعبه تهران')
    ->debit($customer->account, 1000000)
    ->credit($salesIncomeAccount, 1000000)
    ->post();
```

### ۵.۵ سند پیش‌نویس

```php
// ایجاد پیش‌نویس (بدون post)
$document = Accounting::document()
    ->type('sale')
    ->date(now())
    ->description('پیش‌نویس فروش')
    ->debit($customer->account, 1000000)
    ->credit($salesIncomeAccount, 1000000)
    ->save();

// بعداً ثبت قطعی
$document->post();
```

### ۵.۶ استفاده از Service

```php
use YourVendor\Accounting\Services\DocumentService;

$documentService = app(DocumentService::class);

$document = $documentService->create([
    'type' => 'sale',
    'date' => now(),
    'description' => 'فروش کالا',
    'items' => [
        [
            'account_id' => $customer->account_id,
            'amount' => 1000000,
            'sign' => 1,
            'description' => 'بدهکار شدن مشتری',
        ],
        [
            'account_id' => $salesIncomeAccount->id,
            'amount' => 1000000,
            'sign' => -1,
            'description' => 'درآمد فروش',
        ],
    ],
]);

$documentService->post($document);
```

---

## ۶. عملیات رایج

### ۶.۱ فروش نقدی

```php
public function recordCashSale(User $customer, Product $product, float $amount, float $cost)
{
    return Accounting::document()
        ->type('sale')
        ->date(now())
        ->description("فروش نقدی به {$customer->name}")
        // دریافت وجه
        ->debit($this->getCashAccount(), $amount)
        ->credit(Accounting::systemAccount('sales_income'), $amount)
        // بهای تمام شده
        ->debit(Accounting::systemAccount('cost_of_goods'), $cost)
        ->credit($product->account, $cost)
        ->post();
}
```

### ۶.۲ فروش نسیه

```php
public function recordCreditSale(User $customer, Product $product, float $amount, float $cost)
{
    return Accounting::document()
        ->type('sale')
        ->date(now())
        ->description("فروش نسیه به {$customer->name}")
        // بدهکار شدن مشتری
        ->debit($customer->account, $amount)
        ->credit(Accounting::systemAccount('sales_income'), $amount)
        // بهای تمام شده
        ->debit(Accounting::systemAccount('cost_of_goods'), $cost)
        ->credit($product->account, $cost)
        ->post();
}
```

### ۶.۳ دریافت از مشتری

```php
public function recordReceipt(User $customer, float $amount, $paymentMethod = 'cash')
{
    $targetAccount = $paymentMethod === 'cash' 
        ? $this->getCashAccount() 
        : $this->getBankAccount();

    return Accounting::document()
        ->type('receipt')
        ->date(now())
        ->description("دریافت از {$customer->name}")
        ->debit($targetAccount, $amount)
        ->credit($customer->account, $amount)
        ->post();
}
```

### ۶.۴ خرید از تأمین‌کننده

```php
public function recordPurchase(Supplier $supplier, Product $product, float $amount)
{
    return Accounting::document()
        ->type('purchase')
        ->date(now())
        ->description("خرید از {$supplier->name}")
        ->debit($product->account, $amount)
        ->credit($supplier->account, $amount)
        ->post();
}
```

### ۶.۵ پرداخت به تأمین‌کننده

```php
public function recordPayment(Supplier $supplier, float $amount, $paymentMethod = 'bank')
{
    $sourceAccount = $paymentMethod === 'cash' 
        ? $this->getCashAccount() 
        : $this->getBankAccount();

    return Accounting::document()
        ->type('payment')
        ->date(now())
        ->description("پرداخت به {$supplier->name}")
        ->debit($supplier->account, $amount)
        ->credit($sourceAccount, $amount)
        ->post();
}
```

### ۶.۶ انتقال بین حساب‌ها

```php
public function recordTransfer($fromAccount, $toAccount, float $amount, string $description = '')
{
    return Accounting::document()
        ->type('transfer')
        ->date(now())
        ->description($description ?: 'انتقال وجه')
        ->debit($toAccount, $amount)
        ->credit($fromAccount, $amount)
        ->post();
}
```

### ۶.۷ ثبت هزینه

```php
public function recordExpense($expenseAccount, float $amount, $paymentMethod = 'cash', $costCenter = null)
{
    $sourceAccount = $paymentMethod === 'cash' 
        ? $this->getCashAccount() 
        : $this->getBankAccount();

    $doc = Accounting::document()
        ->type('expense')
        ->date(now())
        ->description('ثبت هزینه')
        ->debit($expenseAccount, $amount);
    
    if ($costCenter) {
        $doc->costCenter($costCenter);
    }
    
    return $doc->credit($sourceAccount, $amount)->post();
}
```

---

## ۷. Event ها و Listener ها

### ۷.۱ رویدادهای پکیج

| رویداد | زمان Fire شدن | Payload |
|--------|---------------|---------|
| DocumentCreated | پس از ایجاد سند | Document |
| DocumentPosted | پس از ثبت قطعی | Document |
| DocumentVoided | پس از ابطال | Document, reason |
| AccountCreated | پس از ایجاد حساب | Account |
| BalanceUpdated | پس از تغییر مانده | Account, oldBalance, newBalance |

### ۷.۲ گوش دادن به رویدادها

در `EventServiceProvider`:

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use YourVendor\Accounting\Events\DocumentPosted;
use YourVendor\Accounting\Events\DocumentVoided;
use App\Listeners\SendInvoiceEmail;
use App\Listeners\NotifyAccountant;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        DocumentPosted::class => [
            SendInvoiceEmail::class,
            NotifyAccountant::class,
        ],
        DocumentVoided::class => [
            NotifyAccountant::class,
        ],
    ];
}
```

### ۷.۳ ساخت Listener

```php
<?php

namespace App\Listeners;

use YourVendor\Accounting\Events\DocumentPosted;

class SendInvoiceEmail
{
    public function handle(DocumentPosted $event): void
    {
        $document = $event->document;
        
        // فقط برای فروش
        if ($document->type !== 'sale') {
            return;
        }
        
        // یافتن مشتری
        $customerItem = $document->items()
            ->whereHas('account', function ($query) {
                $query->where('entity_type', 'user');
            })
            ->first();
        
        if ($customerItem) {
            $customer = User::find($customerItem->account->entity_id);
            
            // ارسال ایمیل
            Mail::to($customer)->send(new InvoiceMail($document));
        }
    }
}
```

### ۷.۴ Listener برای بروزرسانی داشبورد

```php
<?php

namespace App\Listeners;

use YourVendor\Accounting\Events\DocumentPosted;
use App\Services\DashboardService;

class UpdateDashboard
{
    public function __construct(
        private DashboardService $dashboard
    ) {}

    public function handle(DocumentPosted $event): void
    {
        $this->dashboard->invalidateCache();
        $this->dashboard->updateTodayStats();
    }
}
```

---

## ۸. استفاده در Service های پروژه

### ۸.۱ OrderService

```php
<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use YourVendor\Accounting\Facades\Accounting;

class OrderService
{
    public function complete(Order $order): void
    {
        // تکمیل سفارش
        $order->update(['status' => 'completed']);
        
        // ثبت سند حسابداری
        $this->recordAccountingDocument($order);
    }
    
    private function recordAccountingDocument(Order $order): void
    {
        $customer = $order->customer;
        $totalAmount = $order->total_amount;
        $totalCost = $order->items->sum('cost');
        
        $doc = Accounting::document()
            ->type('sale')
            ->date($order->completed_at)
            ->reference($order->order_number)
            ->description("فروش سفارش {$order->order_number}")
            ->source($order);  // اتصال به سفارش
        
        // اگر پرداخت شده
        if ($order->is_paid) {
            $doc->debit($this->getPaymentAccount($order), $totalAmount);
        } else {
            $doc->debit($customer->account, $totalAmount);
        }
        
        $doc->credit(Accounting::systemAccount('sales_income'), $totalAmount);
        
        // ثبت بهای تمام شده برای هر آیتم
        foreach ($order->items as $item) {
            $doc->debit(Accounting::systemAccount('cost_of_goods'), $item->cost)
                ->credit($item->product->account, $item->cost);
        }
        
        $doc->post();
    }
    
    private function getPaymentAccount(Order $order)
    {
        return match($order->payment_method) {
            'cash' => Accounting::systemAccount('cash'),
            'bank' => Accounting::systemAccount('bank'),
            default => Accounting::systemAccount('cash'),
        };
    }
}
```

### ۸.۲ InvoiceService

```php
<?php

namespace App\Services;

use App\Models\Invoice;
use YourVendor\Accounting\Facades\Accounting;

class InvoiceService
{
    public function issue(Invoice $invoice): void
    {
        $invoice->update(['status' => 'issued']);
        
        Accounting::document()
            ->type('sale')
            ->date($invoice->issue_date)
            ->reference($invoice->invoice_number)
            ->description("صدور فاکتور {$invoice->invoice_number}")
            ->source($invoice)
            ->debit($invoice->customer->account, $invoice->total_amount)
            ->credit(Accounting::systemAccount('sales_income'), $invoice->total_amount)
            ->post();
    }
    
    public function recordPayment(Invoice $invoice, float $amount, string $method): void
    {
        $paymentAccount = $method === 'cash' 
            ? Accounting::systemAccount('cash')
            : Accounting::systemAccount('bank');
        
        Accounting::document()
            ->type('receipt')
            ->date(now())
            ->reference($invoice->invoice_number)
            ->description("دریافت بابت فاکتور {$invoice->invoice_number}")
            ->source($invoice)
            ->debit($paymentAccount, $amount)
            ->credit($invoice->customer->account, $amount)
            ->post();
        
        // بروزرسانی وضعیت فاکتور
        $invoice->paid_amount += $amount;
        if ($invoice->paid_amount >= $invoice->total_amount) {
            $invoice->status = 'paid';
        }
        $invoice->save();
    }
}
```

### ۸.۳ PayrollService

```php
<?php

namespace App\Services;

use App\Models\Payroll;
use App\Models\Employee;
use YourVendor\Accounting\Facades\Accounting;

class PayrollService
{
    public function processPayroll(Payroll $payroll): void
    {
        $doc = Accounting::document()
            ->type('payroll')
            ->date($payroll->pay_date)
            ->description("پرداخت حقوق {$payroll->period}");
        
        foreach ($payroll->items as $item) {
            // هزینه حقوق
            $doc->debit(
                Accounting::systemAccount('salary_expense'), 
                $item->gross_salary
            )->costCenter($item->employee->department);
            
            // کسورات (مثلاً بیمه)
            if ($item->insurance_deduction > 0) {
                $doc->credit(
                    Accounting::systemAccount('insurance_payable'),
                    $item->insurance_deduction
                );
            }
            
            // کسورات مالیات
            if ($item->tax_deduction > 0) {
                $doc->credit(
                    Accounting::systemAccount('tax_payable'),
                    $item->tax_deduction
                );
            }
            
            // خالص پرداختی
            $doc->credit(
                Accounting::systemAccount('bank'),
                $item->net_salary
            );
        }
        
        $doc->post();
        
        $payroll->update(['status' => 'processed']);
    }
}
```

---

## ۹. Query های مفید

### ۹.۱ مشتریان بدهکار

```php
use YourVendor\Accounting\Models\Account;

$debtors = Account::query()
    ->where('entity_type', 'user')
    ->where('cached_balance', '>', 0)
    ->with('entity')
    ->orderByDesc('cached_balance')
    ->get();
```

### ۹.۲ موجودی همه محصولات

```php
$inventory = Account::query()
    ->where('entity_type', 'product')
    ->with('entity')
    ->get()
    ->map(fn($account) => [
        'product' => $account->entity,
        'balance' => $account->cached_balance,
    ]);
```

### ۹.۳ گردش یک حساب

```php
use YourVendor\Accounting\Models\DocumentItem;

$transactions = DocumentItem::query()
    ->where('account_id', $account->id)
    ->whereHas('document', fn($q) => $q->where('status', 'posted'))
    ->with('document')
    ->orderBy('document.date')
    ->get();
```

### ۹.۴ اسناد امروز

```php
use YourVendor\Accounting\Models\Document;

$todayDocuments = Document::query()
    ->whereDate('date', today())
    ->where('status', 'posted')
    ->with('items.account')
    ->get();
```

### ۹.۵ جمع فروش ماه جاری

```php
use YourVendor\Accounting\Models\DocumentItem;

$monthlySales = DocumentItem::query()
    ->whereHas('document', function ($query) {
        $query->where('type', 'sale')
              ->where('status', 'posted')
              ->whereMonth('date', now()->month)
              ->whereYear('date', now()->year);
    })
    ->whereHas('account', function ($query) {
        $query->where('code', 'like', '41%');  // حساب‌های درآمد
    })
    ->where('sign', -1)  // بستانکار
    ->sum('amount');
```

---

## ۱۰. نکات مهم

### ۱۰.۱ Transaction

برای اطمینان از یکپارچگی داده‌ها:

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($order) {
    // تغییر وضعیت سفارش
    $order->update(['status' => 'completed']);
    
    // ثبت سند حسابداری
    Accounting::document()
        ->type('sale')
        ->debit(...)
        ->credit(...)
        ->post();
    
    // کاهش موجودی انبار
    $order->items->each(fn($item) => $item->product->decrement('stock', $item->quantity));
});
```

### ۱۰.۲ بررسی مانده قبل از عملیات

```php
public function processPayment(Bank $bank, float $amount): void
{
    // بررسی موجودی کافی
    if ($bank->balance() < $amount) {
        throw new InsufficientBalanceException("موجودی بانک کافی نیست");
    }
    
    // ادامه عملیات
}
```

### ۱۰.۳ Validation قبل از ثبت

```php
public function recordSale(User $customer, float $amount): void
{
    // بررسی سقف اعتبار مشتری
    $currentBalance = $customer->balance();
    $creditLimit = $customer->credit_limit;
    
    if ($currentBalance + $amount > $creditLimit) {
        throw new CreditLimitExceededException("سقف اعتبار مشتری تمام شده");
    }
    
    // ثبت سند
}
```

---

## ۱۱. خلاصه

| عملیات | روش |
|--------|-----|
| اتصال مدل به حساب | استفاده از HasAccount Trait |
| دریافت مانده | `$model->balance()` |
| ثبت سند | `Accounting::document()->...->post()` |
| دسترسی به حساب سیستمی | `Accounting::systemAccount('cash')` |
| واکنش به رویدادها | ساخت Listener برای Event ها |

---

[→ ادامه: مرجع API (08-api-reference.md)](08-api-reference.md)

[← بازگشت: پیکربندی (06-configuration.md)](06-configuration.md)

[⌂ فهرست (00-index.md)](00-index.md)
