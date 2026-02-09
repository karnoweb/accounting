# 03-architecture.md

# معماری فنی

## Architecture

---

## مقدمه

این بخش معماری فنی پکیج حسابداری را شرح می‌دهد. درک این معماری به توسعه‌دهندگان کمک می‌کند تا پکیج را به درستی در پروژه خود یکپارچه کنند.

---

## ۱. اصول معماری

### ۱.۱ جداسازی نگرانی‌ها (Separation of Concerns)

پکیج به گونه‌ای طراحی شده که هیچ دانشی از پروژه مصرف‌کننده ندارد.

| لایه | مسئولیت | مثال |
|------|---------|------|
| پکیج حسابداری | ثبت اسناد، محاسبه مانده، گزارش‌گیری | Document, Account, Balance |
| پروژه مصرف‌کننده | منطق تجاری، UI، احراز هویت | Order, Invoice, User |

### ۱.۲ وابستگی یک‌طرفه

پروژه به پکیج وابسته است، نه برعکس.

| جهت | از | به | مجاز |
|-----|-----|-----|------|
| ✅ | پروژه | پکیج | بله |
| ❌ | پکیج | پروژه | خیر |

### ۱.۳ قابلیت پیکربندی

رفتار پکیج از طریق Config قابل تنظیم است بدون نیاز به تغییر کد پکیج.

---

## ۲. لایه‌های سیستم

### ۲.۱ نمای کلی لایه‌ها

| لایه | نام | مسئولیت | اجزا |
|------|-----|---------|------|
| ۱ | Presentation | ارتباط با بیرون | Facade, Controllers |
| ۲ | Application | منطق کاربردی | Services |
| ۳ | Domain | قوانین تجاری | Models, Enums, Events |
| ۴ | Infrastructure | دسترسی به داده | Migrations, Observers |

### ۲.۲ جزئیات هر لایه

**لایه Presentation:**

| جزء | مسئولیت |
|-----|---------|
| Accounting Facade | نقطه ورود ساده برای استفاده |
| Controllers (اختیاری) | API endpoints برای REST |

**لایه Application:**

| جزء | مسئولیت |
|-----|---------|
| AccountService | مدیریت حساب‌ها |
| DocumentService | ثبت و مدیریت اسناد |
| BalanceService | محاسبه و Cache مانده |
| ReportService | تولید گزارش‌ها |
| FiscalYearService | مدیریت سال مالی |

**لایه Domain:**

| جزء | مسئولیت |
|-----|---------|
| Models | نگهداری داده و روابط |
| Enums | تعریف مقادیر ثابت |
| Events | اعلام رویدادها |
| Exceptions | خطاهای خاص Domain |

**لایه Infrastructure:**

| جزء | مسئولیت |
|-----|---------|
| Migrations | ساختار دیتابیس |
| Observers | واکنش به تغییرات Model |
| ServiceProvider | ثبت سرویس‌ها در Laravel |

---

## ۳. Design Patterns

### ۳.۱ الگوهای استفاده شده

| الگو | کاربرد در پکیج | مثال |
|------|----------------|------|
| Facade | ساده‌سازی API | `Accounting::document()` |
| Service Layer | جداسازی منطق از Controller | `DocumentService` |
| Repository (ضمنی) | دسترسی به داده از طریق Model | `Account::query()` |
| Observer | واکنش به رویدادهای Model | `DocumentObserver` |
| Builder | ساخت اشیاء پیچیده | `DocumentBuilder` |
| Strategy | الگوریتم‌های قابل تعویض | `BalanceCalculator` |
| Factory | ساخت حساب‌ها | `AccountFactory` |

### ۳.۲ الگوی Facade

نقطه ورود ساده برای استفاده از پکیج.

**بدون Facade:**
```php
$service = app(DocumentService::class);
$document = $service->create($data);
$service->post($document);
```

**با Facade:**
```php
Accounting::document()->create($data)->post();
```

### ۳.۳ الگوی Service Layer

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