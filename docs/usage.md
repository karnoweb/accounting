# راهنمای استفاده از Laravel Accounting

پکیج حسابداری دوطرفه (Double-Entry) برای لاراول با ثبت خودکار اسناد.

---

## فهرست

- [فاسیاد و نقطه ورود](#فاسیاد-و-نقطه-ورود)
- [ثبت سند حسابداری](#ثبت-سند-حسابداری)
- [حساب‌های سیستمی](#حسابهای-سیستمی)
- [حساب‌ها (Account)](#حسابها-account)
- [تراز و گزارش‌ها](#تراز-و-گزارشها)
- [سال مالی و شعبه](#سال-مالی-و-شعبه)
- [تریت HasAccount](#تریت-hasaccount)
- [رویدادها (Events)](#رویدادها-events)
- [استثناها](#استثناها)

---

## فاسیاد و نقطه ورود

همه عملیات از طریق فاسیاد `Accounting` یا تزریق `AccountingManager` انجام می‌شود:

```php
use Karnoweb\Accounting\Facades\Accounting;

// ساخت سند
Accounting::document()->type('sale')->debit(...)->credit(...)->post();

// کار با حساب‌ها
Accounting::account()->findByCode('110101');

// تراز حساب
Accounting::balance()->getBalance($account);

// گزارش تراز آزمایشی
Accounting::report()->trialBalance();

// سال مالی جاری
Accounting::currentFiscalYear();
```

---

## ثبت سند حسابداری

### DocumentBuilder (زنجیره‌ای)

برای ساخت سند از متدهای زنجیره‌ای استفاده کنید. **جمع بدهکار و بستانکار باید برابر باشد.**

```php
use Karnoweb\Accounting\Facades\Accounting;

$document = Accounting::document()
    ->type('sale')           // sale, purchase, receipt, payment, transfer, opening, closing, adjustment
    ->date(now())
    ->description('فروش به مشتری X')
    ->notes('یادداشت اختیاری')
    ->reference('INV-1001')
    ->source($order)         // مدل مرجع (morph) اختیاری
    ->meta(['key' => 'value'])
    ->debit($cashAccount, 1_000_000)      // بدهکار
    ->credit($salesAccount, 1_000_000)   // بستانکار
    ->save();   // ذخیره به صورت پیش‌نویس (draft)

// یا مستقیم ثبت قطعی:
$document = Accounting::document()
    ->type('receipt')
    ->description('دریافت نقدی')
    ->debit(Accounting::systemAccount('cash'), 500_000)
    ->credit(Accounting::systemAccount('receivables'), 500_000)
    ->post();   // ایجاد + ثبت قطعی در یک مرحله
```

### شعبه و سال مالی

پکیج فقط **branch_id** (عدد یا مدل) را ذخیره می‌کند. می‌توانید شناسهٔ عددی شعبه یا در صورت داشتن مدل Branch در اپ، خود مدل را بدهید:

```php
Accounting::document()
    ->branch(1)                    // branch_id به‌صورت عدد
    ->fiscalYear($fiscalYear)      // یا fiscalYear($id)
    ->type('transfer')
    ->debit($accountA, 100)
    ->credit($accountB, 100)
    ->save();

// در صورت داشتن مدل Branch در اپ:
// ->branch($branchModel)
```

### مرکز هزینه (Cost Center)

```php
Accounting::document()
    ->type('adjustment')
    ->debit($account, 100)->costCenter($costCenter)
    ->credit($otherAccount, 100)
    ->save();
```

### وضعیت سند و ثبت قطعی

- **save()**: سند با وضعیت `draft` ذخیره می‌شود؛ بعداً می‌توان آن را ویرایش یا **post** کرد.
- **post()**: سند ایجاد و بلافاصله ثبت قطعی می‌شود؛ تراز حساب‌ها به‌روز می‌شود.
- هر فراخوانی `Accounting::document()` یک **builder جدا** می‌سازد؛ خطوط builder دیگر نشت نمی‌کنند.

ثبت قطعی سند پیش‌نویس — مسیر کانونیکال یکسان است (`Document::post()` و `DocumentService::post()`):

```php
$document = Document::find($id);
$document->post();
// یا
app(DocumentService::class)->post($document);
```

#### قوانین کرنل هنگام ثبت (post)

1. وضعیت قابل ثبت باشد (`draft` یا `approved`)
2. حداقل تعداد خطوط (`document.min_items`)
3. سند متعادل باشد
4. سال مالی فعال باشد و تاریخ داخل بازه باشد (سال بسته رد می‌شود)
5. **هر خط فقط روی حساب قابل‌ثبت** (فعال + `allow_direct_posting` + سطح `posting_level` + بدون فرزند)
6. عملیات اتمیک است؛ به‌روزرسانی تراز از طریق observer انجام می‌شود

#### تغییرناپذیری خطوط پس از ثبت

پس از `posted` (و `voided`)، تغییر/حذف `DocumentItem` و ویرایش هدر سند (جز ابطال) با `DocumentNotEditableException` رد می‌شود.

#### شماره‌گذاری و یکتایی

- شماره سند با جدول `document_number_sequences` و قفل ردیف تخصیص می‌یابد (امن در concurrency).
- یکتایی DB: `(fiscal_year_id, number)`.
- برای retry امن اپلیکیشن: `->idempotencyKey('...')` یا فیلد `idempotency_key` (unique؛ چند `NULL` مجاز است).
- `(source_type, source_id)` عمداً unique نیست تا چند سند مشروع از یک منبع ممکن باشد.

ابطال سند ثبت‌شده:

```php
$document->void('دلیل ابطال');
```

---

## حساب‌های سیستمی

حساب‌های از پیش تعریف‌شده در `config/accounting.php` تحت کلید `account.system_accounts`:

| کلید        | کاربرد نمونه   |
|------------|----------------|
| `cash`     | صندوق          |
| `bank`     | بانک           |
| `receivables` | دریافتنی‌ها |
| `payables` | پرداختنی‌ها    |
| `sales_income` | درآمد فروش |
| `cost_of_goods` | بهای تمام شده |
| `refund_expense` | هزینه استرداد |

```php
$cash = Accounting::systemAccount('cash');
$bank = Accounting::systemAccount('bank');
```

---

## حساب‌ها (Account)

### ایجاد حساب

```php
$account = Accounting::account()->create([
    'parent_code' => '1101',   // یا parent_id
    'code' => '110102',
    'title' => 'بانک تجارت',
    'type' => 'asset',       // asset, liability, equity, income, expense
    'description' => null,
    'branch_id' => null,
    'is_active' => true,
    'allow_direct_posting' => true,
]);
```

اگر `code` ندهید و `config('accounting.account.auto_code')` روشن باشد، کد به‌صورت خودکار تولید می‌شود.

### جستجو و پیدا کردن

```php
Accounting::account()->find($id);
Accounting::account()->findByCode('110101');
Accounting::account()->findByCodeOrFail('110101');
Accounting::account()->findByEntity(Order::class, $orderId);

Accounting::account()->search([
    'query' => 'صندوق',
    'type' => 'asset',
    'level' => 3,
    'is_active' => true,
]);
```

---

## تراز و گزارش‌ها

### تراز حساب

```php
// بدون سال مالی: مانده lifetime (ستون cached_balance در صورت معتبر بودن TTL)
$balance = Accounting::balance()->getBalance($account);

// با سال مالی: همیشه همان FY — کش FY دیگر هرگز برنمی‌گردد
$balance = Accounting::balance()->getBalance($account, $fiscalYear);

// تراز تا تاریخ مشخص (هرگز از cached_balance lifetime استفاده نمی‌کند)
$balance = Accounting::balance()->getBalanceAsOf($account, '2024-06-15');

// جمع بدهکار / بستانکار
$debit  = Accounting::balance()->getDebitTotal($account);
$credit = Accounting::balance()->getCreditTotal($account);

// گردش (در بازه تاریخ)
$turnover = Accounting::balance()->getTurnover($account, '2024-01-01', '2024-12-31');
// ['debit' => float, 'credit' => float, 'balance' => float]

// به‌روزرسانی کش تراز (بدون FY = lifetime؛ با FY = کش scoped)
Accounting::balance()->refreshCache($account);
Accounting::balance()->refreshCache($account, $fiscalYear);
```

### گزارش تراز آزمایشی (Trial Balance)

```php
$rows = Accounting::report()->trialBalance();
$rows = Accounting::report()->trialBalance($fiscalYear);

// هر سطر: ['account' => Account, 'debit' => float, 'credit' => float]
```

---

## سال مالی و شعبه

```php
$fy = Accounting::currentFiscalYear();
$fy = Accounting::fiscalYear()->current();
$fy = Accounting::fiscalYear()->findByDate('2024-05-01');

$branch = Accounting::currentBranch();
```

---

## تریت HasAccount

برای مدل‌هایی که می‌خواهید به ازای هر رکورد یک **حساب** داشته باشند (مثلاً مشتری، تامین‌کننده)، تریت `HasAccount` را استفاده کنید.

```php
use Karnoweb\Accounting\Traits\HasAccount;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasAccount;
}
```

بعد از `created`، در صورت فعال بودن ایجاد خودکار، یک حساب به مدل متصل می‌شود.

### متدها و رابطه

```php
$customer->account;              // رابطه morphOne به Account
$customer->hasAccount();         // bool
$customer->createAccount();      // ایجاد دستی حساب
$customer->createAccount(['title' => 'عنوان دیگر', 'parent_code' => '1103']);
$customer->getAccountTitle();    // برای عنوان پیش‌فرض حساب

// تراز
$customer->balance();
$customer->balance($fiscalYear);
$customer->balanceAsOf('2024-06-01');

// اسناد و تراکنش‌های مرتبط با این حساب
$customer->documents();
$customer->transactions($fiscalYear);
```

### پیکربندی حساب (اختیاری)

در مدل می‌توانید `accountConfig()` را override کنید:

```php
protected function accountConfig(): array
{
    return [
        'parent_code' => '1103',
        'title' => $this->full_name,
        'type' => 'asset',
        'nature' => 'debit',
        'branch_id' => null,
        'is_active' => true,
        'allow_direct_posting' => true,
        'auto_create' => true,
        'meta' => null,
    ];
}

protected function getAccountTitle(): string
{
    return $this->full_name ?? $this->name;
}
```

---

## رویدادها (Events)

- `Karnoweb\Accounting\Events\DocumentCreated` — بعد از ایجاد سند
- `Karnoweb\Accounting\Events\DocumentPosted` — بعد از ثبت قطعی سند
- `Karnoweb\Accounting\Events\DocumentVoided` — بعد از ابطال سند

مثال شنود:

```php
use Karnoweb\Accounting\Events\DocumentPosted;

Event::listen(DocumentPosted::class, function (DocumentPosted $event) {
    $document = $event->document;
    // ...
});
```

---

## استثناها

| استثنا | زمان |
|--------|------|
| `UnbalancedDocumentException` | جمع بدهکار ≠ بستانکار |
| `ClosedFiscalYearException` | سند در سال مالی بسته |
| `InactiveAccountException` | حساب غیرفعال |
| `InvalidPostingAccountException` | حساب گروه/کل/معین یا غیرقابل‌ثبت |
| `InvalidAccountHierarchyException` | نقض حداکثر سطح / سلسله‌مراتب |
| `DuplicateIdempotencyKeyException` | تکرار `idempotency_key` |
| `FiscalYearOverlapException` | هم‌پوشانی بازه سال مالی |
| `AccountNotFoundException` | حساب با کد داده‌شده یافت نشد |
| `DocumentNotEditableException` | ویرایش/حذف سند یا خط ثبت‌شده |
| `SystemAccountException` | عملیات ممنوع روی حساب سیستمی |

با `abort()` یا `try/catch` و پیام مناسب به کاربر پاسخ دهید.

---

## انواع سند (Document Type)

مقادیر مجاز در `config/accounting.document.allowed_types`:

- `sale` — فروش  
- `purchase` — خرید  
- `receipt` — دریافت  
- `payment` — پرداخت  
- `transfer` — انتقال  
- `opening` — افتتاحیه  
- `closing` — اختتامیه  
- `adjustment` — تعدیل  

## وضعیت سند (Document Status)

- `draft` — پیش‌نویس (قابل ویرایش و ثبت قطعی)
- `pending` — در انتظار تأیید
- `approved` — تأیید شده
- `posted` — ثبت قطعی (فقط قابل ابطال)
- `voided` — ابطال شده

برای جزئیات بیشتر فایل‌های داخل `src/` و `config/accounting.php` را ببینید.
