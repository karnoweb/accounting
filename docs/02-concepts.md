# مفاهیم اصلی

این فایل فقط مفاهیمی را توضیح می‌دهد که **در خود هسته پکیج پیاده‌سازی شده‌اند**.

## ۱. حساب (Account)

`Account` واحد پایه ثبت مالی در این پکیج است. هر `DocumentItem` دقیقاً به یک حساب متصل می‌شود.

### انواع حساب

انواع حساب با `AccountType` مشخص می‌شوند:

- `asset`
- `liability`
- `equity`
- `income`
- `expense`

### ماهیت حساب

ماهیت با `AccountNature` مشخص می‌شود:

- `debit`
- `credit`

پیش‌فرض ماهیت از نوع حساب به دست می‌آید:

- دارایی و هزینه: بدهکار
- بدهی، سرمایه و درآمد: بستانکار

### دائمی و موقت

از نظر رفتار سال مالی:

- حساب‌های **دائمی**: `asset`, `liability`, `equity`
- حساب‌های **موقت**: `income`, `expense`

این تفکیک برای `OpeningService` و `ClosingService` قرارداد اصلی است:

- افتتاحیه فقط روی حساب‌های دائمی مجاز است.
- بستن سود و زیان فقط مانده حساب‌های موقت را صفر می‌کند.

### ساختار درختی حساب‌ها

حساب‌ها `parent_id` دارند و درختی هستند. سطح هر حساب از والد آن به‌دست می‌آید.

- `max_level` از `account.code_length` یا `account.max_level`
- `posting_level` از `account.posting_level` یا آخرین سطح

در پیکربندی پیش‌فرض:

- سطح ۰: گروه
- سطح ۱: کل
- سطح ۲: معین
- سطح ۳: تفصیلی

### حساب قابل‌ثبت چیست؟

در این پکیج حسابی قابل‌ثبت است که:

1. فعال باشد
2. `allow_direct_posting` داشته باشد
3. در سطح `posting_level` باشد
4. فرزند نداشته باشد

پس تفاوت «حساب» و «حساب قابل‌ثبت» مهم است. هر حسابی قابل‌ثبت نیست.

### حساب سیستمی

بعضی حساب‌ها از دید پیکربندی، **حساب سیستمی** هستند؛ مثل `cash`، `bank` و `retained_earnings`.

حساب سیستمی را می‌توان با `Accounting::systemAccount($key)` پیدا کرد، اما اگر خود رکورد `Account` دارای `is_system = true` باشد:

- کد آن قابل تغییر نیست
- نوع و ماهیت آن قابل تغییر نیست
- حذف آن مجاز نیست

### حساب وابسته به موجودیت

حساب می‌تواند با `entity_type` و `entity_id` به یک مدل بیرونی متصل شود. این اتصال معمولاً توسط `HasAccount` ساخته می‌شود.

این قابلیت به معنی وجود ماژول مستقل «اشخاص» یا «بانک» در هسته نیست؛ فقط یک اتصال polymorphic بین حساب و مدل بیرونی است.

## ۲. سند (Document)

`Document` ظرف ثبت حسابداری است. هدر سند اطلاعاتی مثل:

- سال مالی
- شماره سند
- تاریخ
- نوع
- وضعیت
- توضیح
- مرجع (`reference`)

را نگه می‌دارد.

### انواع سند موجود

مقادیر پیکربندی‌شده در `accounting.document.allowed_types`:

- `sale`
- `purchase`
- `receipt`
- `payment`
- `transfer`
- `opening`
- `closing`
- `adjustment`
- `reversal`

نکته مهم: در کد فعلی **اعتبارسنجی سفت‌وسختی روی allowed_types اعمال نشده است**. بنابراین این فهرست بیشتر قرارداد پیکربندی است تا guard اجرایی.

### وضعیت سند

مقادیر `DocumentStatus`:

- `draft`
- `pending`
- `approved`
- `posted`
- `voided`

رفتار واقعی:

- ثبت قطعی از `draft` و `approved` مجاز است.
- سند `posted` دیگر قابل ویرایش نیست.
- سند `voided` دیگر قابل ویرایش نیست.
- حذف سند `posted` یا `voided` مجاز نیست.

## ۳. ردیف سند (DocumentItem)

`DocumentItem` هر ردیف حسابداری سند است.

هر ردیف شامل این داده‌های اصلی است:

- `account_id`
- `cost_center_id`
- `amount`
- `sign`
- `debit`
- `credit`
- `order`

### مدل‌سازی بدهکار و بستانکار

این پکیج بدهکار/بستانکار را با دو لایه مدل می‌کند:

