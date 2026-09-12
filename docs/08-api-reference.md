# مرجع API

این فایل فقط APIهایی را پوشش می‌دهد که در کد فعلی وجود دارند.
نسخه پکیج را از `composer.json` / `Accounting::version()` بخوانید، نه از این سند.

## Facade اصلی

```php
use Karnoweb\Accounting\Facades\Accounting;
```

### متدهای utility

| متد | خروجی | شرح |
|-----|-------|-----|
| `version()` | `string` | نسخه پکیج از `composer.json` |
| `currentFiscalYear()` | `?FiscalYear` | سال `active` با `is_current`، وگرنه اولین سال `active` |
| `currentBranch()` | `?Model` | شعبه از resolver / `default_id` / `is_default` |
| `systemAccount(string $key, ?int $branchId = null)` | `Account` | resolve حساب سیستمی، اختیاری برای یک شعبه |

### دسترسی به سرویس‌ها

| متد | خروجی |
|-----|-------|
| `document()` | `DocumentBuilder` |
| `account()` | `AccountService` |
| `balance()` | `BalanceService` |
| `report()` | `ReportService` |
| `fiscalYear()` | `FiscalYearService` |
| `period()` | `AccountingPeriodService` |
| `posting()` | `PostingService` |
| `opening()` | `OpeningService` |
| `closing()` | `ClosingService` |
| `reversal()` | `ReversalService` |

هلپرهای سراسری مثل `accounting()`, `current_fiscal_year()`, `system_account()` در پکیج **وجود ندارند**.

## `DocumentBuilder`

| متد | ورودی |
|-----|-------|
| `type(string $type)` | نوع سند |
| `date(Carbon\|string $date)` | تاریخ |
| `description(string $text)` | شرح |
| `notes(string $text)` | یادداشت |
| `reference(string $text)` | مرجع |
| `branch(Model\|int $branch)` | شعبه |
| `fiscalYear(FiscalYear\|int $fiscalYear)` | سال مالی |
| `source(Model $model)` | منبع polymorphic |
| `idempotencyKey(string $key)` | کلید یکتایی retry |
| `meta(array $meta)` | داده اضافی |
| `debit(Account\|int $account, int\|float\|string $amount, ?string $description = null)` | ردیف بدهکار (رشته اعشاری ترجیح داده می‌شود) |
| `credit(Account\|int $account, int\|float\|string $amount, ?string $description = null)` | ردیف بستانکار |
| `costCenter(CostCenter\|int\|null $center)` | مرکز هزینه برای ردیف آخر یا بعدی |
| `save()` | ایجاد سند draft |
| `post()` | ایجاد و ثبت قطعی |
| `toArray()` | payload فعلی |

- هر `Accounting::document()` یک builder تازه است و بعد از `save()`/`post()` ریست می‌شود.
- `item()`, `validate()`, `getItems()`, `getTotal()` وجود ندارند.
- `create()`/`save()` هم از `PostingService` رد می‌شوند: سال مالی `active` و دوره `open` لازم است.

## `AccountService`

| متد | شرح |
|-----|-----|
| `create(array $data)` | ایجاد حساب |
| `assertPostable(Account\|int $account)` | اطمینان از قابل‌ثبت بودن |
| `find(int $id)` | جستجو با شناسه |
| `findOrFail(int $id)` | جستجو با خطا |
| `findByCode(string $code, ?int $branchId = null)` | جستجو با کد |
| `findByCodeOrFail(string $code, ?int $branchId = null)` | جستجو با خطا |
| `findByCodeForBranch(string $code, ?int $branchId)` | اولویت حساب شعبه، سپس حساب مشترک |
| `findByCodeForBranchOrFail(...)` | همان با exception |
| `findByEntity(string $entityType, int $entityId)` | لینک polymorphic |
| `getSystemAccount(string $key, ?int $branchId = null)` | حساب سیستمی |
| `search(array $filters)` | فیلترهای واقعی: `query`, `type`, `level`, `is_active`, `branch_id` |

`update()`, `delete()`, `getTree()`, `validateCode()` روی این سرویس **وجود ندارند**.

```php
$account = Accounting::account()->create([
    'parent_code' => '1102',
    'title' => 'بانک ملت - حساب جاری',
    'type' => 'asset',
    'nature' => 'debit',
]);
```

## `DocumentService`

| متد | شرح |
|-----|-----|
| `create(array $data)` | ایجاد سند و ردیف‌ها |
| `post(Document\|int $document)` | ثبت قطعی |
| `getNextNumber(?FiscalYear $fiscalYear = null, ?int $branchId = null)` | شماره بعدی |
| `isBalanced(Document $document)` | تعادل اعشاری دقیق |

`update()`, `delete()`, `submit()`, `approve()`, `reject()`, `void()`, `find()`, `search()` روی این سرویس **نیستند**. ابطال روی `Document::void()` است.

## `BalanceService`

