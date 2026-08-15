# مستندات پکیج حسابداری Laravel

## Accounting Package Documentation

---

## اطلاعات پکیج

| مشخصه | مقدار |
|-------|-------|
| نام پکیج | `your-vendor/laravel-accounting` |
| نسخه | 1.0.0 |
| حداقل نسخه PHP | 8.2 |
| حداقل نسخه Laravel | ^11.0 \| ^12.0 |
| لایسنس | MIT |
| آخرین بروزرسانی | خرداد ۱۴۰۴ |

---

## فهرست مستندات

### بخش اول: آشنایی

| # | فایل | عنوان | شرح |
|---|------|-------|-----|
| 01 | [overview.md](01-overview.md) | معرفی کلی | هدف، فلسفه طراحی، محدوده پکیج |
| 02 | [concepts.md](02-concepts.md) | مفاهیم حسابداری | حساب، سند، سال مالی، مرکز هزینه |
| 03 | [architecture.md](03-architecture.md) | معماری فنی | ساختار، لایه‌ها، Design Patterns |

### بخش دوم: دیتابیس

| # | فایل | عنوان | شرح |
|---|------|-------|-----|
| 04 | [database-schema.md](04-database-schema.md) | ساختار دیتابیس | جداول، روابط، ERD |

### بخش سوم: راه‌اندازی

| # | فایل | عنوان | شرح |
|---|------|-------|-----|
| 05 | [installation.md](05-installation.md) | نصب | نصب، Migration، Seeder |
| 06 | [configuration.md](06-configuration.md) | پیکربندی | تنظیمات و سفارشی‌سازی |
| 07 | [integration.md](07-integration.md) | یکپارچه‌سازی | اتصال به پروژه |

### بخش چهارم: استفاده

| # | فایل | عنوان | شرح |
|---|------|-------|-----|
| 08 | [api-reference.md](08-api-reference.md) | مرجع API | متدها و سرویس‌ها |
| 09 | [reports.md](09-reports.md) | گزارش‌ها | انواع گزارش‌های حسابداری |
| 09b | [fiscal-year-lifecycle.md](fiscal-year-lifecycle.md) | چرخه سال مالی | create / activate / close / posting control (۱۳.۳) |
| 10 | [multi-branch.md](10-multi-branch.md) | شعبه (branch_id) | استفاده از branch_id و تنظیمات شعبه |
| 11 | [multi-language.md](11-multi-language.md) | چندزبانگی | ترجمه و زبان‌ها |
| 12 | [security.md](12-security.md) | امنیت | دسترسی‌ها و Audit |
| 13 | [examples.md](13-examples.md) | مثال‌ها | سناریوهای کاربردی |

### بخش پنجم: پیاده‌سازی

| # | فایل | عنوان | شرح |
|---|------|-------|-----|
| 14a | [implementation/models.md](14-implementation/14a-models.md) | Models | کد مدل‌ها |
| 14b | [implementation/migrations.md](14-implementation/14b-migrations.md) | Migrations | کد Migration ها |
| 14c | [implementation/services.md](14-implementation/14c-services.md) | Services | کد سرویس‌ها |
| 14d | [implementation/traits.md](14-implementation/14d-traits.md) | Traits | کد Trait ها |
| 14e | [implementation/enums.md](14-implementation/14e-enums.md) | Enums | کد Enum ها |
| 14f | [implementation/events-observers.md](14-implementation/14f-events-observers.md) | Events & Observers | کد رویدادها |

### بخش ششم: ضمائم

| # | فایل | عنوان | شرح |
|---|------|-------|-----|
| 15 | [appendix.md](15-appendix.md) | ضمائم | واژه‌نامه، FAQ، Changelog |

---

## نقشه کلی سیستم

### لایه پروژه (مصرف‌کننده)

پروژه شما شامل موجودیت‌هایی مثل User، Product، Bank و Cashier است. هر کدام از این موجودیت‌ها با استفاده از `HasAccount` Trait به یک حساب در سیستم حسابداری متصل می‌شوند.

### لایه پکیج حسابداری

پکیج از سه بخش اصلی تشکیل شده:

**سرویس‌ها:** AccountService، DocumentService، ReportService و سایر سرویس‌ها که منطق تجاری را پیاده‌سازی می‌کنند.

**مدل‌ها:** Account، Document، FiscalYear، CostCenter، DocumentLog و در صورت تنظیم مدل شعبه در config، Branch (اختیاری).

**دیتابیس:** پکیج فقط جداول fiscal_years، accounts، cost_centers، documents، document_items و document_logs را ایجاد می‌کند. برای شعبه فقط فیلد **branch_id** در accounts و documents استفاده می‌شود؛ جدول/مدل Branch در اختیار اپلیکیشن است.

---

## مسیر یادگیری پیشنهادی

