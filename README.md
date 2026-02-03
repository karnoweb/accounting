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

### سیدرها

```bash
php artisan vendor:publish --tag=accounting-seeders
```

سیدر پیش‌فرض حساب‌ها و ساختار اولیه در `database/seeders` کپی می‌شود. در `DatabaseSeeder` یا سیدر دلخواه آن را فراخوانی کنید:

```php
$this->call(\Database\Seeders\DefaultAccountsSeeder::class);
```

### پابلیش همه دارایی‌های پکیج

```bash
php artisan vendor:publish --provider="Karnoweb\Accounting\AccountingServiceProvider"
```

---

## تنظیمات اولیه

1. بعد از مایگریشن، حداقل یک **شعبه** و یک **سال مالی** با وضعیت فعال داشته باشید (مثلاً با `DefaultAccountsSeeder`).
2. در `config/accounting.php` در صورت نیاز مقادیر زیر را تنظیم کنید:
   - `accounting.user.model` — مدل کاربر (برای `created_by`, `posted_by`)
   - `accounting.branch.default_id` — شعبه پیش‌فرض
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

---

## پابلیش پکیج (برای انتشار روی Packagist)

1. در `packages/laravel-accounting` نسخه را در `composer.json` به‌روز کنید (`version`).
2. در صورت نیاز تگ بزنید و به رپازیتوری (مثلاً GitHub) push کنید.
3. پکیج را در [Packagist](https://packagist.org) با آدرس رپو ثبت کنید تا با `composer require karnoweb/laravel-accounting` قابل نصب باشد.

---

## لایسنس

MIT
