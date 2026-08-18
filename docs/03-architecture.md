# معماری فنی

## نمای کلی

معماری این پکیج حول سه هسته می‌چرخد:

1. **دفتر حسابداری**: `Document` و `DocumentItem`
2. **ساختار حساب‌ها**: `Account`
3. **کنترل دوره ثبت**: `FiscalYear` و `PostingService`

خدمات دیگر مثل افتتاحیه، اختتامیه، برگشت و گزارش‌گیری روی همین سه هسته سوار می‌شوند.

## مرزهای دامنه

### داخل دامنه پکیج

- حساب و ساختار حساب
- سند و ردیف سند
- سال مالی
- مانده و گردش
- گزارش‌های ledger-based
- Audit سند

### خارج از دامنه پکیج

- فرآیند فروش، خرید، انبار، CRM
- تعریف مشتری، فروشنده، بانک، صندوق به‌عنوان ماژول مستقل
- UI، کنترلر، API اپلیکیشن
- سیاست‌های دسترسی پروژه

## لایه‌های واقعی

### ۱. Entry Point

- `Accounting` facade
- `AccountingManager`
- `AccountingServiceProvider`

این لایه فقط دسترسی به سرویس‌ها را ساده می‌کند و خودش منطق حسابداری پیچیده ندارد.

### ۲. Application Services

- `AccountService`
- `DocumentService`
- `BalanceService`
- `ReportService`
- `FiscalYearService`
- `PostingService`
- `OpeningService`
- `ClosingService`
- `ReversalService`
- `DocumentBuilder`

این لایه محل اصلی orchestration و business ruleها است.

### ۳. Domain Model

- `Account`
- `Document`
- `DocumentItem`
- `FiscalYear`
- `CostCenter`
- `DocumentLog`
- `DocumentNumberSequence`
- Enumها و Exceptionها

مدل‌ها بخشی از قواعد را داخل `booted()` و متدهای خود نگه می‌دارند، اما اکثر جریان‌های مهم در سرویس‌ها جمع شده‌اند.

### ۴. Persistence / Side Effects

- Migrationها
- `DocumentObserver`
- Cache مربوط به `BalanceService`
- رویدادهای `DocumentCreated`, `DocumentPosted`, `DocumentVoided`

## جریان اصلی ثبت سند

```mermaid
flowchart TD
    A[Accounting::document یا DocumentService::create] --> B[Resolve Fiscal Year]
    B --> C[Validate items and postable accounts]
    C --> D[Allocate document number]
    D --> E[Create Document]
    E --> F[Create DocumentItem rows]
    F --> G[DocumentService::post]
    G --> H[Validate status, balance, fiscal year, accounts]
    H --> I[Mark document as posted]
    I --> J[DocumentObserver]
    J --> K[BalanceService::updateAfterDocument]
    J --> L[DocumentPosted event]
```

## جریان ابطال سند

```mermaid
flowchart TD
    A[Document::void] --> B[Lock fiscal year and document]
    B --> C[Check voidability and posted reversal]
    C --> D[Update status to voided]
    D --> E[DocumentObserver::handleVoided]
    E --> F[BalanceService::reverseDocument]
    E --> G[DocumentVoided event]
```

## جریان برگشت سند

```mermaid
flowchart TD
    A[Document::reverse یا ReversalService] --> B[Lock fiscal year and original document]
    B --> C[Check active FY and no posted closing]
    C --> D[Invert persisted items]
    D --> E[Create type=reversal document]
    E --> F[Post reversal document]
```

## روابط ماژول‌ها

```mermaid
classDiagram
    FiscalYear "1" --> "*" Document
    Document "1" --> "*" DocumentItem
    DocumentItem "*" --> "1" Account
    DocumentItem "*" --> "0..1" CostCenter
    Account "0..1" --> "*" Account : parent/children
    Document "1" --> "*" DocumentLog
    Document "0..1" --> "*" Document : reversal/original
```

