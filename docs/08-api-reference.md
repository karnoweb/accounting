# 08-api-reference.md

# مرجع API

## API Reference

---

## مقدمه

این بخش مرجع کامل API پکیج حسابداری است. شامل تمام متدها، پارامترها، و مقادیر بازگشتی.

---

## ۱. Facade اصلی

### ۱.۱ معرفی

```php
use YourVendor\Accounting\Facades\Accounting;
```

Facade اصلی نقطه ورود ساده به تمام قابلیت‌های پکیج است.

### ۱.۲ متدهای عمومی

| متد | شرح | خروجی |
|-----|-----|-------|
| `version()` | نسخه پکیج | string |
| `config($key, $default)` | دریافت تنظیمات | mixed |
| `currentFiscalYear()` | سال مالی جاری | FiscalYear |
| `currentBranch()` | شعبه جاری | Branch |
| `systemAccount($key)` | حساب سیستمی | Account |

### ۱.۳ دسترسی به سرویس‌ها

| متد | شرح | خروجی |
|-----|-----|-------|
| `document()` | سازنده سند | DocumentBuilder |
| `account()` | سرویس حساب | AccountService |
| `balance()` | سرویس مانده | BalanceService |
| `report()` | سرویس گزارش | ReportService |
| `fiscalYear()` | سرویس سال مالی | FiscalYearService |

---

## ۲. DocumentBuilder

### ۲.۱ شروع ساخت سند

```php
$builder = Accounting::document();
```

### ۲.۲ متدهای تنظیم

| متد | پارامتر | شرح | خروجی |
|-----|---------|-----|-------|
| `type($type)` | string | نوع سند | self |
| `date($date)` | Carbon/string | تاریخ سند | self |
| `description($text)` | string | توضیحات | self |
| `notes($text)` | string | یادداشت | self |
| `reference($ref)` | string | شماره مرجع | self |
| `branch($branch)` | Branch/int | شعبه | self |
| `fiscalYear($year)` | FiscalYear/int | سال مالی | self |
| `source($model)` | Model | منبع سند | self |
| `meta($data)` | array | اطلاعات اضافی | self |

### ۲.۳ متدهای آیتم

| متد | پارامترها | شرح |
|-----|-----------|-----|
| `debit($account, $amount, $description)` | Account/int, float, string | افزودن آیتم بدهکار |
| `credit($account, $amount, $description)` | Account/int, float, string | افزودن آیتم بستانکار |
| `item($account, $amount, $sign, $description)` | Account/int, float, int, string | افزودن آیتم |
| `costCenter($center)` | CostCenter/int | تنظیم مرکز هزینه برای آیتم آخر |

### ۲.۴ متدهای ذخیره

| متد | شرح | خروجی |
|-----|-----|-------|
| `save()` | ذخیره پیش‌نویس | Document |
| `post()` | ذخیره و ثبت قطعی | Document |
| `validate()` | اعتبارسنجی بدون ذخیره | bool |
| `getItems()` | دریافت آیتم‌ها قبل از ذخیره | array |
| `getTotal()` | جمع مبلغ | array |

### ۲.۵ مثال کامل

```php
$document = Accounting::document()
    ->type('sale')
    ->date('2024-03-15')
    ->branch(1)
    ->description('فروش کالا به مشتری')
    ->reference('INV-2024-001')
    ->source($order)
    ->meta(['order_id' => $order->id])
    ->debit($customer->account, 1500000, 'بدهکار شدن مشتری')
    ->credit($salesAccount, 1500000, 'درآمد فروش')
    ->debit($costAccount, 1000000, 'بهای تمام شده')
    ->credit($productAccount, 1000000, 'خروج کالا')
        ->costCenter($projectA)
    ->post();
```

---

## ۳. AccountService

### ۳.۱ دسترسی

```php
$accountService = Accounting::account();
// یا
$accountService = app(AccountService::class);
```

