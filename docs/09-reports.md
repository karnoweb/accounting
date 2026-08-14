# 09-reports.md

# گزارش‌های حسابداری

## Reports

---

## گزارش‌های موجود در پکیج

از نسخهٔ ۱۳.۲.۰ پکیج یک **لایهٔ گزارش‌گیری واقعی** ارائه می‌دهد که مستقیماً روی دفتر ثبت‌شده
(`acc_document_items JOIN acc_documents`) کار می‌کند — هرگز از `cached_balance` (چه lifetime حساب
و چه انباشتهٔ والد) استفاده نمی‌کند. همهٔ گزارش‌ها به‌طور پیش‌فرض **فقط اسناد `posted`** را
می‌بینند و اسناد `voided` را کنار می‌گذارند.

گزارش‌های ارائه‌شده:

- **تراز آزمایشی واقعی** (`trialBalanceDetailed`) — سطوح L0 تا L3، مانده افتتاحیه/گردش دوره/مانده پایانی.
- **دفتر کل** (`generalLedger`) — افتتاحیه ← ردیف‌های دفتر ← مانده جاری ← مانده پایانی، برای چند حساب.
- **دفتر معین یک حساب** (`accountStatement`) — همان منطق `generalLedger` برای یک حساب.
- **گردش حساب** (`getTurnover`) — اکنون با فیلتر اختیاری سال مالی و شعبه.

آنچه همچنان **در این پکیج نیست** (به بخش پایانی مراجعه کنید): صورت سود و زیان، ترازنامه،
افتتاحیه/اختتامیهٔ سال مالی، زیرسیستم مشتری/تأمین‌کننده، صورت جریان وجه نقد، و هر UI گزارش.

---

## دسترسی

```php
use Karnoweb\Accounting\Facades\Accounting;

$tb = Accounting::report()->trialBalanceDetailed($fiscalYear);
$gl = Accounting::report()->generalLedger($ledgerQuery);
$as = Accounting::report()->accountStatement($ledgerQuery);
```

یا:

```php
use Karnoweb\Accounting\Services\ReportService;

$rows = app(ReportService::class)->trialBalanceDetailed($fiscalYear);
```

---

## پایهٔ مشترک گزارش‌ها: `LedgerQuery`

همهٔ گزارش‌های تراکنش‌محور (تراز آزمایشی، دفتر کل، دفتر معین، گردش) روی یک شیء مشترک
`Karnoweb\Accounting\Reporting\LedgerQuery` ساخته می‌شوند تا فیلترها و **ترتیب قطعی** یکسان
باشند.

```php
use Karnoweb\Accounting\Reporting\LedgerQuery;

$query = LedgerQuery::make()
    ->forAccount($account)          // یا ->forAccounts([$a, $b, ...])
    ->forFiscalYear($fiscalYear)    // اختیاری؛ اگر from/to ندهید، از start_date/end_date همین FY استفاده می‌شود
    ->from('2024-01-01')            // اختیاری
    ->to('2024-12-31')              // اختیاری
    ->branch($branchId);            // اختیاری — فراخوانی نشود یعنی فیلتر شعبه اعمال نمی‌شود
```

نکات مهم:

- **همیشه posted-only و بدون اسناد باطل‌شده** — `postedOnly()` و `excludeVoided()` صرفاً برای
  خوانایی/سازگاری وجود دارند (no-op)؛ رفتار پیش‌فرض همین است و راهی برای غیرفعال کردن آن نیست.
- **ترتیب قطعی**، در همهٔ گزارش‌های ردیف‌محور یکسان و هرگز بر اساس `created_at`:

  ```
  documents.date ASC, documents.number ASC, documents.id ASC,
  document_items.order ASC, document_items.id ASC
  ```

- **مانده افتتاحیه** (`openingBalances()`) = جمع `debit - credit` تمام اسناد `posted` با
  `date < from`، **صرف‌نظر از سال مالی** — یعنی مانده افتتاحیهٔ یک سال مالی حتی از سال مالی
  قبلی و **بسته‌شده** هم به‌درستی محاسبه می‌شود.
- فیلتر شعبه روی `acc_documents.branch_id` اعمال می‌شود، نه روی حساب — شعبه یک تفکیک سطح سند
  است.

---

## تراز آزمایشی واقعی (`trialBalanceDetailed`)

### امضا

```php
public function trialBalanceDetailed(LedgerQuery|FiscalYear|null $criteria = null): TrialBalanceReport
```