## مسئولیت هر سرویس

### `AccountService`

- ایجاد حساب
- تولید خودکار کد
- resolve حساب‌های سیستمی
- enforce کردن قواعد سلسله‌مراتب

### `DocumentService`

- ایجاد سند
- تخصیص شماره سند
- validate ردیف‌ها
- validate `idempotency_key`
- ثبت قطعی سند

### `PostingService`

- gate نهایی برای این پرسش: «آیا در این تاریخ و این سال مالی ثبت مجاز است؟»

### `FiscalYearService`

- create / update / activate / close
- کنترل هم‌پوشانی سال‌ها
- کنترل `opening_done`

### `OpeningService`

- ثبت سند افتتاحیه دستی
- انتقال مانده از سال بسته به سال بعد

### `ClosingService`

- صفر کردن حساب‌های موقت
- انتقال سود/زیان خالص به `retained_earnings`

### `ReversalService`

- ساخت سند معکوس برای یک سند posted عملیاتی

### `ReportService`

- ساخت گزارش‌های ledger-based
- حفظ یک foundation مشترک با `LedgerQuery`

## مهم‌ترین Business Ruleها و محل enforce شدن

| قاعده | محل اصلی enforce |
|------|-------------------|
| سند باید متعادل باشد | `DocumentService` |
| ثبت فقط روی حساب قابل‌ثبت | `Account::assertPostable()` و `AccountService` |
| سند posted/voided قابل ویرایش نیست | `Document` و `DocumentItem` |
| هم‌پوشانی سال مالی ممنوع | `FiscalYearService` |
| فقط یک سال مالی active | `FiscalYearService` |
| گزارش‌ها فقط posted را می‌بینند | `LedgerQuery` |
| افتتاحیه فقط برای حساب‌های دائمی | `OpeningService` |
| اختتامیه فقط برای حساب‌های موقت | `ClosingService` |
| برگشت فقط در همان سال مالی active | `ReversalService` |

## نکات معماری که نباید بدون بررسی تغییر کنند

1. ترتیب گزارش‌ها در `LedgerQuery`، چون روی `runningBalance` اثر مستقیم دارد.
2. قاعده detail-only posting، چون روی همه گزارش‌ها و افتتاحیه/اختتامیه اثر دارد.
3. تفاوت `void` و `reversal`.
4. این اصل که گزارش‌ها از `cached_balance` نمی‌خوانند.
5. این اصل که `PostingService` تنها gate عمومی ثبت است.

جداسازی منطق تجاری از Controller و Model.

| بدون Service | با Service |
|--------------|------------|
| منطق در Controller | Controller فقط HTTP را مدیریت می‌کند |
| تکرار کد | استفاده مجدد از منطق |
| تست سخت | تست آسان |

### ۳.۴ الگوی Observer

واکنش خودکار به تغییرات Model.

| رویداد | واکنش Observer |
|--------|----------------|
| Document created | ثبت در Audit Log |
| Document posted | بروزرسانی مانده حساب‌ها |
| Document voided | برگرداندن مانده حساب‌ها |

### ۳.۵ الگوی Builder

ساخت اسناد پیچیده به صورت مرحله‌ای.

**بدون Builder:**
```php
$document = new Document();
$document->type = 'sale';
$document->date = now();
$document->save();

$item1 = new DocumentItem();
$item1->document_id = $document->id;
$item1->account_id = 1;
$item1->amount = 1000;
$item1->sign = 1;
$item1->save();

// و ادامه...
```

**با Builder:**
```php
Accounting::document()
    ->type('sale')
    ->date(now())
    ->debit($account1, 1000)
    ->credit($account2, 1000)
    ->post();
```

---

## ۴. ساختار پکیج

### ۴.۱ پوشه‌بندی اصلی