### ۳.۲ متدهای CRUD

**create**

```php
public function create(array $data): Account
```

| پارامتر | نوع | الزامی | شرح |
|---------|-----|--------|-----|
| parent_id | int | ❌ | شناسه والد |
| parent_code | string | ❌ | کد والد (جایگزین parent_id) |
| code | string | ❌ | کد حساب (خودکار تولید می‌شود) |
| title | string | ✅ | عنوان حساب |
| description | string | ❌ | توضیحات |
| type | string | ✅ | نوع (asset, liability, equity, income, expense) |
| nature | string | ❌ | ماهیت (debit, credit) |
| is_active | bool | ❌ | وضعیت فعال (پیش‌فرض: true) |
| entity_type | string | ❌ | نوع موجودیت |
| entity_id | int | ❌ | شناسه موجودیت |
| meta | array | ❌ | اطلاعات اضافی |

مثال:

```php
$account = Accounting::account()->create([
    'parent_code' => '1102',
    'title' => 'بانک ملت - حساب جاری',
    'type' => 'asset',
    'nature' => 'debit',
]);
```

**update**

```php
public function update(Account|int $account, array $data): Account
```

مثال:

```php
$account = Accounting::account()->update($account, [
    'title' => 'عنوان جدید',
    'is_active' => false,
]);
```

**delete**

```php
public function delete(Account|int $account): bool
```

مثال:

```php
Accounting::account()->delete($account);
```

⚠️ حساب‌های سیستمی و حساب‌های دارای تراکنش قابل حذف نیستند.

### ۳.۳ متدهای جستجو

**find**

```php
public function find(int $id): ?Account
public function findOrFail(int $id): Account
public function findByCode(string $code): ?Account
public function findByCodeOrFail(string $code): Account
```

مثال:

```php
$account = Accounting::account()->findByCode('110101');
```

**findByEntity**

```php
public function findByEntity(string $entityType, int $entityId): ?Account
```

مثال:

```php
$account = Accounting::account()->findByEntity('user', 1);
```

**search**

```php
public function search(array $filters): Collection
```

| فیلتر | نوع | شرح |
|-------|-----|-----|
| query | string | جستجو در عنوان و کد |
| type | string | نوع حساب |
| nature | string | ماهیت حساب |
| level | int | سطح حساب |
| parent_id | int | شناسه والد |
| is_active | bool | وضعیت فعال |
| entity_type | string | نوع موجودیت |
| has_balance | bool | دارای مانده |

مثال:

```php
$accounts = Accounting::account()->search([
    'type' => 'asset',
    'level' => 3,
    'is_active' => true,
    'query' => 'بانک',
]);
```

### ۳.۴ متدهای درخت

**getTree**

```php
public function getTree(?int $parentId = null, int $maxLevel = 3): Collection
```

مثال:

```php
$tree = Accounting::account()->getTree();
$assetTree = Accounting::account()->getTree(1);  // فقط زیرمجموعه دارایی
```

**getChildren**

```php
public function getChildren(Account|int $account): Collection
```

**getParent**

```php
public function getParent(Account|int $account): ?Account
```

**getAncestors**

```php
public function getAncestors(Account|int $account): Collection
```

**getDescendants**

```php
public function getDescendants(Account|int $account): Collection
```

### ۳.۵ متدهای کمکی

**generateCode**

```php
public function generateCode(Account|int|string $parent): string
```

مثال:

```php
$newCode = Accounting::account()->generateCode('1102');
// نتیجه: '110203' (کد بعدی در سطح تفصیلی)
```

**validateCode**

```php
public function validateCode(string $code): bool
```

**getSystemAccount**

```php
public function getSystemAccount(string $key): Account
```

مثال:

```php
$cashAccount = Accounting::account()->getSystemAccount('cash');
$bankAccount = Accounting::account()->getSystemAccount('bank');
```

---

## ۴. DocumentService

