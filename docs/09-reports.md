# گزارش‌ها

گزارش‌های هسته پکیج فقط از **دفتر ثبت‌شده** داده می‌خوانند:

- `documents`
- `document_items`
- فقط اسناد با `status = posted`

بنابراین Source of Truth این گزارش‌ها، ردیف‌های ثبت‌شده سند هستند؛ نه `cached_balance` و نه مدل‌های عملیاتی بیرونی.

## گزارش‌های موجود

1. `trialBalanceDetailed`
2. `profitAndLoss`
3. `balanceSheet`
4. `cashMovements`
5. `generalLedger`
6. `accountStatement`
7. `accountStatementPaginated`
8. `generalLedgerSummary`
9. `costCenterStatementPaginated`
10. `BalanceService::getTurnover`
11. `accountTurnover`
12. `journalBook`
13. `dailyJournal`
14. `periodClosing`
15. `comparePeriods`
16. `receivableAging` / `payableAging` — فقط با `AgingSourceProvider` میزبان

صورت‌های مالی: [20-financial-statements.md](20-financial-statements.md).
گزارش‌های پیشرفته (فیلتر مشترک، شعبه، cursor): [21-advanced-reports.md](21-advanced-reports.md).

## پایه مشترک گزارش‌ها: `LedgerQuery`

`LedgerQuery` foundation مشترک گزارش‌های تراکنش‌محور است.

### فیلترهای پشتیبانی‌شده

- `forAccount()`
- `forAccounts()`
- `forFiscalYear()`
- `forAccountingPeriod()` — پنجره را به `start_date`/`end_date` دوره محدود می‌کند و FY را از همان دوره می‌گیرد
- `from()`
- `to()`
- `branch()` / `branches()` / `allBranches()`
- `costCenter()` / `costCenters()`
- `excludeDocumentTypes()` — حذف نوع سند posted از دوره و افتتاحیه (سود و زیان برای نادیده گرفتن `closing` از آن استفاده می‌کند)
- `includeDocumentTypes()` / `search()` / `accountType()` / `mode()` / `listingQuery()` — گزارش‌های پیشرفته؛ جمع مالی همچنان `baseQuery()` posted-only است

### رفتارهای ثابت

#### فقط اسناد posted

متدهای `postedOnly()` و `excludeVoided()` وجود دارند، اما در عمل no-op هستند؛ چون query همیشه posted-only است.

#### ترتیب قطعی

ترتیب خواندن ردیف‌ها همیشه این است:

```text
documents.date,
documents.number,
documents.id,
document_items.order,
document_items.id
```

این ترتیب بخشی از قرارداد گزارش است، چون `runningBalance` دفتر کل از آن محاسبه می‌شود.

#### فیلتر شعبه

- اگر `branch()` اصلاً فراخوانی نشود، فیلتر شعبه نداریم.
- اگر `branch(null)` داده شود، فقط اسناد بدون شعبه دیده می‌شوند.
- فیلتر روی `documents.branch_id` اعمال می‌شود.

#### منطق مانده افتتاحیه

`openingBalances()` جمع `debit - credit` همه اسناد posted با `date < from` را می‌سازد.

- اگر query به سال مالی scope شده باشد، افتتاحیه فقط از همان سال مالی خوانده می‌شود.
- اگر query فقط بازه تاریخی داشته باشد و سال مالی scope نشده باشد، افتتاحیه lifetime است.

## ۱. تراز آزمایشی واقعی (`trialBalanceDetailed`)

### هدف گزارش

پاسخ به این سؤال که:

«برای هر حساب، مانده ابتدای دوره، گردش بدهکار/بستانکار دوره و مانده پایان دوره چیست؟»

### API

```php
$tb = Accounting::report()->trialBalanceDetailed($criteria);
```

`$criteria` می‌تواند `FiscalYear`، `LedgerQuery` یا `null` باشد.

اگر `null` باشد:

- ابتدا `FiscalYear::current()` بررسی می‌شود
- اگر سال active جاری وجود داشته باشد، گزارش بر همان سال scope می‌شود
- اگر وجود نداشته باشد، گزارش بدون محدودیت سال مالی اجرا می‌شود

### Source of Truth

- `LedgerQuery::trialBalanceAggregates()`
- `HierarchyRollup::build()`

### منطق محاسبه

برای هر حساب سطح ثبت:

- `opening_debit`
- `opening_credit`
- `period_debit`
- `period_credit`

مستقیماً از `document_items` محاسبه می‌شود.

سپس `HierarchyRollup` این اعداد را از L3 به L0-L2 جمع می‌کند. بنابراین:

- ردیف‌های L3 داده خام ledger هستند
- ردیف‌های L0 تا L2 rollup فرزندان‌اند

### این عدد از کجا آمده است؟

برای یک ردیف L3:

- افتتاحیه = جمع debit/credit قبل از `from`
- گردش = جمع debit/credit داخل بازه
- پایان = افتتاحیه + گردش

برای L0 تا L2:

- عدد از `cached_balance` نیامده
- فقط جمع descendantهای L3 است

### خروجی

`TrialBalanceReport` شامل:

- `rows`
- `level($level)`
- `detail()`
- `find($accountId)`
- `totals()`

### اتحادهای مهم

- جمع بدهکار افتتاحیه = جمع بستانکار افتتاحیه
- جمع بدهکار دوره = جمع بستانکار دوره
- جمع بدهکار پایان = جمع بستانکار پایان