- `sign = 1` یعنی بدهکار
- `sign = -1` یعنی بستانکار

و هنگام ذخیره:

- اگر `sign = 1` باشد، `debit = amount` و `credit = 0`
- اگر `sign = -1` باشد، `credit = amount` و `debit = 0`

پس `amount` مقدار خام ردیف است و `sign` جهت آن را تعیین می‌کند.

### تعادل سند

معیار تعادل سند، مقایسهٔ دقیق اعشاری است:

`SUM(amount × sign) == 0`

جمع و مقایسه با `Amount` انجام می‌شود، نه با float و نه با تلورانس `0.01`.
مقیاس مقایسه همان `accounting.general.decimal_places` است (پیش‌فرض ۲، مطابق ستون‌های `decimal(15,2)`).

`0.10 + 0.20` در این هسته دقیقاً `0.30` است. اختلاف یک سنت (`0.01`) سند را نامتعادل می‌کند.

## ۴. سال مالی (Fiscal Year)

`FiscalYear` ظرف سالانه ثبت است: `draft` → `active` → `closed`.

### تفاوت سال مالی و دوره مالی (Accounting Period)

از نسخه `13.6.0`، `AccountingPeriod` یک موجودیت persisted داخل پکیج است و قفل
ثبت کوچک‌تر از سال مالی را فراهم می‌کند (`draft` → `open` → `closed`).

- هر دوره دقیقاً به یک سال مالی تعلق دارد و باید داخل بازه همان سال باشد.
- دوره‌های یک سال مالی نباید هم‌پوشانی داشته باشند.
- ثبت اسناد فقط در دورهٔ `open` مجاز است؛ دورهٔ `closed` قابل بازگشایی نیست.
- `PostingService` / `DocumentService` در لایه دامنه دوره را enforce می‌کنند
  (نه فقط UI/middleware).
- شعبه همچنان روی `documents` / `accounts` ایزوله می‌ماند؛ دوره branch-scoped نیست.

API کانونیکال: `Accounting::period()` (`create` / `open` / `close` / `resolve` /
`assertAllowsPosting`). روی `activate()` سال مالی، اگر هنوز دوره‌ای نباشد، یک
دورهٔ باز تمام‌ساله ساخته می‌شود (`accounting.period.auto_create_on_activate`).

### `opening_done`

فیلد `opening_done` فقط یک **فلگ تکمیل چرخه** است. این فیلد به‌تنهایی اثبات نمی‌کند چه سندی ایجاد شده، بلکه نشان می‌دهد مرحله افتتاحیه برای سال فعال complete شده است.

## ۵. مرکز هزینه (Cost Center)

`CostCenter` یک بعد تحلیلی اختیاری روی `DocumentItem` است.

نقش آن در نسخه فعلی:

- روی ردیف سند ذخیره می‌شود
- در مدل و دیتابیس رابطه دارد

- فیلتر گزارش با `LedgerQuery::costCenter()` و `costCenterStatementPaginated()` پشتیبانی می‌شود.
- تخصیص یا تسهیم خودکار مرکز هزینه در هسته **نیست**.

## ۶. شعبه (Branch)

شعبه در این پکیج یک ماژول کامل نیست. فقط `branch_id` روی:

- `accounts`
- `documents`

ذخیره می‌شود.

جدول `branches` باید در اپلیکیشن مصرف‌کننده وجود داشته باشد اگر بخواهید رابطه Eloquent واقعی داشته باشید.

### تفاوت شعبه و حساب

- شعبه، بُعد تفکیک عملیاتی سند است.
- حساب، موضوع مالی ثبت است.

گزارش‌ها نیز فیلتر شعبه را روی `documents.branch_id` اعمال می‌کنند، نه روی خود حساب.

## ۷. افتتاحیه (Opening)

افتتاحیه در این پکیج با `type=opening` و `OpeningService` پیاده‌سازی شده است.

### چه مسئله‌ای را حل می‌کند؟

- ثبت مانده ابتدای سال مالی
- انتقال مانده حساب‌های دائمی از سال مالی بسته به سال بعد

### جریان پیش‌نویس → تأیید (از نسخهٔ ۱۳.۵.۰)

افتتاحیه برای هر باکت (سال مالی + شعبه) دو مرحله دارد:

