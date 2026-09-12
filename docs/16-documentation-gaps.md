# وضعیت مستندات و شکاف‌های باقی‌مانده

این فایل **پیگیری تصمیم محصول** است، نه فهرست قابلیت‌های فعلی. رفتار جاری در `00-index`، `08-api-reference`، `06-configuration`، `09-reports` و `fiscal-year-lifecycle` است.

نسخه را از `composer.json` / `Accounting::version()` بخوانید؛ اینجا نسخهٔ جاری هاردکد نمی‌شود.

## ۱. مواردی که با کد هم‌خوان شدند

این‌ها قبلاً در مستندات قدیمی غلط یا مبهم بودند و دیگر نباید به‌عنوان شکاف زنده تکرار شوند:

- چند سال `active` مجاز است (`allow_multiple_active`، پیش‌فرض `true`)؛ `is_current` اشاره‌گر UI است.
- جدول `accounting_periods` وجود دارد و `create`/`post` به دورهٔ `open` وابسته‌اند.
- `LedgerQuery::costCenter()` و `costCenterStatementPaginated()` فیلتر مرکز هزینه دارند.
- `reports.per_page` در صفحه‌بندی گزارش‌ها **استفاده می‌شود**.
- `confirm()` افتتاحیه به‌طور پیش‌فرض بعد از فعالیت عملیاتی posted مجاز است.
- پس از `close()` سال جاری، اگر سال active دیگری بماند promote می‌شود.
- صورت سود و زیان و ترازنامه روی `LedgerQuery` هستند؛ صورت جریان O/I/F هنوز نیست (فقط `cashMovements`).
- گردش حساب، دفتر روزنامه، خلاصه روزانه، آمادگی بستن دوره و مقایسه دوره روی `LedgerReportFilters` هستند.
- AR/AP Aging بومی نیست؛ فقط قرارداد `AgingSourceProvider`.
- `void` و `reversal` دو رفتار متفاوت‌اند.
- `FiscalYearService::close()` سند اختتامیه نمی‌سازد؛ `ClosingService` سال را نمی‌بندد.
- چندشعبه ≠ چندشرکتی / tenant.

## ۲. کلیدهای config که enforce نمی‌شوند

باید بعداً implement، deprecate یا از config حذف شوند:

| کلید | وضعیت امروز |
|------|-------------|
| `accounting.enabled` | unused |
| `general.date_format` | unused / informational |
| `document.allowed_types` | unused — قرارداد |
| `document.workflow_enabled` | unused — enum وضعیت هست، سرویس workflow نیست |
| `fiscal_year.default_id` | unused |
| `balance.update_strategy` | unused — observer همیشه immediate |

جزئیات: [06-configuration.md](06-configuration.md).

`accounting.seed.branch_id` را seeder می‌خواند اما در فایل منتشرشدهٔ config نیست.

## ۳. قابلیت‌هایی که در پکیج نیستند

این‌ها را از روی مثال فروشگاهی یا نام حساب سیستمی استنتاج نکنید:

- ماژول صندوق / بانک / مشتری / کالا / انبار / مالیات / ارز / UI
- صورت جریان وجه نقد کامل (عملیاتی / سرمایه‌گذاری / تأمین مالی)
- AR/AP Aging بدون provider میزبان
- بازگشایی سال یا دورهٔ بسته
- اصلاح بین‌سال‌ها و برگشت جزئی سند
- تخصیص/تسهیم مرکز هزینه
- isolation چندشرکتی / multi-tenant
- authorization اپلیکیشن

## ۴. فایل‌های تاریخی (منبع حقیقت نیستند)

- `docs/14-implementation/*` — اسنپ‌شات قدیمی پیاده‌سازی
- `docs/reporting-implementation.md` — یادداشت طراحی ۱۳.۲.۰
- `docs/examples/shop/*` — سناریو مفهومی اپ میزبان
- بخش‌های طولانی `11-multi-language.md` که helper ساختگی دارند — جعبهٔ بالای همان فایل مبنا است

## ۵. پیشنهادهای بعدی (محصول، نه مستند)

1. تصمیم برای کلیدهای unused: enforce / deprecate / حذف
2. اگر workflow تایید لازم است، سرویس جدا در اپ میزبان یا هسته
3. صورت جریان O/I/F فقط وقتی متادیتای طبقه در دفتر وجود داشته باشد؛ شالوده فعلی `cashMovements()` است
