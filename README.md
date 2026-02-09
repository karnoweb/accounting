# Laravel Accounting

پکیج حسابداری دوطرفه (Double-Entry) برای لاراول با ثبت خودکار اسناد، سال مالی، شعبه، مرکز هزینه و گزارش تراز آزمایشی.

- **PHP:** ^8.2  
- **Laravel:** ^11.0 | ^12.0  

---

## نصب

### از طریق Composer (پروژه جدا)

```bash
composer require karnoweb/laravel-accounting
```

### به‌صورت پکیج داخلی (مونورپو)

در `composer.json` اپلیکیشن لاراول:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/laravel-accounting"
        }
    ],
    "require": {
        "karnoweb/laravel-accounting": "@dev"
    }
}
```

سپس:

```bash
composer update karnoweb/laravel-accounting
```

سرویس‌پرایدر و فاسیاد به‌صورت خودکار ثبت می‌شوند (Laravel package discovery).

---

## پابلیش (Publish)

### کانفیگ

```bash
php artisan vendor:publish --tag=accounting-config
```

فایل `config/accounting.php` در پروژه کپی می‌شود.

### مایگریشن‌ها

```bash
php artisan vendor:publish --tag=accounting-migrations
php artisan migrate
```

جداول: `branches`, `fiscal_years`, `accounts`, `cost_centers`, `documents`, `document_items`, `document_logs`.

### ترجمه‌ها (زبان)

```bash
php artisan vendor:publish --tag=accounting-lang
```

فایل‌های زبان در `lang/vendor/accounting` قرار می‌گیرند (انگلیسی و فارسی).

### سیدر پیش‌فرض حساب‌ها

سیدر از داخل پکیج فراخوانی می‌شود؛ نیازی به publish کردن فایل سیدر نیست. حساب‌های اختصاصی از `config/accounting.php` → `account.custom_seed` خوانده می‌شوند.

در `DatabaseSeeder` یا سیدر دلخواه:

```php
$this->call(\Karnoweb\Accounting\Database\Seeders\DefaultAccountsSeeder::class);
```

یا برای یک شعبه مشخص: `DefaultAccountsSeeder::syncForBranch($branchId);`

### حساب‌های اضافی (کاربر / پروژه)

- **در سید:** در `config/accounting.php` آرایهٔ `account.custom_seed` را پر کنید تا همراه پیش‌فرض‌ها سینک شوند. هر عنصر مثل تعریف پیش‌فرض: `code`, `title`, `level`, `type` (مقدار enum مثل `asset`)، و `parent_code` یا `parent_id`. مثال:

```php
'custom_seed' => [
    ['code' => '110102', 'title' => 'صندوق فروشگاه', 'level' => 3, 'type' => 'asset', 'parent_code' => '1101'],
    ['code' => '110202', 'title' => 'بانک دوم', 'level' => 3, 'type' => 'asset', 'parent_code' => '1102'],
],
```

- **در زمان اجرا:** کاربر می‌تواند حساب جدید با `Accounting::account()->create([...])` اضافه کند (کد یکتا، والد با `parent_id` یا `parent_code`).

### پابلیش همه دارایی‌های پکیج

```bash
php artisan vendor:publish --provider="Karnoweb\Accounting\AccountingServiceProvider"
```

---

## تنظیمات اولیه

1. بعد از مایگریشن، حداقل یک **شعبه** و یک **سال مالی** با وضعیت فعال داشته باشید (مثلاً با `DefaultAccountsSeeder`).
2. در `config/accounting.php` در صورت نیاز مقادیر زیر را تنظیم کنید:
   - `accounting.general.prefix` — پیشوند جداول حسابداری (پیش‌فرض: `acc_`؛ جداول: `acc_branches`, `acc_accounts`, …)
   - `accounting.user.model` — مدل کاربر (برای `created_by`, `posted_by`)
   - `accounting.branch.default_id` — شعبه پیش‌فرض
   - `accounting.account.custom_seed` — تعریف حساب‌های اضافی که همراه سیدر پیش‌فرض سینک می‌شوند
   - `accounting.account.system_accounts` — کد حساب‌های سیستمی (صندوق، بانک، دریافتنی، پرداختنی و …)
   - `accounting.document.allowed_types` — انواع مجاز سند

---

## مستندات استفاده

**[راهنمای استفاده (Usage)](docs/usage.md)** شامل:

- فاسیاد و نقطه ورود
- ثبت سند (DocumentBuilder، save/post، شعبه، سال مالی، مرکز هزینه)
- حساب‌های سیستمی و مدیریت حساب‌ها
- تراز و گزارش تراز آزمایشی
- سال مالی و شعبه
- تریت `HasAccount` برای مدل‌های دارای حساب
- رویدادها و استثناها

** راهنمای کامل به زبان فارسی **

---

**[راهنمای پکیج (کامل)](docs/00-index.md)** شامل تمام مستندات پکیج به زبان فارسی:

**[مثال‌های کاربردی](docs/examples/shop/00-index.md)** شامل مثال‌های کاربردی برای سناریوهای فروشگاهی


## پابلیش پکیج (برای انتشار روی Packagist)

1. در `packages/laravel-accounting` نسخه را در `composer.json` به‌روز کنید (`version`).
2. در صورت نیاز تگ بزنید و به رپازیتوری (مثلاً GitHub) push کنید.
3. پکیج را در [Packagist](https://packagist.org) با آدرس رپو ثبت کنید تا با `composer require karnoweb/laravel-accounting` قابل نصب باشد.

---

## لایسنس

MIT