### ۴.۱ دسترسی

```php
$documentService = app(DocumentService::class);
```

### ۴.۲ متدهای CRUD

**create**

```php
public function create(array $data): Document
```

| پارامتر | نوع | الزامی | شرح |
|---------|-----|--------|-----|
| fiscal_year_id | int | ❌ | شناسه سال مالی (خودکار) |
| branch_id | int | ❌ | شناسه شعبه |
| date | string/Carbon | ✅ | تاریخ سند |
| type | string | ✅ | نوع سند |
| description | string | ❌ | توضیحات |
| notes | string | ❌ | یادداشت |
| reference | string | ❌ | شماره مرجع |
| source_type | string | ❌ | نوع منبع |
| source_id | int | ❌ | شناسه منبع |
| items | array | ✅ | آیتم‌های سند |
| meta | array | ❌ | اطلاعات اضافی |

ساختار items:

```php
'items' => [
    [
        'account_id' => 1,
        'amount' => 1000000,
        'sign' => 1,  // 1 = بدهکار، -1 = بستانکار
        'description' => 'توضیح ردیف',
        'cost_center_id' => null,
        'meta' => [],
    ],
    // ...
]
```

مثال:

```php
$document = $documentService->create([
    'date' => '2024-03-15',
    'type' => 'sale',
    'description' => 'فروش کالا',
    'items' => [
        ['account_id' => 10, 'amount' => 1000000, 'sign' => 1],
        ['account_id' => 20, 'amount' => 1000000, 'sign' => -1],
    ],
]);
```

**update**

```php
public function update(Document|int $document, array $data): Document
```

⚠️ فقط اسناد پیش‌نویس قابل ویرایش هستند.

**delete**

```php
public function delete(Document|int $document): bool
```

⚠️ فقط اسناد پیش‌نویس قابل حذف هستند.

### ۴.۳ متدهای تغییر وضعیت

**submit**

```php
public function submit(Document|int $document): Document
```

تغییر از draft به pending.

**approve**

```php
public function approve(Document|int $document): Document
```

تغییر از pending به approved.

**reject**

```php
public function reject(Document|int $document, string $reason = ''): Document
```

برگشت از pending به draft.

**post**

```php
public function post(Document|int $document): Document
```

ثبت قطعی سند (تغییر به posted).

**void**

```php
public function void(Document|int $document, string $reason = ''): Document
```

ابطال سند ثبت شده.

### ۴.۴ متدهای جستجو

**find**

```php
public function find(int $id): ?Document
public function findOrFail(int $id): Document
public function findByNumber(int $number, ?FiscalYear $fiscalYear = null): ?Document
```

**search**

```php
public function search(array $filters): LengthAwarePaginator
```

| فیلتر | نوع | شرح |
|-------|-----|-----|
| fiscal_year_id | int | سال مالی |
| branch_id | int | شعبه |
| type | string/array | نوع سند |
| status | string/array | وضعیت |
| date_from | string | از تاریخ |
| date_to | string | تا تاریخ |
| number_from | int | از شماره |
| number_to | int | تا شماره |
| reference | string | شماره مرجع |
| account_id | int | حساب مرتبط |
| min_amount | float | حداقل مبلغ |
| max_amount | float | حداکثر مبلغ |
| created_by | int | ایجادکننده |

مثال:

```php
$documents = $documentService->search([
    'type' => 'sale',
    'status' => 'posted',
    'date_from' => '2024-01-01',
    'date_to' => '2024-03-31',
]);
```

### ۴.۵ متدهای کمکی

**getNextNumber**

```php
public function getNextNumber(?FiscalYear $fiscalYear = null): int
```

**validate**

```php
public function validate(array $data): array
```

اعتبارسنجی داده‌ها و برگرداندن خطاها.

**isBalanced**

```php
public function isBalanced(Document|array $document): bool
```

بررسی بالانس بودن سند.

**getTotal**