1. **`saveDraft($fy, $items, $branchId = null)`** — سند `type=opening, status=draft` می‌سازد (یا اگر پیش‌نویس قبلی برای همین باکت وجود دارد، ردیف‌هایش را جای‌گزین می‌کند). این پیش‌نویس **می‌تواند نامتوازن باشد** — تعادل فقط در مرحلهٔ بعد بررسی می‌شود.
2. **`confirm($fy, $branchId = null)`** — همان سند را در جا به `posted` تبدیل می‌کند و تعادل را اجباری می‌کند. رد شدن به‌خاطر اسناد عملیاتی posted فقط وقتی است که `accounting.opening.allow_after_posted_activity` برابر `false` باشد (پیش‌فرض فعلی `true` است). `opening_done` وقتی `true` می‌شود که هیچ افتتاحیهٔ `draft` دیگری برای آن سال باقی نمانده باشد.

متد `find($fy, $branchId = null)` افتتاحیهٔ `draft` یا `posted` همان باکت را برمی‌گرداند (یا `null`). متد قدیمی `post($fy, $items, $branchId = null)` هنوز هست و برای سازگاری معادل `saveDraft()` + `confirm()` در یک تراکنش است.

`OpeningService::carryForward()` نیز از نسخهٔ ۱۳.۵.۰ فقط افتتاحیهٔ **`draft`** می‌سازد؛ `opening_done` تا زمانی که هر باکت جدا با `confirm()` تأیید نشود `false` می‌ماند.

### قواعد اصلی

- فقط در سال مالی `active`
- فقط روی حساب‌های دائمی
- گیت «بعد از فعالیت عملیاتی» فقط روی `confirm()` / `post()` است و با کانفیگ کنترل می‌شود؛ `saveDraft()` آن را چک نمی‌کند
- با کلید قطعی `opening:{fyId}:branch:{id|none}` — مشترک بین نسخهٔ draft و posted همان باکت
- جزئیات کانفیگ: [17-multi-active-years-and-opening.md](17-multi-active-years-and-opening.md)

## ۸. بستن سود و زیان (Closing)

بستن سود و زیان با `ClosingService` و سند `type=closing` انجام می‌شود.

### چه مسئله‌ای را حل می‌کند؟

مانده حساب‌های موقت را در پایان سال فعال به حساب سود انباشته منتقل می‌کند.

### قواعد اصلی

- فقط در سال مالی `active`
- فقط روی حساب‌های موقت
- با حساب سیستمی `retained_earnings`
- سال مالی را خودکار `closed` نمی‌کند

## ۹. برگشت (Reversal)

برگشت عملیاتی با `ReversalService` یک **سند جدید** از نوع `reversal` می‌سازد.

### تفاوت با ابطال

- ابطال: سند اصلی از دفتر posted خارج می‌شود.
- برگشت: سند اصلی می‌ماند و سند معکوس اضافه می‌شود.

### قواعد اصلی

- فقط روی سند posted
- فقط در همان سال مالی
- فقط وقتی سال مالی هنوز active است
- روی `opening` و `closing` مجاز نیست
- اگر closing posted در همان سال وجود داشته باشد، برگشت رد می‌شود

## ۱۰. گزارش‌ها

گزارش‌های موجود هسته (همه از دفتر posted):

- `trialBalanceDetailed`
- `profitAndLoss`
- `balanceSheet`
- `cashMovements`
- `generalLedger`
- `accountStatement`
- `accountStatementPaginated`
- `generalLedgerSummary`
- `costCenterStatementPaginated`
- `BalanceService::getTurnover`

منبع همه این‌ها **دفتر ثبت‌شده** است:

- `documents.status = posted`
- `document_items JOIN documents`

نه `cached_balance` و نه مدل‌های عملیاتی بیرونی.

صورت جریان وجه نقد کامل (O/I/F) پیاده‌سازی نشده؛ فقط شالوده حرکت حساب‌های نقد پیکربندی‌شده وجود دارد.

مرجع: [09-reports.md](09-reports.md)، [20-financial-statements.md](20-financial-statements.md).

## واژه‌نامه کوتاه

| اصطلاح | معنی |
|--------|------|
| Account | حساب مالی |
| Document | هدر سند |
| DocumentItem | ردیف سند |
| Fiscal Year | سال مالی (`draft` / `active` / `closed`) |
| Accounting Period | دوره ثبت داخل سال مالی (`draft` / `open` / `closed`) |
| Opening | سند مانده ابتدای سال |
| Closing | سند بستن حساب‌های موقت |
| Reversal | سند معکوس یک سند posted |
| Void | ابطال سند posted بدون ساخت سند جدید |

جزئیات سال مالی: [fiscal-year-lifecycle.md](fiscal-year-lifecycle.md)  
جزئیات مبلغ: [18-monetary-arithmetic.md](18-monetary-arithmetic.md)

---

[→ ادامه: معماری فنی (03-architecture.md)](03-architecture.md)

[← بازگشت: معرفی کلی (01-overview.md)](01-overview.md)

[⌂ فهرست (00-index.md)](00-index.md)
