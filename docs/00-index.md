# مستندات مرجع پکیج `karnoweb/laravel-accounting`

این پوشه مرجع رسمی رفتار پکیج است. هرجا بین متن و کد اختلاف باشد، **کد مبنا** است.

## مشخصات فعلی پکیج

| مورد | مقدار |
|------|-------|
| نام پکیج | `karnoweb/laravel-accounting` |
| نسخه فعلی | `13.5.0` |
| PHP | `^8.3` |
| Laravel | `^13.0` |
| الگوی حسابداری | Double-Entry |

## این پکیج چه مسئله‌ای را حل می‌کند؟

این پکیج یک هسته حسابداری دوبل برای لاراول فراهم می‌کند که این مسئولیت‌ها را داخل خود نگه می‌دارد:

- تعریف و نگهداری **Chart of Accounts**
- ایجاد و ثبت قطعی **Document** و **DocumentItem**
- کنترل چرخه **Fiscal Year**
- محاسبه مانده و گردش حساب
- تولید گزارش‌های مبتنی بر دفتر ثبت‌شده
- مدیریت افتتاحیه، بستن سود و زیان، و برگشت عملیاتی

این پکیج **جایگزین ERP کامل** نیست و موجودیت‌های تجاری مثل مشتری، فاکتور فروش، کالا، مالیات، انبار، بانک و صندوق را به‌صورت مستقل مدل نمی‌کند. اگر پروژه مصرف‌کننده چنین مفاهیمی داشته باشد، باید آن‌ها را از طریق حساب‌ها و اسناد به این هسته متصل کند.

## دو محور اصلی مستندات

### A) راهنمای استفاده از پکیج

1. [01-overview.md](01-overview.md) — هدف، دامنه و قراردادهای اصلی
2. [05-installation.md](05-installation.md) — نصب و راه‌اندازی
3. [06-configuration.md](06-configuration.md) — پیکربندی واقعی
4. [07-integration.md](07-integration.md) — اتصال به پروژه مصرف‌کننده
5. [08-api-reference.md](08-api-reference.md) — API واقعی سرویس‌ها و facade
6. [usage.md](usage.md) — شروع سریع و الگوهای رایج استفاده

### B) دانشنامه ماژول‌ها و مفاهیم حسابداری

1. [02-concepts.md](02-concepts.md) — مفاهیم حسابداری پیاده‌سازی‌شده
2. [03-architecture.md](03-architecture.md) — معماری فنی و مرزهای دامنه
3. [04-database-schema.md](04-database-schema.md) — جداول، روابط و محدودیت‌ها
4. [09-reports.md](09-reports.md) — منبع داده و منطق گزارش‌ها
5. [fiscal-year-lifecycle.md](fiscal-year-lifecycle.md) — چرخه کامل سال مالی
6. [12-security.md](12-security.md) — Audit، تغییرناپذیری و محدودیت‌ها
7. [15-appendix.md](15-appendix.md) — واژه‌نامه، تفاوت مفاهیم مشابه و FAQ
8. [16-documentation-gaps.md](16-documentation-gaps.md) — شکاف‌های مستندسازی و موارد نیازمند تصمیم انسانی

## نقشه واقعی ماژول‌ها

### مدل‌ها

- `Account`
- `FiscalYear`
- `Document`
- `DocumentItem`
- `CostCenter`
- `DocumentLog`
- `DocumentNumberSequence`
- `Branch` (رابطه اختیاری به مدل شعبهٔ اپلیکیشن)

### سرویس‌ها

- `AccountService`
- `DocumentService`
- `DocumentBuilder`
- `BalanceService`
- `ReportService`
- `FiscalYearService`
- `PostingService`
- `OpeningService`
- `ClosingService`
- `ReversalService`

### گزارش‌ها و DTOها

- `LedgerQuery`
- `HierarchyRollup`
- `TrialBalanceReport`
- `TrialBalanceRow`
- `GeneralLedgerReport`
- `AccountLedger`
- `LedgerLine`

## نکات مهم برای خواندن این مستندات

- `branch_id` در این پکیج فقط یک **کلید تفکیک** در سطح حساب و سند است. جدول `branches` را خود پکیج ایجاد نمی‌کند.
- `reference` فقط یک فیلد متنی روی سند است. در کد هیچ قاعده یکتایی یا semantics خاصی برای آن enforce نشده است.
- `source_type` و `source_id` فقط لینک polymorphic به منبع تجاری سند هستند و unique نیستند.
- گزارش‌ها فقط از **دفتر ثبت‌شده** می‌خوانند، نه از `cached_balance`.
- Cost Center در این نسخه فقط روی `DocumentItem` ذخیره می‌شود و هنوز در گزارش‌های هسته‌ای فیلتر مستقلی ندارد.

## چه چیزهایی عمداً در این پکیج نیست؟

- جدول یا ماژول مستقل برای صندوق
- جدول یا ماژول مستقل برای بانک
- ماژول انتقال وجه بانکی
- زیرسیستم اشخاص، مشتریان، تأمین‌کنندگان
- ماژول کالا، خدمت، انبار یا مالیات
- دوره مالی ماهانه یا `AccountingPeriod`
- بازگشایی سال مالی بسته
- اصلاح بین‌دوره‌ای سال بسته
- برگشت جزئی سند

## وضعیت مثال‌های موجود

پوشه `docs/examples/shop/` سناریوهای آموزشی دامنهٔ فروشگاهی را نگه می‌دارد. این سناریوها **قرارداد هسته پکیج نیستند** و بخشی از آن‌ها به منطق اپلیکیشن مصرف‌کننده وابسته‌اند. برای تشخیص قابلیت‌های واقعی پکیج، این مثال‌ها باید همراه با کد هسته خوانده شوند، نه به‌عنوان Source of Truth مستقل.

## مسیر پیشنهادی مطالعه

1. [01-overview.md](01-overview.md)
2. [02-concepts.md](02-concepts.md)
3. [03-architecture.md](03-architecture.md)
4. [04-database-schema.md](04-database-schema.md)
5. [usage.md](usage.md)
6. [09-reports.md](09-reports.md)
7. [16-documentation-gaps.md](16-documentation-gaps.md)