```php
public function getTotal(Document $document): array
```

خروجی:

```php
[
    'debit' => 1000000,
    'credit' => 1000000,
    'balance' => 0,
]
```

---

## ۵. BalanceService

### ۵.۱ دسترسی

```php
$balanceService = app(BalanceService::class);
```

### ۵.۲ متدهای محاسبه

**getBalance**

```php
public function getBalance(
    Account|int $account, 
    ?FiscalYear $fiscalYear = null,
    bool $forceRealtime = false
): float
```

مثال:

```php
$balance = $balanceService->getBalance($account);
$balance = $balanceService->getBalance($account, $fiscalYear);
$balance = $balanceService->getBalance($account, null, true);  // بدون cache
```

**getBalanceAsOf**

```php
public function getBalanceAsOf(
    Account|int $account, 
    Carbon|string $date,
    ?FiscalYear $fiscalYear = null
): float
```

مثال:

```php
$balance = $balanceService->getBalanceAsOf($account, '2024-03-31');
```

**getDebitTotal**

```php
public function getDebitTotal(
    Account|int $account, 
    ?FiscalYear $fiscalYear = null
): float
```

**getCreditTotal**

```php
public function getCreditTotal(
    Account|int $account, 
    ?FiscalYear $fiscalYear = null
): float
```

**getTurnover**

```php
public function getTurnover(
    Account|int $account,
    Carbon|string $fromDate,
    Carbon|string $toDate
): array
```

خروجی:

```php
[
    'debit' => 5000000,
    'credit' => 3000000,
    'balance' => 2000000,
]
```

### ۵.۳ متدهای Cache

**refreshCache**

```php
public function refreshCache(Account|int $account): float
```

بروزرسانی cache مانده یک حساب.

**refreshAllCaches**

```php
public function refreshAllCaches(?FiscalYear $fiscalYear = null): void
```

بروزرسانی cache تمام حساب‌ها.

**invalidateCache**

```php
public function invalidateCache(Account|int $account): void
```

نامعتبر کردن cache یک حساب.

### ۵.۴ متدهای بررسی

**hasNormalBalance**

```php
public function hasNormalBalance(Account|int $account): bool
```

بررسی اینکه مانده در جهت طبیعی حساب است.

**getBalanceWarning**

```php
public function getBalanceWarning(Account|int $account): ?string
```

دریافت هشدار مانده غیرطبیعی (در صورت وجود).

---

## ۶. ReportService

### ۶.۱ دسترسی

```php
$reportService = Accounting::report();
// یا
$reportService = app(ReportService::class);
```

### ۶.۲ تراز آزمایشی

**trialBalance**

```php
public function trialBalance(
    ?FiscalYear $fiscalYear = null,
    ?Carbon $asOfDate = null,
    ?int $branchId = null
): Collection
```

خروجی (هر ردیف):

```php
[
    'account_id' => 1,
    'code' => '110101',
    'title' => 'صندوق',
    'level' => 3,
    'debit' => 5000000,
    'credit' => 3000000,
    'balance' => 2000000,
    'balance_debit' => 2000000,
    'balance_credit' => 0,
]
```

مثال:

```php
$trialBalance = Accounting::report()->trialBalance();
$trialBalance = Accounting::report()->trialBalance($fiscalYear, Carbon::parse('2024-03-31'));
```

### ۶.۳ دفتر کل

**generalLedger**

```php
public function generalLedger(
    Account|int $account,
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null
): array
```

خروجی:

```php
[
    'account' => ['id' => 1, 'code' => '110101', 'title' => 'صندوق'],
    'period' => ['from' => '2024-01-01', 'to' => '2024-03-31'],
    'opening_balance' => 1000000,
    'transactions' => [
        [
            'date' => '2024-01-15',
            'document_number' => 5,
            'description' => 'دریافت از مشتری',
            'debit' => 500000,
            'credit' => 0,
            'balance' => 1500000,
        ],
        // ...
    ],
    'closing_balance' => 2500000,
    'totals' => [
        'debit' => 2000000,
        'credit' => 500000,
    ],
]
```