## ۲. دفتر کل (`generalLedger`)

### هدف گزارش

پاسخ به این سؤال که:

«حرکت‌های یک یا چند حساب در بازه مورد نظر، با ترتیب قطعی و مانده جاری، چیست؟»

### API

```php
$gl = Accounting::report()->generalLedger($query);
```

### Source of Truth

- `LedgerQuery::openingBalances()`
- `LedgerQuery::cursor()`

### منطق محاسبه

برای هر حساب:

1. مانده افتتاحیه محاسبه می‌شود.
2. ردیف‌های ledger با ترتیب قطعی خوانده می‌شوند.
3. `runningBalance` برای هر خط از مانده قبلی + `debit - credit` ساخته می‌شود.
4. `closingBalance` از آخرین مانده جاری به دست می‌آید.

### خروجی

`GeneralLedgerReport` مجموعه‌ای از `AccountLedger` است.

هر `AccountLedger` شامل:

- `accountId`
- `openingBalance`
- `lines`
- `closingBalance`

هر `LedgerLine` شامل این فیلدهاست:

- `item_id`
- `document_id`
- `account_id`
- `date`
- `document_number`
- `document_type`
- `document_description`
- `reference`
- `source_type`
- `source_id`
- `debit`
- `credit`
- `signed_amount`
- `running_balance`
- `fiscal_year_id`
- `branch_id`
- `order`

## ۳. دفتر معین (`accountStatement`)

### هدف گزارش

Projection دفتر کل برای دقیقاً یک حساب.

### API

```php
$statement = Accounting::report()->accountStatement(
    LedgerQuery::make()->forAccount($account)
);
```

### قاعده مهم

اگر query دقیقاً به یک حساب scope نشده باشد، `InvalidArgumentException` پرتاب می‌شود.

### تفاوت با `generalLedger`

- `generalLedger` برای چند حساب یا همه حساب‌های قابل‌ثبت است
- `accountStatement` فقط برای یک حساب است

## ۴. گردش حساب (`getTurnover`)

### هدف گزارش

پاسخ به این سؤال که:

«این حساب در این بازه چقدر بدهکار و چقدر بستانکار شده است؟»

### API

```php
$turnover = Accounting::balance()->getTurnover($account, $from, $to, [
    'fiscal_year' => $fiscalYear,
    'branch_id' => $branchId,
]);
```

### خروجی

```php
[
    'debit' => float,
    'credit' => float,
    'balance' => float,
]
```

`balance` برابر `debit - credit` است.

## ۵. گزارش‌های صفحه‌بندی‌شده

Meta گزارش (افتتاحیه، اختتامیه، totals) همیشه از کل scope محاسبه می‌شود؛ فقط ردیف‌ها صفحه می‌شوند.

- `accountStatementPaginated($query, $page, $perPage)` — صورت حساب یک حساب با `runningBalance` صحیح؛ `$perPage = -1` یعنی همه خطوط
- `generalLedgerSummary($query, $page, $perPage)` — لیست حساب‌ها با opening/period/closing (بدون خطوط)
- `costCenterStatementPaginated($query, $page, $perPage)` — خطوط یک مرکز هزینه؛ نیاز به `LedgerQuery::costCenter($id)`

خروجی‌ها `LengthAwarePaginator` لاراول دارند تا host با `page_limit` و `Response::dataWithAdditional` سازگار باشد.

Default اندازه صفحه: `config('accounting.reports.per_page', 50)`.

## گزارش قدیمی: `trialBalance()`

این متد هنوز وجود دارد، اما deprecated است و تراز آزمایشی واقعی محسوب نمی‌شود، چون:

- افتتاحیه و گردش را جدا نمی‌کند
- rollup سلسله‌مراتبی ندارد
- فقط مانده نهایی را برمی‌گرداند

برای توسعه جدید از `trialBalanceDetailed()` استفاده کنید.

## صورت‌های مالی

از ۱۳.۱۰.۰ روی همان `LedgerQuery` / تراز آزمایشی:

- `profitAndLoss()` — جریان درآمد/هزینه؛ اسناد `closing` کنار گذاشته می‌شوند
- `balanceSheet()` — موجودی در `as_of`؛ سود جاری قبل از بستن جداگانه می‌آید
- `cashMovements()` — شالوده ورود/خروج حساب‌های نقد پیکربندی‌شده، نه صورت O/I/F

جزئیات، معادلهٔ ترازنامه، و محدودیت جریان نقد: [20-financial-statements.md](20-financial-statements.md).

گزارش‌های ۱۳.۱۱.۰ (`accountTurnover`, `journalBook`, `dailyJournal`, `periodClosing`, `comparePeriods`) روی `LedgerReportFilters` هستند و `LedgerQuery` را برای posted-only / افتتاحیه / شعبه گسترش می‌دهند، نه یک موتور جدا.

## آنچه در گزارش‌های هسته‌ای فعلاً نیست

- counterpart resolution برای اسناد چندردیفی
- صورت جریان وجه نقد کامل (عملیاتی / سرمایه‌گذاری / تأمین مالی)
- AR/AP Aging بومی (دفتر due_date و طرف حساب ندارد؛ قرارداد `AgingSourceProvider`)
- UI یا export سطح رابط کاربری
- snapshot isolation برای cursor روی دفتر زنده

صفحه‌بندی offset + prefix sum برای صورت‌حساب تک‌حساب همچنان هست. گزارش‌های ترتیبی جدید پیش‌فرض cursor دارند.