می‌توانید یک `FiscalYear` (بازهٔ `start_date`–`end_date` همان سال)، یک `LedgerQuery` دلخواه
(برای بازهٔ تاریخ آزاد یا فیلتر شعبه)، یا `null` (بدون فیلتر تاریخ — کل تاریخچه) بدهید.

### رفتار

- برای **هر حساب سطح تفصیلی (L3)**: `opening_debit`, `opening_credit`, `period_debit`,
  `period_credit` مستقیماً از `document_items` محاسبه می‌شود (نه `cached_balance`).
- برای **L0–L2** (گروه/کل/معین): مقادیر از جمع فرزندان L3 در حافظه رول‌آپ می‌شود
  (`HierarchyRollup`) — بدون خواندن `cached_balance` والد و بدون N+1.
- خروجی یک `TrialBalanceReport` است که شامل تمام سطوح (۰ تا ۳) می‌شود.

### خروجی — `TrialBalanceReport`

```php
$tb = Accounting::report()->trialBalanceDetailed($fiscalYear);

$tb->rows;             // Collection<TrialBalanceRow> — همهٔ سطوح
$tb->level(3);         // فقط ردیف‌های تفصیلی
$tb->detail();         // مترادف level(3)
$tb->find($accountId); // یک ردیف مشخص یا null
$tb->totals();         // جمع‌های تطبیقی بر اساس ردیف‌های L3
$tb->toArray();
```

هر `TrialBalanceRow` شامل: `accountId`, `parentId`, `code`, `title`, `level`, `type`, `nature`,
`openingDebit`, `openingCredit`, `periodDebit`, `periodCredit`, `endingDebit`, `endingCredit`
و متدهای `openingBalance()`, `periodNet()`, `endingBalance()`.

### اتحادهای تراز (Reconciliation)

روی ردیف‌های L3، همیشه برقرار است:

```
SUM(opening debit) == SUM(opening credit)
SUM(period debit)  == SUM(period credit)
SUM(ending debit)  == SUM(ending credit)
opening + period   == ending   (برای هر ردیف)
```

`$tb->totals()` همین مجموع‌ها را برمی‌گرداند.

### `trialBalance()` قدیمی — **Deprecated**

```php
/** @deprecated از 13.2.0؛ به‌جای آن از trialBalanceDetailed() استفاده کنید. */
public function trialBalance(?FiscalYear $fiscalYear = null): array
```

این متد برای سازگاری با نسخه‌های قبل **بدون تغییر رفتار** باقی مانده، اما **تراز آزمایشی واقعی
نیست**: افتتاحیه/دورهٔ جدا ندارد، رول‌آپ سلسله‌مراتب ندارد، ردیف‌های با مانده نزدیک صفر را
حذف می‌کند و فقط یک سال مالی را می‌بیند. کد جدید باید از `trialBalanceDetailed()` استفاده کند.

خروجی همان قبلی: `[['account' => Account, 'debit' => float, 'credit' => float], ...]`.

---

## دفتر کل (`generalLedger`)

### امضا

```php
public function generalLedger(LedgerQuery $query): GeneralLedgerReport
```

```php
use Karnoweb\Accounting\Reporting\LedgerQuery;

$query = LedgerQuery::make()
    ->forAccounts([$cash, $bank])
    ->forFiscalYear($fiscalYear)
    ->branch($branchId);

$gl = Accounting::report()->generalLedger($query);

$ledger = $gl->forAccount($cash->id); // AccountLedger|null
$gl->toArray();
```

### `AccountLedger`

هر حساب درخواستی یک `AccountLedger` می‌گیرد:

```php
$ledger->accountId;
$ledger->openingBalance;   // قبل از from — بدهکار منفی/مثبت بر اساس debit-credit
$ledger->lines;            // Collection<LedgerLine> — به ترتیب قطعی
$ledger->closingBalance;   // openingBalance + مجموع signedAmount() ردیف‌ها
```

### `LedgerLine`

هر ردیف شامل تمام اطلاعات لازم برای نمایش است:

| فیلد | شرح |
|------|-----|
| `itemId`, `documentId`, `accountId` | شناسه‌ها |
| `date` | تاریخ سند (Carbon) |
| `documentNumber`, `documentType` | شماره و نوع سند |
| `documentDescription`, `reference` | توضیحات و مرجع |
| `sourceType`, `sourceId` | منبع polymorphic سند (اختیاری) |
| `debit`, `credit` | مبالغ خام ردیف |
| `signedAmount()` | `debit - credit` |
| `runningBalance` | مانده جاری تا این ردیف (شامل افتتاحیه) |
| `fiscalYearId`, `branchId` | سال مالی و شعبهٔ سند |
| `order` | ترتیب ردیف در سند |