| متد | شرح |
|-----|-----|
| `getBalance()` | مانده حساب |
| `calculateRealtime()` | مانده realtime |
| `getBalanceAsOf()` | مانده تا تاریخ |
| `getDebitTotal()` | جمع بدهکار |
| `getCreditTotal()` | جمع بستانکار |
| `getTurnover()` | گردش بازه؛ آرگومان چهارم اختیاری `{fiscal_year, branch_id}` |
| `refreshCache()` | بازسازی کش |
| `updateAfterDocument(Document $document)` | به‌روزرسانی کش بعد از ثبت (observer) |
| `reverseDocument(Document $document)` | برگرداندن دلتای کش بعد از ابطال (observer) |

`refreshAllCaches()`, `hasNormalBalance()`, `getBalanceWarning()` وجود ندارند.

## `ReportService`

| متد | خروجی |
|-----|--------|
| `trialBalance(?FiscalYear $fiscalYear = null)` | `array` — deprecated |
| `trialBalanceDetailed(LedgerQuery\|FiscalYear\|null $criteria = null)` | `TrialBalanceReport` |
| `profitAndLoss(LedgerQuery\|FiscalYear\|null $criteria = null)` | `ProfitAndLossReport` |
| `balanceSheet(LedgerQuery\|FiscalYear\|null $criteria = null)` | `BalanceSheetReport` |
| `cashMovements(LedgerQuery\|FiscalYear\|null $criteria = null)` | `CashMovementReport` |
| `generalLedger(LedgerQuery $query)` | `GeneralLedgerReport` |
| `accountStatement(LedgerQuery $query)` | `AccountLedger` |
| `accountStatementPaginated(LedgerQuery $query, int $page = 1, ?int $perPage = null)` | `PaginatedAccountStatement` |
| `costCenterStatementPaginated(LedgerQuery $query, int $page = 1, ?int $perPage = null)` | `PaginatedCostCenterStatement` |
| `generalLedgerSummary(LedgerQuery $query, int $page = 1, ?int $perPage = null)` | `PaginatedGeneralLedgerSummary` |

`incomeStatement()`, `costCenterReport()`, `branchReport()` **وجود ندارند**. سود و زیان همان `profitAndLoss()` است. فیلتر شعبه/مرکز هزینه/بازه از `LedgerQuery` می‌آید، نه از آرگومان نام‌دار روی `trialBalance()`.

مرجع دفتر: [09-reports.md](09-reports.md). صورت‌های مالی: [20-financial-statements.md](20-financial-statements.md).

## `LedgerQuery`

| متد | شرح |
|-----|-----|
| `forAccount()` / `forAccounts()` | محدود به حساب |
| `forFiscalYear()` | محدود به سال مالی |
| `forAccountingPeriod(AccountingPeriod $period)` | FY + `[start, end]` دوره |
| `from()` / `to()` | بازه تاریخ |
| `branch()` | فیلتر `documents.branch_id`؛ `null` یعنی بدون شعبه |
| `costCenter()` | فیلتر `document_items.cost_center_id` |
| `excludeDocumentTypes()` | حذف نوع سند از دوره و افتتاحیه |
| `get()` / `cursor()` / `pageLines()` / `countLines()` / `prefixSignedSum()` | خواندن خطوط |
| `openingBalances()` / `periodTotals()` / `periodTotalsByAccount()` / `trialBalanceAggregates()` | تجمیع |

همیشه posted-only است.

## `FiscalYearService`

| متد | شرح |
|-----|-----|
| `current()` | سال جاری UI (`active` + `is_current`، وگرنه اولین `active`) |
| `findByDate(string $date)` | سال شامل تاریخ (حتی draft/closed) |
| `create(array $data)` | ایجاد draft |
| `update(...)` | `start_date` فقط در draft بدون سند؛ `end_date` در draft/active تا `>= latestDocumentDate()` |
| `activate(...)` | فعال‌سازی؛ با `allow_multiple_active` چند سال می‌توانند `active` بمانند |
| `setCurrent(...)` | فقط `is_current` |
| `findPriorConsecutive(...)` | سال متوالی قبلی |
| `validateCanClose()` / `close()` | بستن سال (بدون ژورنال)؛ دوره‌های باز را می‌بندد؛ اگر `is_current` بود سال active باقی‌مانده promote می‌شود |
| `completeOpening()` / `revertOpening()` | فلگ `opening_done` |
| `assertPriorYearClosedForOpening()` | گیت سال قبل |
| `assertAcceptsPosting()` / `assertNoOverlap()` | کنترل ثبت و هم‌پوشانی |
| `latestDocumentDate()` / `minAllowedEndDate()` | کف `end_date` |

## `AccountingPeriodService`