### ۶.۴ گردش حساب

**accountStatement**

```php
public function accountStatement(
    Account|int $account,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null
): array
```

مشابه دفتر کل اما بدون محدودیت سال مالی.

### ۶.۵ ترازنامه

**balanceSheet**

```php
public function balanceSheet(
    ?FiscalYear $fiscalYear = null,
    ?Carbon $asOfDate = null,
    ?int $branchId = null
): array
```

خروجی:

```php
[
    'as_of_date' => '2024-03-31',
    'assets' => [
        'current' => [...],
        'non_current' => [...],
        'total' => 50000000,
    ],
    'liabilities' => [
        'current' => [...],
        'non_current' => [...],
        'total' => 20000000,
    ],
    'equity' => [
        'items' => [...],
        'total' => 30000000,
    ],
    'totals' => [
        'assets' => 50000000,
        'liabilities_and_equity' => 50000000,
        'is_balanced' => true,
    ],
]
```

### ۶.۶ صورت سود و زیان

**incomeStatement**

```php
public function incomeStatement(
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null,
    ?int $branchId = null
): array
```

خروجی:

```php
[
    'period' => ['from' => '2024-01-01', 'to' => '2024-03-31'],
    'income' => [
        'operating' => [...],
        'non_operating' => [...],
        'total' => 10000000,
    ],
    'expenses' => [
        'operating' => [...],
        'non_operating' => [...],
        'total' => 7000000,
    ],
    'net_profit' => 3000000,
    'profit_margin' => 30.0,
]
```

### ۶.۷ گزارش مرکز هزینه

**costCenterReport**

```php
public function costCenterReport(
    CostCenter|int $costCenter,
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null
): array
```

### ۶.۸ گزارش شعبه

**branchReport**

```php
public function branchReport(
    Branch|int $branch,
    ?FiscalYear $fiscalYear = null
): array
```

---

## ۷. FiscalYearService

### ۷.۱ دسترسی

```php
$fiscalYearService = Accounting::fiscalYear();
// یا
$fiscalYearService = app(FiscalYearService::class);
```

### ۷.۲ متدهای CRUD

**create**

```php
public function create(array $data): FiscalYear
```

| پارامتر | نوع | الزامی | شرح |
|---------|-----|--------|-----|
| title | string | ✅ | عنوان |
| start_date | string/Carbon | ✅ | تاریخ شروع |
| end_date | string/Carbon | ✅ | تاریخ پایان |
| status | string | ❌ | وضعیت (پیش‌فرض: draft) |

مثال:

```php
$fiscalYear = Accounting::fiscalYear()->create([
    'title' => 'سال مالی ۱۴۰۴',
    'start_date' => '1404-01-01',
    'end_date' => '1404-12-29',
]);
```

**update**

```php
public function update(FiscalYear|int $fiscalYear, array $data): FiscalYear
```

**delete**

```php
public function delete(FiscalYear|int $fiscalYear): bool
```

⚠️ سال مالی دارای سند قابل حذف نیست.

### ۷.۳ متدهای وضعیت

**activate**

```php
public function activate(FiscalYear|int $fiscalYear): FiscalYear
```

فعال کردن سال مالی (تغییر به active).

**close**

```php
public function close(FiscalYear|int $fiscalYear): FiscalYear
```

بستن سال مالی (تغییر به closed).

**reopen**

```php
public function reopen(FiscalYear|int $fiscalYear): FiscalYear
```

بازگشایی سال مالی بسته.

### ۷.۴ متدهای عملیاتی

**createOpening**

```php
public function createOpening(FiscalYear|int $fiscalYear, ?FiscalYear $previousYear = null): Document
```

ایجاد سند افتتاحیه از روی سال قبل.