### برای تصمیم‌گیران و مدیران پروژه

| مرحله | فایل | هدف |
|-------|------|-----|
| ۱ | 01-overview.md | آشنایی با هدف و فلسفه پکیج |
| ۲ | 02-concepts.md | درک مفاهیم حسابداری |
| ۳ | 13-examples.md | دیدن مثال‌های کاربردی |

### برای توسعه‌دهندگان

| مرحله | فایل | هدف |
|-------|------|-----|
| ۱ | 01-overview.md | آشنایی کلی |
| ۲ | 02-concepts.md | درک مفاهیم |
| ۳ | 03-architecture.md | درک معماری |
| ۴ | 04-database-schema.md | شناخت جداول |
| ۵ | 05-installation.md | نصب پکیج |
| ۶ | 06-configuration.md | پیکربندی |
| ۷ | 07-integration.md | یکپارچه‌سازی |
| ۸ | 08-api-reference.md | استفاده از API |
| ۹ | 14-implementation | مطالعه کد |

### برای حسابداران و کاربران نهایی

| مرحله | فایل | هدف |
|-------|------|-----|
| ۱ | 01-overview.md | آشنایی کلی |
| ۲ | 02-concepts.md | درک مفاهیم حسابداری |
| ۳ | 09-reports.md | آشنایی با گزارش‌ها |
| ۴ | 13-examples.md | مثال‌های کاربردی |

---

## پیش‌نیازها

### پیش‌نیازهای سیستم

| مورد | حداقل نسخه |
|------|------------|
| PHP | 8.2 |
| Laravel | ^11.0 \| ^12.0 |
| MySQL | 8.0 |
| PostgreSQL | 13 (جایگزین) |

### پیش‌نیازهای دانشی

| سطح | مفاهیم |
|-----|--------|
| ضروری | Laravel (Models, Migrations, Services) |
| ضروری | مفاهیم پایه حسابداری (بدهکار/بستانکار) |
| مفید | Design Patterns (Repository, Service) |
| مفید | آشنایی با استانداردهای حسابداری ایران |

---

## ساختار پوشه‌های پکیج

### پوشه اصلی src

| پوشه | محتویات |
|------|---------|
| Models | Account، Document، DocumentItem، FiscalYear، Branch، CostCenter، DocumentLog |
| Services | AccountService، DocumentService، BalanceService، ReportService، FiscalYearService |
| Traits | HasAccount |
| Enums | AccountType، AccountNature، DocumentStatus، FiscalYearStatus |
| Events | DocumentCreated، DocumentPosted، DocumentVoided |
| Observers | DocumentObserver |
| Exceptions | UnbalancedDocumentException، ClosedFiscalYearException، InactiveAccountException |
| Facades | Accounting |

### پوشه config

| فایل | شرح |
|------|-----|
| accounting.php | تنظیمات اصلی پکیج |

### پوشه database

| پوشه | محتویات |
|------|---------|
| migrations | Migration های جداول |
| seeders | DefaultAccountsSeeder |

### پوشه lang

| پوشه | محتویات |
|------|---------|
| en | فایل‌های زبان انگلیسی |
| fa | فایل‌های زبان فارسی |

---

## قراردادهای نام‌گذاری

### در کد

| نوع | قرارداد | مثال |
|-----|---------|------|
| Model | PascalCase، مفرد | `Account`, `Document` |
| Table | snake_case، جمع | `accounts`, `documents` |
| Migration | snake_case با تاریخ | `2024_01_01_000001_create_accounts_table` |
| Service | PascalCase + Service | `AccountService` |
| Trait | PascalCase + Has/Is/Can | `HasAccount` |
| Enum | PascalCase | `AccountType` |
| Event | PascalCase + Past Tense | `DocumentCreated` |
| Config Key | snake_case | `default_fiscal_year` |

### در مستندات

| علامت | معنی |
|-------|------|
| ✅ | پشتیبانی می‌شود |
| ❌ | پشتیبانی نمی‌شود |
| ⚠️ | نیاز به توجه خاص |
| 💡 | نکته مهم |
| 📌 | یادآوری |

---

## نسخه‌های مستندات

| نسخه | تاریخ | تغییرات |
|------|-------|---------|
| 1.0.0 | خرداد ۱۴۰۴ | انتشار اولیه |

---

## پشتیبانی و ارتباط

| مورد | آدرس |
|------|------|
| گزارش باگ | GitHub Issues |
| درخواست ویژگی | GitHub Discussions |
| مشارکت | CONTRIBUTING.md |

---

## لایسنس

این پکیج تحت لایسنس MIT منتشر شده است.

---

> **شروع سریع:** برای شروع فوری، به [05-installation.md](05-installation.md) مراجعه کنید.

---

[→ ادامه: معرفی کلی (01-overview.md)](01-overview.md)