| متد | شرح |
|-----|-----|
| `create()` / `update()` | ایجاد/ویرایش دوره draft |
| `open()` / `close()` | `draft → open` و `open → closed` (بدون ژورنال) |
| `resolve()` / `resolveOrFail()` | دوره یکتای شامل تاریخ |
| `assertAllowsPosting()` / `allowsPosting()` | gate ثبت |
| `ensureFullYearOpen()` | دوره باز تمام‌ساله اگر هیچ دوره‌ای نباشد |
| `closeOpenPeriodsForFiscalYear()` | بستن دوره‌های باز سال |
| `validateCanClose()` / `hasPeriods()` / `assertNoOverlap()` / `resolveForUpdate()` | کنترل‌های کمکی |

دورهٔ `closed` بازگشایی نمی‌شود.

## `PostingService`

| متد | شرح |
|-----|-----|
| `assertAllowed(...)` | gate FY + period؛ دورهٔ `open` را برمی‌گرداند |
| `isAllowed(...)` | boolean |
| `resolvePeriod(...)` | معادل `Accounting::period()->resolve()` |

`type` و `branchId` در signature هستند، اما در تصمیم ثبت امروز استفاده نمی‌شوند.

## `OpeningService`

جزئیات کانفیگ: [17-multi-active-years-and-opening.md](17-multi-active-years-and-opening.md).

| متد | شرح |
|-----|-----|
| `isComplete()` | فلگ `opening_done` |
| `saveDraft()` | draft باکت (سال + شعبه)؛ می‌تواند نامتوازن باشد |
| `confirm()` | ثبت همان draft؛ تعادل اجباری است |
| `find()` | draft یا posted همان باکت |
| `post()` | `saveDraft()` + `confirm()` |
| `carryForward()` | draft از سال `closed` یا (با کانفیگ) از سال `active` موقت |

## `ClosingService`

| متد | شرح |
|-----|-----|
| `isProfitAndLossClosed()` | صفر بودن مانده حساب‌های موقت |
| `closeProfitAndLoss()` | سند `type=closing` به `retained_earnings` |

سال مالی را `closed` نمی‌کند.

## `ReversalService`

| متد | شرح |
|-----|-----|
| `reverse(Document\|int $document, array $options = [])` | سند معکوس همان FY |
| `idempotencyKey(Document $original)` | `reversal:{id}` |

گزینهٔ معتبر: `reason`, `date`. `fiscal_year_id` و `branch_id` از اصل کپی می‌شوند و از options قابل override نیستند.

## مدل‌ها — متدهای واقعی

### `Document`

`post()`, `reverse(?string $reason = null)`, `markAsPosted()`, `void(string $reason = '')`, `isBalanced()`, `isPosted()`, `isEditable()`, `isVoidable()`, `postedReversal()`, روابط `items`, `logs`, `fiscalYear`, `accountingPeriod`, `reversedDocument`, `reversals`, `source`, `createdBy`. Scopeها: `posted()`, `draft()`. Attributeها: `debit_total`, `credit_total`.

### `FiscalYear`

`current()`, `findByDate()`, `activate()`, `close()`, `completeOpening()`, `revertOpening()`, `setCurrent()`, `isActive()`, `isClosed()`, `containsDate()`, روابط `documents`, `accountingPeriods`. Accessorها: `status_label`, `latest_document_date`, `min_allowed_end_date`.

### `Account`

`balance()`, `isPostable()`, `assertPostable()`, `canDelete()`, `refreshBalance()`, روابط `parent`, `children`, `items`, `entity`, `branch`. Scopeها: `active()`, `ofType()`, `ofLevel()`. Attribute: `natural_balance`.

### `AccountingPeriod`

`open()`, `close()`, `isDraft()`, `isOpen()`, `isClosed()`, `containsDate()`, روابط `fiscalYear`, `documents`.

### `CostCenter`

رابطه واقعی: `documentItems()` — نه `items()`.

## رویدادها

- `DocumentCreated`, `DocumentPosted`, `DocumentVoided`
- `AccountingPeriodOpened`, `AccountingPeriodClosed`, `PostingRejectedForClosedPeriod`

## استثناهای موجود

`UnbalancedDocumentException`, `ClosedFiscalYearException`, `FiscalYearStateException`, `InvalidFiscalYearException`, `FiscalYearOverlapException`, `InactiveAccountException`, `InvalidPostingAccountException`, `InvalidAccountHierarchyException`, `DuplicateIdempotencyKeyException`, `AccountNotFoundException`, `DocumentNotEditableException`, `DocumentNotReversibleException`, `SystemAccountException`, `ClosedAccountingPeriodException`, `AccountingPeriodStateException`, `AccountingPeriodOverlapException`, `InvalidAccountingPeriodException`.

`InvalidDocumentStatusException` و `InsufficientBalanceException` **وجود ندارند**.

## آنچه در این پکیج API نیست

- صورت سود و زیان / ترازنامه / جریان وجه نقد
- workflow تأیید (`submit` / `approve`) به‌عنوان سرویس
- هلپر سراسری `accounting_*`
- `AccountService::update/delete/getTree`
- `DocumentService::void`
- Macroable بودن سرویس‌ها

---

[⌂ فهرست](00-index.md) · [usage.md](usage.md) · [09-reports.md](09-reports.md)
