# 05-installation.md

# نصب و راه‌اندازی

## Installation

---

## مقدمه

این بخش نحوه نصب و راه‌اندازی اولیه پکیج حسابداری را شرح می‌دهد.

---

## ۱. پیش‌نیازها

### ۱.۱ پیش‌نیازهای سیستم

| مورد | حداقل نسخه | توصیه شده |
|------|------------|-----------|
| PHP | 8.2 | 8.3 |
| Laravel | ^11.0 \| ^12.0 | 12.x |
| MySQL | 8.0 | 8.0+ |
| PostgreSQL (جایگزین) | 13 | 15+ |

### ۱.۲ افزونه‌های PHP مورد نیاز

| افزونه | کاربرد |
|--------|--------|
| BCMath | محاسبات دقیق مالی |
| JSON | ذخیره فیلدهای meta |
| PDO | اتصال دیتابیس |

### ۱.۳ بررسی پیش‌نیازها

برای بررسی نسخه PHP:

```bash
php -v
```

برای بررسی افزونه‌ها:

```bash
php -m | grep -E "bcmath|json|pdo"
```

---

## ۲. نصب پکیج

### ۲.۱ نصب با Composer

```bash
composer require your-vendor/laravel-accounting
```

### ۲.۲ بررسی نصب

پس از نصب، پکیج باید در لیست پکیج‌ها دیده شود:

```bash
composer show your-vendor/laravel-accounting
```

---

## ۳. انتشار فایل‌ها

### ۳.۱ انتشار همه فایل‌ها

برای انتشار تمام فایل‌های قابل انتشار:

```bash
php artisan vendor:publish --provider="YourVendor\Accounting\AccountingServiceProvider"
```

### ۳.۲ انتشار انتخابی

**فقط Config:**

```bash
php artisan vendor:publish --tag="accounting-config"
```

**Migration ها:** مایگریشن‌های پکیج به‌صورت خودکار بارگذاری می‌شوند؛ نیازی به publish جداگانه نیست. پس از نصب، `php artisan migrate` را اجرا کنید.

**فقط فایل‌های زبان:**

```bash
php artisan vendor:publish --tag="accounting-lang"
```

**فقط Seeder ها:**

```bash
php artisan vendor:publish --tag="accounting-seeders"
```

### ۳.۳ لیست فایل‌های منتشر شده

| تگ | مسیر مقصد | شرح |
|----|-----------|-----|
| accounting-config | config/accounting.php | تنظیمات پکیج |
| accounting-lang | lang/vendor/accounting/ | فایل‌های زبان |

مایگریشن‌های پکیج از داخل پکیج بارگذاری می‌شوند و با `php artisan migrate` اجرا می‌گردند. جدول `branches` توسط پکیج ایجاد **نمی‌شود**؛ در صورت نیاز آن را در اپلیکیشن ایجاد کنید یا فقط از `config('accounting.branch.default_id')` استفاده کنید.

---

## ۴. اجرای Migration ها

### ۴.۱ اجرای Migration

```bash
php artisan migrate
```

### ۴.۲ بررسی جداول ایجاد شده

پس از اجرای Migration، پکیج فقط جداول زیر را ایجاد می‌کند (با پیشوند از config، مثلاً `acc_`):

| جدول | شرح |
|------|-----|
| fiscal_years | سال‌های مالی |
| accounts | حساب‌های مالی |
| cost_centers | مراکز هزینه |
| documents | اسناد حسابداری |
| document_items | آیتم‌های اسناد |
| document_logs | لاگ تغییرات |

جدول `branches` توسط پکیج ساخته **نمی‌شود**؛ در accounts و documents فقط فیلد `branch_id` (nullable) وجود دارد. برای شعبه از `config('accounting.branch.default_id')` استفاده کنید یا جدول/مدل Branch را در اپلیکیشن تعریف کنید.

### ۴.۳ بررسی با Artisan

```bash
php artisan migrate:status
```

خروجی باید شامل Migration های پکیج باشد:

| Migration | Batch |
|-----------|-------|
| 2024_01_01_000002_create_fiscal_years_table | 1 |
| 2024_01_01_000003_create_accounts_table | 1 |
| 2024_01_01_000004_create_cost_centers_table | 1 |
| 2024_01_01_000005_create_documents_table | 1 |
| 2024_01_01_000006_create_document_items_table | 1 |
| 2024_01_01_000007_create_document_logs_table | 1 |

---

## ۵. اجرای Seeder

### ۵.۱ Seeder پیش‌فرض

پکیج یک Seeder برای ایجاد حساب‌های پایه ارائه می‌دهد:

```bash
php artisan db:seed --class="Database\Seeders\DefaultAccountsSeeder"
```

### ۵.۲ محتوای Seeder پیش‌فرض

**شعبه پیش‌فرض:**

| کد | عنوان | پیش‌فرض |
|----|-------|---------|
| HQ | دفتر مرکزی | ✅ |