| پوشه | محتوا | تعداد فایل |
|------|-------|------------|
| src/Models | مدل‌های Eloquent | ۷ |
| src/Services | سرویس‌های منطق تجاری | ۵ |
| src/Traits | Trait های قابل استفاده | ۱ |
| src/Enums | Enum های وضعیت و نوع | ۴ |
| src/Events | رویدادهای سیستم | ۳ |
| src/Observers | Observer های Model | ۱ |
| src/Exceptions | خطاهای سفارشی | ۳ |
| src/Facades | Facade ها | ۱ |
| config | فایل تنظیمات | ۱ |
| database/migrations | Migration ها | ۷ |
| database/seeders | Seeder ها | ۱ |
| lang | فایل‌های زبان | ۲ پوشه |

### ۴.۲ جزئیات پوشه Models

| فایل | مسئولیت |
|------|---------|
| Account.php | مدل حساب |
| Document.php | مدل سند |
| DocumentItem.php | مدل آیتم سند |
| DocumentLog.php | مدل لاگ تغییرات |
| FiscalYear.php | مدل سال مالی |
| Branch.php | مدل شعبه |
| CostCenter.php | مدل مرکز هزینه |

### ۴.۳ جزئیات پوشه Services

| فایل | مسئولیت |
|------|---------|
| AccountService.php | CRUD حساب‌ها، جستجو، درخت حساب |
| DocumentService.php | ثبت، ویرایش، حذف، تغییر وضعیت سند |
| BalanceService.php | محاسبه مانده، Cache، بروزرسانی |
| ReportService.php | تولید گزارش‌های مالی |
| FiscalYearService.php | مدیریت سال مالی، افتتاحیه، اختتامیه |

### ۴.۴ جزئیات پوشه Enums

| فایل | مقادیر |
|------|--------|
| AccountType.php | asset, liability, equity, income, expense |
| AccountNature.php | debit, credit |
| DocumentStatus.php | draft, pending, approved, posted, voided |
| FiscalYearStatus.php | draft, active, closed |

### ۴.۵ جزئیات پوشه Events

| فایل | زمان Fire شدن |
|------|---------------|
| DocumentCreated.php | پس از ایجاد سند |
| DocumentPosted.php | پس از ثبت قطعی سند |
| DocumentVoided.php | پس از ابطال سند |

### ۴.۶ جزئیات پوشه Exceptions

| فایل | زمان پرتاب |
|------|------------|
| UnbalancedDocumentException.php | سند بالانس نیست |
| ClosedFiscalYearException.php | ثبت در سال مالی بسته |
| InactiveAccountException.php | استفاده از حساب غیرفعال |

---

## ۵. جریان داده‌ها

### ۵.۱ جریان ثبت سند

| مرحله | عملیات | مسئول |
|-------|--------|-------|
| ۱ | دریافت درخواست | Controller/Facade |
| ۲ | اعتبارسنجی داده‌ها | DocumentService |
| ۳ | بررسی سال مالی | FiscalYearService |
| ۴ | بررسی حساب‌ها | AccountService |
| ۵ | بررسی بالانس | DocumentService |
| ۶ | ذخیره سند | Document Model |
| ۷ | ذخیره آیتم‌ها | DocumentItem Model |
| ۸ | ثبت لاگ | DocumentObserver |
| ۹ | Fire رویداد | Event System |
| ۱۰ | بروزرسانی مانده | BalanceService |

### ۵.۲ جریان دریافت مانده

| مرحله | عملیات | مسئول |
|-------|--------|-------|
| ۱ | درخواست مانده | Facade/Service |
| ۲ | بررسی Cache | BalanceService |
| ۳a | اگر Cache معتبر | برگرداندن مقدار Cache |
| ۳b | اگر Cache نامعتبر | محاسبه از دیتابیس |
| ۴ | بروزرسانی Cache | BalanceService |
| ۵ | برگرداندن نتیجه | BalanceService |

### ۵.۳ جریان تولید گزارش

