# نصب و راه‌اندازی

## پیش‌نیازها

بر اساس `composer.json` فعلی:

- PHP `^8.3`
- Laravel `^13.0`

## نصب با Composer

```bash
composer require karnoweb/laravel-accounting
```

## انتشار فایل‌های اختیاری

### config

```bash
php artisan vendor:publish --tag=accounting-config
```

### فایل‌های زبان

```bash
php artisan vendor:publish --tag=accounting-lang
```

نکته: migrationهای پکیج auto-loaded هستند و نیازی به publish جداگانه ندارند.

## اجرای migrationها

```bash
php artisan migrate
```

## جداولی که پکیج ایجاد می‌کند

با پیشوند `acc_` در حالت پیش‌فرض:

- `acc_fiscal_years`
- `acc_accounts`
- `acc_cost_centers`
- `acc_documents`
- `acc_document_items`
- `acc_document_logs`
- `acc_document_number_sequences`

و همچنین migration افزودن:

- `documents.idempotency_key`
- `documents.reversed_document_id`
- indexهای reporting

## آنچه پکیج ایجاد نمی‌کند

- جدول `branches`
- جدول کاربران
- داده‌های domain مثل مشتری، فروشنده، بانک، صندوق، کالا

## Seeder پیش‌فرض

پکیج `DefaultAccountsSeeder` را ارائه می‌دهد:

```php
$this->call(\Karnoweb\Accounting\Database\Seeders\DefaultAccountsSeeder::class);
```

یا:

```php
\Karnoweb\Accounting\Database\Seeders\DefaultAccountsSeeder::syncForBranch($branchId);
```

## کارهایی که بعد از نصب باید انجام دهید

1. config را بررسی کنید
2. migrationها را اجرا کنید
3. حداقل یک سال مالی active بسازید
4. chart of accounts اولیه را seed کنید
5. در صورت نیاز مدل `Branch` اپلیکیشن را در config معرفی کنید

## حداقل راه‌اندازی عملی

```php
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Database\Seeders\DefaultAccountsSeeder;

// 1) seed chart and initial fiscal year
$this->call(DefaultAccountsSeeder::class);

// 2) use the package
Accounting::document()
    ->type('adjustment')
    ->date(now())
    ->debit(Accounting::systemAccount('cash'), 1000)
    ->credit(Accounting::systemAccount('retained_earnings'), 1000)
    ->post();
```

## هشدارهای مهم

1. تغییر `general.prefix` را بعد از migration زنده بدون برنامه مهاجرت انجام ندهید.
2. اگر `retained_earnings` درست تنظیم نشود، `ClosingService` کار نخواهد کرد.
3. اگر جدول `branches` در اپلیکیشن شما وجود ندارد، رابطه Eloquent شعبه را استفاده نکنید.