**createClosing**

```php
public function createClosing(FiscalYear|int $fiscalYear): Document
```

ایجاد سند اختتامیه و بستن حساب‌های موقت.

### ۷.۵ متدهای جستجو

**current**

```php
public function current(): ?FiscalYear
```

دریافت سال مالی جاری.

**findByDate**

```php
public function findByDate(Carbon|string $date): ?FiscalYear
```

یافتن سال مالی شامل یک تاریخ.

**all**

```php
public function all(): Collection
```

همه سال‌های مالی.

---

## ۸. Model های اصلی

### ۸.۱ Account

**روابط:**

| متد | نوع | مرتبط با |
|-----|-----|----------|
| `parent()` | BelongsTo | Account |
| `children()` | HasMany | Account |
| `branch()` | BelongsTo | Branch |
| `items()` | HasMany | DocumentItem |
| `documents()` | HasManyThrough | Document |
| `entity()` | MorphTo | - |

**Scope ها:**

```php
Account::active()->get();                    // فقط فعال
Account::ofType('asset')->get();             // نوع خاص
Account::ofLevel(3)->get();                  // سطح خاص
Account::ofBranch($branch)->get();           // شعبه خاص
Account::systemAccounts()->get();            // حساب‌های سیستمی
Account::postable()->get();                  // قابل ثبت سند
Account::withBalance()->get();               // با مانده غیرصفر
```

**متدها:**

```php
$account->balance();                          // مانده
$account->balanceAsOf($date);                 // مانده تا تاریخ
$account->turnover($from, $to);               // گردش
$account->hasNormalBalance();                 // بررسی مانده طبیعی
$account->getFullCode();                      // کد کامل با والدین
$account->getFullTitle();                     // عنوان کامل با والدین
$account->getPath();                          // مسیر از ریشه
$account->isLeaf();                           // آیا برگ است؟
$account->isRoot();                           // آیا ریشه است؟
$account->isAncestorOf($other);               // آیا جد است؟
$account->isDescendantOf($other);             // آیا فرزند است؟
```

### ۸.۲ Document

**روابط:**

| متد | نوع | مرتبط با |
|-----|-----|----------|
| `fiscalYear()` | BelongsTo | FiscalYear |
| `branch()` | BelongsTo | Branch |
| `items()` | HasMany | DocumentItem |
| `logs()` | HasMany | DocumentLog |
| `createdBy()` | BelongsTo | User |
| `approvedBy()` | BelongsTo | User |
| `postedBy()` | BelongsTo | User |
| `source()` | MorphTo | - |

**Scope ها:**

```php
Document::posted()->get();                    // فقط ثبت شده
Document::draft()->get();                     // فقط پیش‌نویس
Document::ofType('sale')->get();              // نوع خاص
Document::ofFiscalYear($year)->get();         // سال مالی خاص
Document::ofBranch($branch)->get();           // شعبه خاص
Document::inDateRange($from, $to)->get();     // بازه تاریخی
Document::today()->get();                     // امروز
Document::thisMonth()->get();                 // این ماه
```

**متدها:**

```php
$document->post();                            // ثبت قطعی
$document->void($reason);                     // ابطال
$document->isPosted();                        // آیا ثبت شده؟
$document->isEditable();                      // آیا قابل ویرایش؟
$document->isDeletable();                     // آیا قابل حذف؟
$document->isBalanced();                      // آیا بالانس؟
$document->getTotal();                        // جمع بدهکار و بستانکار
$document->getDebitTotal();                   // جمع بدهکار
$document->getCreditTotal();                  // جمع بستانکار
$document->getAffectedAccounts();             // حساب‌های تأثیرپذیر
$document->duplicate();                       // کپی سند
$document->reverse();                         // ایجاد سند معکوس
```

### ۸.۳ DocumentItem

**روابط:**