| مرحله | عملیات | مسئول |
|-------|--------|-------|
| ۱ | دریافت پارامترها | Controller/Facade |
| ۲ | اعتبارسنجی بازه | ReportService |
| ۳ | واکشی داده‌ها | Account/Document Models |
| ۴ | محاسبات | ReportService |
| ۵ | قالب‌بندی خروجی | ReportService |
| ۶ | برگرداندن نتیجه | Controller/Facade |

---

## ۶. ارتباط با پروژه

### ۶.۱ روش‌های اتصال

| روش | کاربرد | پیچیدگی |
|-----|--------|---------|
| HasAccount Trait | اتصال Model به حساب | ساده |
| Facade | استفاده مستقیم از API | ساده |
| Service Injection | تزریق سرویس در کلاس | متوسط |
| Event Listening | واکنش به رویدادهای پکیج | متوسط |

### ۶.۲ HasAccount Trait

برای مدل‌هایی که نیاز به حساب دارند.

| قابلیت | شرح |
|--------|-----|
| ساخت خودکار حساب | هنگام ایجاد Model |
| دسترسی به حساب | `$model->account` |
| دسترسی به مانده | `$model->balance()` |
| دسترسی به اسناد | `$model->documents()` |

### ۶.۳ Facade

استفاده سریع بدون نیاز به تزریق.

| متد | کاربرد |
|-----|--------|
| `Accounting::document()` | ساخت سند جدید |
| `Accounting::balance($account)` | دریافت مانده |
| `Accounting::report()` | تولید گزارش |
| `Accounting::account()` | مدیریت حساب |

### ۶.۴ Event Listening

پروژه می‌تواند به رویدادهای پکیج گوش دهد.

| رویداد | کاربرد در پروژه |
|--------|-----------------|
| DocumentPosted | ارسال اعلان، بروزرسانی داشبورد |
| DocumentVoided | ارسال هشدار، لاگ خاص |

---

## ۷. سیستم رویداد (Event System)

### ۷.۱ رویدادهای پکیج

| رویداد | Payload | زمان |
|--------|---------|------|
| DocumentCreated | Document | پس از ایجاد |
| DocumentPosted | Document | پس از ثبت قطعی |
| DocumentVoided | Document, reason | پس از ابطال |

### ۷.۲ نحوه استفاده در پروژه

پروژه می‌تواند Listener برای این رویدادها بسازد:

| رویداد | Listener پروژه | عملیات |
|--------|----------------|--------|
| DocumentPosted | SendInvoiceEmail | ارسال فاکتور ایمیلی |
| DocumentPosted | UpdateDashboard | بروزرسانی آمار |
| DocumentVoided | NotifyManager | اطلاع به مدیر |

---

## ۸. مدیریت خطا

### ۸.۱ انواع Exception

| Exception | علت | HTTP Code |
|-----------|-----|-----------|
| UnbalancedDocumentException | مجموع بدهکار ≠ مجموع بستانکار | 422 |
| ClosedFiscalYearException | سال مالی بسته است | 422 |
| InactiveAccountException | حساب غیرفعال است | 422 |
| InvalidDocumentStatusException | تغییر وضعیت نامعتبر | 422 |
| AccountNotFoundException | حساب یافت نشد | 404 |

### ۸.۲ مدیریت در پروژه

پروژه می‌تواند این Exception ها را در Handler مدیریت کند:

| Exception | پاسخ پیشنهادی |
|-----------|---------------|
| UnbalancedDocumentException | نمایش خطا به کاربر |
| ClosedFiscalYearException | راهنمایی به سال جدید |
| InactiveAccountException | پیشنهاد فعال‌سازی |

---

## ۹. استراتژی Cache

### ۹.۱ چرا Cache؟

محاسبه مانده از روی اسناد در مقیاس بزرگ کند است.