**سال مالی:**

| عنوان | شروع | پایان | وضعیت |
|-------|------|-------|-------|
| سال مالی ۱۴۰۳ | 1403-01-01 | 1403-12-29 | active |

**حساب‌های پایه (سطح گروه):**

| کد | عنوان | نوع |
|----|-------|-----|
| 1 | دارایی‌ها | asset |
| 2 | بدهی‌ها | liability |
| 3 | سرمایه | equity |
| 4 | درآمدها | income |
| 5 | هزینه‌ها | expense |

**حساب‌های پایه (سطح کل):**

| کد | عنوان | والد |
|----|-------|------|
| 11 | دارایی جاری | 1 |
| 12 | دارایی غیرجاری | 1 |
| 21 | بدهی جاری | 2 |
| 22 | بدهی غیرجاری | 2 |
| 31 | سرمایه | 3 |
| 41 | درآمد عملیاتی | 4 |
| 42 | درآمد غیرعملیاتی | 4 |
| 51 | هزینه عملیاتی | 5 |
| 52 | هزینه غیرعملیاتی | 5 |

**حساب‌های پایه (سطح معین):**

| کد | عنوان | والد |
|----|-------|------|
| 1101 | موجودی نقد | 11 |
| 1102 | بانک‌ها | 11 |
| 1103 | حساب‌های دریافتنی | 11 |
| 1104 | موجودی کالا | 11 |
| 2101 | حساب‌های پرداختنی | 21 |
| 4101 | درآمد فروش کالا | 41 |
| 4102 | درآمد خدمات | 41 |
| 5101 | بهای تمام شده کالا | 51 |
| 5102 | هزینه حقوق | 51 |
| 5103 | هزینه اجاره | 51 |

**حساب‌های پایه (سطح تفصیلی):**

| کد | عنوان | والد |
|----|-------|------|
| 110101 | صندوق | 1101 |
| 110201 | بانک اصلی | 1102 |
| 410101 | فروش کالا | 4101 |
| 410201 | درآمد خدمات | 4102 |
| 510101 | بهای تمام شده | 5101 |

### ۵.۳ سفارشی‌سازی Seeder

اگر می‌خواهید Seeder را سفارشی کنید:

۱. ابتدا Seeder را Publish کنید:

```bash
php artisan vendor:publish --tag="accounting-seeders"
```

۲. فایل `database/seeders/DefaultAccountsSeeder.php` را ویرایش کنید.

۳. Seeder را اجرا کنید:

```bash
php artisan db:seed --class="DefaultAccountsSeeder"
```

---

## ۶. پیکربندی اولیه

### ۶.۱ تنظیم User Model

در فایل `config/accounting.php`:

```php
'user' => [
    'model' => App\Models\User::class,
    'table' => 'users',
    'foreign_key' => 'user_id',
],
```

### ۶.۲ تنظیم سال مالی پیش‌فرض

```php
'fiscal_year' => [
    'auto_detect' => true,
    'default_id' => null,
],
```

### ۶.۳ تنظیم شعبه پیش‌فرض

```php
'branch' => [
    'enabled' => true,
    'default_id' => 1,
],
```

---

## ۷. ثبت Service Provider

### ۷.۱ ثبت خودکار (Auto-Discovery)

در Laravel 12، پکیج به صورت خودکار شناسایی می‌شود و نیازی به ثبت دستی نیست.

### ۷.۲ ثبت دستی (اختیاری)

اگر Auto-Discovery غیرفعال است، در `bootstrap/providers.php`:

```php
return [
    // سایر Provider ها
    YourVendor\Accounting\AccountingServiceProvider::class,
];
```

---

## ۸. ثبت Facade

### ۸.۱ استفاده خودکار

Facade به صورت خودکار ثبت می‌شود و قابل استفاده است:

```php
use YourVendor\Accounting\Facades\Accounting;

// یا
use Accounting;
```

### ۸.۲ ثبت Alias (اختیاری)

در `config/app.php`:

```php
'aliases' => [
    // سایر Alias ها
    'Accounting' => YourVendor\Accounting\Facades\Accounting::class,
],
```

---

## ۹. بررسی صحت نصب

### ۹.۱ تست Facade

یک فایل تست ساده اجرا کنید:

```bash
php artisan tinker
```

```php
use YourVendor\Accounting\Facades\Accounting;

// بررسی دسترسی به Facade
Accounting::version();

// بررسی سال مالی جاری
Accounting::currentFiscalYear();

// بررسی تعداد حساب‌ها
Accounting::accountsCount();
```

### ۹.۲ تست جداول

```php
use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Models\FiscalYear;

// تعداد حساب‌ها
Account::count();

// سال مالی فعال
FiscalYear::where('status', 'active')->first();
```

### ۹.۳ خروجی مورد انتظار