| متد | نوع | مرتبط با |
|-----|-----|----------|
| `document()` | BelongsTo | Document |
| `account()` | BelongsTo | Account |
| `costCenter()` | BelongsTo | CostCenter |

**Accessor ها:**

```php
$item->debit;                                 // مبلغ بدهکار
$item->credit;                                // مبلغ بستانکار
$item->signed_amount;                         // مبلغ علامت‌دار
$item->is_debit;                              // آیا بدهکار؟
$item->is_credit;                             // آیا بستانکار؟
```

### ۸.۴ FiscalYear

**روابط:**

| متد | نوع | مرتبط با |
|-----|-----|----------|
| `documents()` | HasMany | Document |

**Scope ها:**

```php
FiscalYear::active()->get();                  // فعال
FiscalYear::closed()->get();                  // بسته
FiscalYear::current()->first();               // جاری
```

**متدها:**

```php
$fiscalYear->isActive();                      // آیا فعال؟
$fiscalYear->isClosed();                      // آیا بسته؟
$fiscalYear->containsDate($date);             // آیا تاریخ شامل است؟
$fiscalYear->getDaysRemaining();              // روزهای باقی‌مانده
$fiscalYear->getProgress();                   // درصد پیشرفت
$fiscalYear->documentsCount();                // تعداد اسناد
$fiscalYear->activate();                      // فعال کردن
$fiscalYear->close();                         // بستن
```

### ۸.۵ Branch

**روابط:**

| متد | نوع | مرتبط با |
|-----|-----|----------|
| `accounts()` | HasMany | Account |
| `documents()` | HasMany | Document |

**Scope ها:**

```php
Branch::active()->get();
Branch::default()->first();
```

### ۸.۶ CostCenter

**روابط:**

| متد | نوع | مرتبط با |
|-----|-----|----------|
| `items()` | HasMany | DocumentItem |

**Scope ها:**

```php
CostCenter::active()->get();
```

---

## ۹. Exception ها

### ۹.۱ لیست Exception ها

| Exception | علت |
|-----------|-----|
| `UnbalancedDocumentException` | سند بالانس نیست |
| `ClosedFiscalYearException` | سال مالی بسته است |
| `InactiveAccountException` | حساب غیرفعال است |
| `InvalidDocumentStatusException` | تغییر وضعیت نامعتبر |
| `AccountNotFoundException` | حساب یافت نشد |
| `SystemAccountException` | عملیات غیرمجاز روی حساب سیستمی |
| `DocumentNotEditableException` | سند قابل ویرایش نیست |
| `InsufficientBalanceException` | موجودی کافی نیست |

### ۹.۲ مدیریت خطا

```php
use YourVendor\Accounting\Exceptions\UnbalancedDocumentException;
use YourVendor\Accounting\Exceptions\ClosedFiscalYearException;

try {
    Accounting::document()
        ->type('sale')
        ->debit($account1, 1000)
        ->credit($account2, 900)  // نامتوازن!
        ->post();
} catch (UnbalancedDocumentException $e) {
    // مدیریت خطای عدم توازن
    return back()->withError('سند بالانس نیست');
} catch (ClosedFiscalYearException $e) {
    // مدیریت خطای سال مالی بسته
    return back()->withError('سال مالی بسته است');
}
```

---

## ۱۰. Helper ها

### ۱۰.۱ توابع کمکی

```php
// دسترسی به Facade
accounting()->document()->...

// فرمت مبلغ
accounting_format(1000000);  // 1,000,000

// پارس مبلغ
accounting_parse('1,000,000');  // 1000000

// سال مالی جاری
current_fiscal_year();

// شعبه جاری
current_branch();

// حساب سیستمی
system_account('cash');
```

---

[→ ادامه: گزارش‌ها (09-reports.md)](09-reports.md)

[← بازگشت: یکپارچه‌سازی (07-integration.md)](07-integration.md)

[⌂ فهرست (00-index.md)](00-index.md)