| تعداد تراکنش | زمان محاسبه بدون Cache | زمان با Cache |
|--------------|------------------------|---------------|
| ۱,۰۰۰ | ~۵۰ms | ~۱ms |
| ۱۰,۰۰۰ | ~۵۰۰ms | ~۱ms |
| ۱۰۰,۰۰۰ | ~۵s | ~۱ms |

### ۹.۲ استراتژی بروزرسانی

| روش | زمان اجرا | دقت |
|-----|-----------|-----|
| Immediate | پس از هر سند | ۱۰۰٪ |
| Delayed | با Job در صف | ~۹۹٪ |
| Scheduled | هر ساعت/روز | ~۹۵٪ |

💡 **توصیه:** از روش Immediate برای دقت بالا استفاده کنید.

### ۹.۳ محل ذخیره Cache

| محل | مزیت | معایب |
|-----|------|-------|
| فیلد در جدول accounts | ساده، Atomic | فضای بیشتر |
| Redis/Memcached | سریع‌تر | پیچیدگی بیشتر |

💡 **توصیه:** فیلد در جدول برای اکثر پروژه‌ها کافی است.

---

## ۱۰. Multi-tenancy

### ۱۰.۱ پشتیبانی

پکیج از Multi-tenancy پشتیبانی می‌کند.

| روش | پشتیبانی |
|-----|----------|
| Branch-based | ✅ بله |
| Database per tenant | ⚠️ نیاز به Config |
| Schema per tenant | ⚠️ نیاز به Config |

### ۱۰.۲ Branch-based Multi-tenancy

ساده‌ترین روش که در پکیج پیاده‌سازی شده:

| جنبه | شرح |
|------|-----|
| یک دیتابیس | همه شعب در یک DB |
| فیلتر با Branch | هر Query بر اساس branch_id |
| گزارش جداگانه | هر شعبه گزارش خودش |
| گزارش تجمیعی | امکان ترکیب همه شعب |

---

## ۱۱. قابلیت توسعه

### ۱۱.۱ افزودن نوع سند جدید

پروژه می‌تواند انواع سند جدید تعریف کند:

| مرحله | عملیات |
|-------|--------|
| ۱ | افزودن به Config |
| ۲ | ساخت Service مختص (اختیاری) |
| ۳ | استفاده از نوع جدید |

### ۱۱.۲ افزودن گزارش جدید

| مرحله | عملیات |
|-------|--------|
| ۱ | ساخت کلاس Report |
| ۲ | استفاده از ReportService |
| ۳ | قالب‌بندی خروجی |

### ۱۱.۳ Macroable

سرویس‌ها Macroable هستند و پروژه می‌تواند متد اضافه کند.

---

## ۱۲. نکات امنیتی

### ۱۲.۱ محافظت‌های داخلی

| محافظت | شرح |
|--------|-----|
| بالانس اجباری | سند نامتعادل ثبت نمی‌شود |
| سال مالی بسته | ثبت در دوره بسته ممنوع |
| حساب سیستمی | حذف حساب‌های پایه ممنوع |
| Audit Trail | ثبت تمام تغییرات |

### ۱۲.۲ مسئولیت پروژه

| مسئولیت | شرح |
|---------|-----|
| Authorization | چه کسی می‌تواند سند ثبت کند |
| Authentication | شناسایی کاربر |
| Input Validation | اعتبارسنجی ورودی‌های UI |

---

## ۱۳. خلاصه

| جنبه | رویکرد |
|------|--------|
| معماری | لایه‌ای (Layered) |
| الگوهای اصلی | Facade, Service, Observer, Builder |
| ارتباط با پروژه | Trait, Facade, Events |
| مدیریت خطا | Exception های سفارشی |
| Performance | Cache مانده در دیتابیس |
| توسعه‌پذیری | Config, Macroable, Events |

---

[→ ادامه: ساختار دیتابیس (04-database-schema.md)](04-database-schema.md)

[← بازگشت: مفاهیم حسابداری (02-concepts.md)](02-concepts.md)

[⌂ فهرست (00-index.md)](00-index.md)