| تست | خروجی مورد انتظار |
|-----|-------------------|
| Accounting::version() | '1.0.0' |
| Accounting::currentFiscalYear() | FiscalYear object |
| Account::count() | عددی بزرگ‌تر از ۰ |

---

## ۱۰. دستورات Artisan

### ۱۰.۱ لیست دستورات

پکیج چند دستور Artisan ارائه می‌دهد:

| دستور | شرح |
|-------|-----|
| accounting:install | نصب کامل پکیج |
| accounting:seed | اجرای Seeder پیش‌فرض |
| accounting:balance:refresh | بروزرسانی Cache مانده‌ها |
| accounting:fiscal-year:create | ایجاد سال مالی جدید |
| accounting:status | بررسی وضعیت پکیج |

### ۱۰.۲ نصب با یک دستور

به جای اجرای دستی مراحل، می‌توانید از دستور زیر استفاده کنید:

```bash
php artisan accounting:install
```

این دستور موارد زیر را انجام می‌دهد:
- انتشار Config
- انتشار Migration ها
- اجرای Migration ها
- اجرای Seeder پیش‌فرض

### ۱۰.۳ بررسی وضعیت

```bash
php artisan accounting:status
```

خروجی:

```
+------------------+------------------+
| Item             | Status           |
+------------------+------------------+
| Package Version  | 1.0.0            |
| Config Published | ✅ Yes           |
| Migrations Run   | ✅ Yes (7/7)     |
| Active Fiscal    | 1403             |
| Accounts Count   | 25               |
| Documents Count  | 0                |
+------------------+------------------+
```

---

## ۱۱. عیب‌یابی

### ۱۱.۱ خطاهای رایج

**خطا: Class not found**

| علت احتمالی | راه‌حل |
|-------------|--------|
| Composer dump نشده | `composer dump-autoload` |
| پکیج نصب نشده | `composer require your-vendor/laravel-accounting` |
| Cache قدیمی | `php artisan cache:clear` |

**خطا: Table not found**

| علت احتمالی | راه‌حل |
|-------------|--------|
| Migration اجرا نشده | `php artisan migrate` |
| Migration منتشر نشده | `php artisan vendor:publish --tag="accounting-migrations"` |

**خطا: Config not found**

| علت احتمالی | راه‌حل |
|-------------|--------|
| Config منتشر نشده | `php artisan vendor:publish --tag="accounting-config"` |
| Cache قدیمی | `php artisan config:clear` |

### ۱۱.۲ پاکسازی Cache

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

یا همه با هم:

```bash
php artisan optimize:clear
```

### ۱۱.۳ بازسازی Autoload

```bash
composer dump-autoload -o
```

---

## ۱۲. بروزرسانی پکیج

### ۱۲.۱ بروزرسانی با Composer

```bash
composer update your-vendor/laravel-accounting
```

### ۱۲.۲ اجرای Migration های جدید

پس از بروزرسانی:

```bash
php artisan migrate
```

### ۱۲.۳ بررسی تغییرات Config

اگر Config تغییر کرده، می‌توانید فایل جدید را ببینید:

```bash
php artisan vendor:publish --tag="accounting-config" --force
```

⚠️ **هشدار:** استفاده از `--force` فایل قبلی را بازنویسی می‌کند.

---

## ۱۳. حذف پکیج

### ۱۳.۱ Rollback کردن Migration ها

⚠️ **هشدار:** این کار تمام داده‌های حسابداری را پاک می‌کند.

```bash
php artisan migrate:rollback --step=7
```

### ۱۳.۲ حذف پکیج

```bash
composer remove your-vendor/laravel-accounting
```

### ۱۳.۳ حذف فایل‌های منتشر شده

```bash
rm config/accounting.php
rm -rf lang/vendor/accounting
rm database/seeders/DefaultAccountsSeeder.php
```

---

## ۱۴. چک‌لیست نصب

| مرحله | دستور | وضعیت |
|-------|-------|-------|
| ۱ | نصب پکیج | `composer require` |
| ۲ | انتشار Config | `vendor:publish --tag=accounting-config` |
| ۳ | تنظیم Config | ویرایش `config/accounting.php` |
| ۴ | اجرای Migration | `php artisan migrate` |
| ۵ | اجرای Seeder | `php artisan db:seed` |
| ۶ | بررسی نصب | `php artisan accounting:status` |

---

## ۱۵. گام بعدی

پس از نصب موفق:

۱. فایل `config/accounting.php` را بررسی و تنظیم کنید
۲. Trait را به مدل‌های مورد نیاز اضافه کنید
۳. اولین سند را ثبت کنید

برای جزئیات بیشتر، به بخش [پیکربندی (06-configuration.md)](06-configuration.md) مراجعه کنید.

---

[→ ادامه: پیکربندی (06-configuration.md)](06-configuration.md)

[← بازگشت: ساختار دیتابیس (04-database-schema.md)](04-database-schema.md)

[⌂ فهرست (00-index.md)](00-index.md)