مانده جاری (`runningBalance`) = `openingBalance` + جمع تجمعی `signedAmount()` ردیف‌های قبلی
(به همان ترتیب قطعی)؛ مانده پایانی (`closingBalance`) = `openingBalance` + گردش کل دوره.

### پیمایش/صفحه‌بندی

برای بازه‌های بزرگ (مثلاً کل یک سال مالی) از `LedgerQuery::cursor()` استفاده می‌شود که به‌جای
بارگذاری کامل نتیجه در حافظه، به‌صورت stream می‌خواند. برای هر حساب مانده جاری به‌ترتیب محاسبه
می‌شود، بنابراین صفحه‌بندی offset-وار وسط یک حساب (بدون بازمحاسبهٔ مانده) در این فاز پیاده‌سازی
نشده — بازهٔ معمول (یک سال مالی، یک شعبه، فهرست محدود حساب) مشکلی ندارد.

---

## دفتر معین یک حساب (`accountStatement`)

### امضا

```php
public function accountStatement(LedgerQuery $query): AccountLedger
```

منطق کاملاً از همان `LedgerQuery`/`generalLedger` استفاده می‌شود (بدون تکرار SQL). `$query`
باید دقیقاً به **یک حساب** محدود شده باشد (`forAccount()`)؛ در غیر این صورت
`InvalidArgumentException` پرتاب می‌شود.

```php
$query = LedgerQuery::make()->forAccount($cash)->forFiscalYear($fiscalYear);
$statement = Accounting::report()->accountStatement($query);

$statement->openingBalance;
foreach ($statement->lines as $line) {
    echo $line->date->toDateString(), ' ', $line->debit, ' ', $line->credit, ' ', $line->runningBalance, PHP_EOL;
}
$statement->closingBalance;
```

---

## گردش حساب (`getTurnover`) — با فیلتر سال مالی/شعبه

### امضا

```php
public function getTurnover(
    Account|int $account,
    Carbon|string $fromDate,
    Carbon|string $toDate,
    array $options = []
): array
```

فراخوانی سه‌آرگومانی قدیمی **بدون تغییر** کار می‌کند. آرگومان چهارم اختیاری است:

```php
use Karnoweb\Accounting\Facades\Accounting;

// قبلی — بدون تغییر
$t = Accounting::balance()->getTurnover($account, '2024-01-01', '2024-12-31');

// جدید — فیلتر سال مالی و/یا شعبه
$t = Accounting::balance()->getTurnover($account, '2024-01-01', '2024-12-31', [
    'fiscal_year' => $fiscalYear, // یا شناسه عددی
    'branch_id'   => $branchId,
]);

// ['debit' => float, 'credit' => float, 'balance' => float]
```

مقادیر همیشه از دفتر ثبت‌شده محاسبه می‌شوند (بدون کش).

---

## مبلغ جهت‌دار (نتیجهٔ سود/زیان) — `AccountNature::naturalAmount()`

برای این‌که مصرف‌کننده (مثلاً Karno Base) بتواند اثر یک گردش را روی سود/زیان به‌درستی محاسبه
کند، بدون سوءاستفاده از `abs()`:

```php
use Karnoweb\Accounting\Enums\AccountNature;

$movement = $account->nature->naturalAmount($debit, $credit);
```

قانون: برای حساب با ماهیت **بدهکار** (دارایی، هزینه) مقدار `debit - credit` برگردانده می‌شود؛
برای ماهیت **بستانکار** (بدهی، سرمایه، **درآمد**) مقدار `credit - debit`. یعنی یک برگشت از
فروش (بدهکار روی حساب درآمد) همیشه درآمد را کاهش می‌دهد، نه این‌که با `abs()` قدر مطلق گرفته
شود.

---

## آنچه پکیج ارائه نمی‌دهد

موارد زیر عمداً خارج از کرنل هستند و باید در اپلیکیشن (مثلاً Karno Base) پیاده شوند:

- UI گزارش‌ها (رندر HTML/PDF/Excel)
- صورت سود و زیان کامل (تجمیع چند حساب درآمد/هزینه + قالب‌بندی صورت مالی)
- ترازنامه مدیریتی
- افتتاحیه/اختتامیهٔ سال مالی
- زیرسیستم مشتری/تأمین‌کننده (subledger)
- صورت جریان وجه نقد
- تفکیک حساب مقابل (counterpart) برای اسناد بیش از دو ردیف — برای سند دو ردیفی مصرف‌کننده
  می‌تواند خودش آن را استنتاج کند؛ برای سند N-ردیفی این کار مبهم است و در پکیج انجام نمی‌شود.
