# 06-configuration.md

# پیکربندی

## Configuration

---

## مقدمه

این بخش تمام تنظیمات قابل پیکربندی پکیج حسابداری را شرح می‌دهد. فایل پیکربندی در مسیر `config/accounting.php` قرار دارد.

---

## ۱. ساختار کلی فایل Config

```php
<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | تنظیمات عمومی
    |--------------------------------------------------------------------------
    */
    'general' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات کاربر
    |--------------------------------------------------------------------------
    */
    'user' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات سال مالی
    |--------------------------------------------------------------------------
    */
    'fiscal_year' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات شعبه
    |--------------------------------------------------------------------------
    */
    'branch' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات حساب
    |--------------------------------------------------------------------------
    */
    'account' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات سند
    |--------------------------------------------------------------------------
    */
    'document' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات مانده و Cache
    |--------------------------------------------------------------------------
    */
    'balance' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات اعتبارسنجی
    |--------------------------------------------------------------------------
    */
    'validation' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات گزارش‌ها
    |--------------------------------------------------------------------------
    */
    'reports' => [
        // ...
    ],

];
```

---

## ۲. تنظیمات عمومی (general)

### ۲.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| prefix | string | 'accounting' | پیشوند جداول دیتابیس |
| date_format | string | 'Y-m-d' | فرمت تاریخ |
| decimal_places | int | 2 | تعداد ارقام اعشار |
| thousand_separator | string | ',' | جداکننده هزارگان |
| decimal_separator | string | '.' | جداکننده اعشار |

### ۲.۲ مثال

```php
'general' => [
    'prefix' => 'acc',
    'date_format' => 'Y-m-d',
    'decimal_places' => 2,
    'thousand_separator' => ',',
    'decimal_separator' => '.',
],
```

### ۲.۳ کاربرد prefix

اگر prefix را تغییر دهید، نام جداول تغییر می‌کند:

| prefix | نام جدول |
|--------|----------|
| '' (خالی) | accounts |
| 'accounting' | accounting_accounts |
| 'acc' | acc_accounts |

⚠️ **هشدار:** prefix را فقط قبل از اجرای Migration تغییر دهید.

---

## ۳. تنظیمات کاربر (user)

### ۳.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| model | string | App\Models\User::class | کلاس مدل کاربر |
| table | string | 'users' | نام جدول کاربران |
| foreign_key | string | 'user_id' | نام کلید خارجی |
| owner_id_resolver | callable/null | null | تابع برای یافتن کاربر جاری |

### ۳.۲ مثال پایه

```php
'user' => [
    'model' => App\Models\User::class,
    'table' => 'users',
    'foreign_key' => 'user_id',
    'owner_id_resolver' => null,
],
```

### ۳.۳ مثال با جدول متفاوت

اگر جدول کاربران شما `admins` نام دارد:

```php
'user' => [
    'model' => App\Models\Admin::class,
    'table' => 'admins',
    'foreign_key' => 'admin_id',
    'owner_id_resolver' => null,
],
```

### ۳.۴ مثال با Resolver سفارشی

برای پروژه‌های Multi-tenant:

```php
'user' => [
    'model' => App\Models\User::class,
    'table' => 'users',
    'foreign_key' => 'user_id',
    'owner_id_resolver' => function () {
        return auth()->id() ?? request()->header('X-User-ID');
    },
],
```

---

## ۴. تنظیمات سال مالی (fiscal_year)

### ۴.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| auto_detect | bool | true | تشخیص خودکار سال مالی جاری |
| default_id | int/null | null | شناسه سال مالی پیش‌فرض |
| allow_backdated | bool | false | اجازه ثبت سند با تاریخ گذشته |
| lock_after_close | bool | true | قفل کردن پس از بستن |
| require_opening | bool | true | الزام ثبت افتتاحیه |

### ۴.۲ مثال

```php
'fiscal_year' => [
    'auto_detect' => true,
    'default_id' => null,
    'allow_backdated' => false,
    'lock_after_close' => true,
    'require_opening' => true,
],
```

### ۴.۳ توضیح auto_detect

| مقدار | رفتار |
|-------|-------|
| true | سال مالی بر اساس تاریخ سند انتخاب می‌شود |
| false | سال مالی با is_current=true استفاده می‌شود |

### ۴.۴ توضیح allow_backdated

| مقدار | رفتار |
|-------|-------|
| true | می‌توان سند با تاریخ قبل از امروز ثبت کرد |
| false | تاریخ سند باید امروز یا بعد باشد |

---

## ۵. تنظیمات شعبه (branch)

### ۵.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| enabled | bool | true | فعال بودن قابلیت شعبه |
| default_id | int/null | null | شناسه شعبه پیش‌فرض |
| required | bool | false | الزامی بودن شعبه در سند |
| auto_detect | bool | false | تشخیص خودکار شعبه کاربر |
| resolver | callable/null | null | تابع برای یافتن شعبه جاری |

### ۵.۲ مثال بدون شعبه

اگر پروژه شما نیاز به شعبه ندارد:

```php
'branch' => [
    'enabled' => false,
    'default_id' => null,
    'required' => false,
    'auto_detect' => false,
    'resolver' => null,
],
```

### ۵.۳ مثال با شعبه پیش‌فرض

```php
'branch' => [
    'enabled' => true,
    'default_id' => 1,
    'required' => true,
    'auto_detect' => false,
    'resolver' => null,
],
```

### ۵.۴ مثال با Resolver

برای تشخیص شعبه کاربر:

```php
'branch' => [
    'enabled' => true,
    'default_id' => null,
    'required' => true,
    'auto_detect' => true,
    'resolver' => function () {
        return auth()->user()?->branch_id;
    },
],
```

---

## ۶. تنظیمات حساب (account)

### ۶.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| code_separator | string | '' | جداکننده اجزای کد |
| code_length | array | [1,2,4,6] | طول کد در هر سطح |
| auto_code | bool | true | تولید خودکار کد |
| allow_negative_balance | bool | false | اجازه مانده منفی |
| warn_abnormal_balance | bool | true | هشدار مانده غیرطبیعی |
| system_accounts | array | [...] | حساب‌های سیستمی |

### ۶.۲ مثال

```php
'account' => [
    'code_separator' => '',
    'code_length' => [1, 2, 4, 6],
    'auto_code' => true,
    'allow_negative_balance' => false,
    'warn_abnormal_balance' => true,
    'system_accounts' => [
        'cash' => '110101',
        'bank' => '110201',
        'receivables' => '1103',
        'payables' => '2101',
        'sales_income' => '410101',
        'cost_of_goods' => '510101',
        'retained_earnings' => '3102',
    ],
],
```

### ۶.۳ توضیح code_length

تعیین طول کد حساب در هر سطح:

| سطح | index | طول | مثال |
|-----|-------|-----|------|
| گروه | 0 | 1 | 1 |
| کل | 1 | 2 | 11 |
| معین | 2 | 4 | 1101 |
| تفصیلی | 3 | 6 | 110101 |

### ۶.۴ توضیح system_accounts

حساب‌های سیستمی برای دسترسی سریع:

```php
// در کد
$cashAccount = Accounting::systemAccount('cash');
$bankAccount = Accounting::systemAccount('bank');
```

### ۶.۵ مثال code_separator

| code_separator | نتیجه |
|----------------|-------|
| '' | 110101 |
| '-' | 1-10-1-01 |
| '.' | 1.10.1.01 |

---

## ۷. تنظیمات سند (document)

### ۷.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| auto_number | bool | true | شماره‌گذاری خودکار |
| number_prefix | string | '' | پیشوند شماره سند |
| number_format | string | '%d' | فرمت شماره سند |
| number_reset_yearly | bool | true | ریست شماره در سال جدید |
| require_description | bool | false | الزام توضیحات |
| default_status | string | 'draft' | وضعیت پیش‌فرض |
| allowed_types | array | [...] | انواع مجاز سند |
| workflow_enabled | bool | false | فعال بودن گردش کار |
| min_items | int | 2 | حداقل تعداد آیتم |

### ۷.۲ مثال

```php
'document' => [
    'auto_number' => true,
    'number_prefix' => '',
    'number_format' => '%d',
    'number_reset_yearly' => true,
    'require_description' => false,
    'default_status' => 'draft',
    'allowed_types' => [
        'sale',
        'purchase',
        'receipt',
        'payment',
        'transfer',
        'opening',
        'closing',
        'adjustment',
    ],
    'workflow_enabled' => false,
    'min_items' => 2,
],
```

### ۷.۳ توضیح number_format

| فرمت | ورودی | خروجی |
|------|-------|-------|
| '%d' | 123 | 123 |
| '%05d' | 123 | 00123 |
| 'DOC-%04d' | 123 | DOC-0123 |

### ۷.۴ توضیح workflow_enabled

| مقدار | رفتار |
|-------|-------|
| false | سند مستقیم از draft به posted می‌رود |
| true | سند باید مراحل pending و approved را طی کند |

---

## ۸. تنظیمات مانده و Cache (balance)

### ۸.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| cache_enabled | bool | true | فعال بودن Cache مانده |
| cache_ttl | int | 3600 | مدت اعتبار Cache (ثانیه) |
| update_strategy | string | 'immediate' | استراتژی بروزرسانی |
| update_parents | bool | true | بروزرسانی حساب‌های والد |
| use_summary_table | bool | false | استفاده از جدول خلاصه |

### ۸.۲ مثال

```php
'balance' => [
    'cache_enabled' => true,
    'cache_ttl' => 3600,
    'update_strategy' => 'immediate',
    'update_parents' => true,
    'use_summary_table' => false,
],
```

### ۸.۳ توضیح update_strategy

| مقدار | شرح | کاربرد |
|-------|-----|--------|
| 'immediate' | بلافاصله پس از ثبت سند | دقت بالا |
| 'delayed' | با Job در صف | عملکرد بهتر |
| 'scheduled' | با زمان‌بندی (Cron) | پروژه‌های بزرگ |

### ۸.۴ مثال برای پروژه بزرگ

```php
'balance' => [
    'cache_enabled' => true,
    'cache_ttl' => 300,
    'update_strategy' => 'delayed',
    'update_parents' => true,
    'use_summary_table' => true,
],
```

---

## ۹. تنظیمات اعتبارسنجی (validation)

### ۹.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| strict_balance | bool | true | الزام بالانس بودن سند |
| check_fiscal_year | bool | true | بررسی سال مالی |
| check_account_active | bool | true | بررسی فعال بودن حساب |
| check_account_level | bool | true | بررسی سطح حساب |
| check_date_range | bool | true | بررسی بازه تاریخ |
| max_amount | float/null | null | حداکثر مبلغ مجاز |
| min_amount | float | 0.01 | حداقل مبلغ مجاز |

### ۹.۲ مثال

```php
'validation' => [
    'strict_balance' => true,
    'check_fiscal_year' => true,
    'check_account_active' => true,
    'check_account_level' => true,
    'check_date_range' => true,
    'max_amount' => null,
    'min_amount' => 0.01,
],
```

### ۹.۳ توضیح check_account_level

| مقدار | رفتار |
|-------|-------|
| true | فقط حساب‌های سطح ۳ (تفصیلی) قابل استفاده |
| false | همه سطوح قابل استفاده |

### ۹.۴ مثال با محدودیت مبلغ

```php
'validation' => [
    'strict_balance' => true,
    'check_fiscal_year' => true,
    'check_account_active' => true,
    'check_account_level' => true,
    'check_date_range' => true,
    'max_amount' => 10000000000,  // 10 میلیارد
    'min_amount' => 1,
],
```

---

## ۱۰. تنظیمات گزارش‌ها (reports)

### ۱۰.۱ فیلدها

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| default_format | string | 'array' | فرمت پیش‌فرض خروجی |
| paginate | bool | true | صفحه‌بندی نتایج |
| per_page | int | 50 | تعداد در هر صفحه |
| include_zero_balance | bool | false | نمایش حساب‌های صفر |
| cache_reports | bool | false | کش کردن گزارش‌ها |
| cache_ttl | int | 300 | مدت اعتبار کش گزارش |

### ۱۰.۲ مثال

```php
'reports' => [
    'default_format' => 'array',
    'paginate' => true,
    'per_page' => 50,
    'include_zero_balance' => false,
    'cache_reports' => false,
    'cache_ttl' => 300,
],
```

### ۱۰.۳ توضیح default_format

| مقدار | خروجی |
|-------|-------|
| 'array' | آرایه PHP |
| 'collection' | Laravel Collection |
| 'json' | JSON string |

---

## ۱۱. فایل کامل Config

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | تنظیمات عمومی
    |--------------------------------------------------------------------------
    */
    'general' => [
        'prefix' => '',
        'date_format' => 'Y-m-d',
        'decimal_places' => 2,
        'thousand_separator' => ',',
        'decimal_separator' => '.',
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات کاربر
    |--------------------------------------------------------------------------
    */
    'user' => [
        'model' => App\Models\User::class,
        'table' => 'users',
        'foreign_key' => 'user_id',
        'owner_id_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات سال مالی
    |--------------------------------------------------------------------------
    */
    'fiscal_year' => [
        'auto_detect' => true,
        'default_id' => null,
        'allow_backdated' => false,
        'lock_after_close' => true,
        'require_opening' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات شعبه
    |--------------------------------------------------------------------------
    */
    'branch' => [
        'enabled' => true,
        'default_id' => null,
        'required' => false,
        'auto_detect' => false,
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات حساب
    |--------------------------------------------------------------------------
    */
    'account' => [
        'code_separator' => '',
        'code_length' => [1, 2, 4, 6],
        'auto_code' => true,
        'allow_negative_balance' => false,
        'warn_abnormal_balance' => true,
        'system_accounts' => [
            'cash' => '110101',
            'bank' => '110201',
            'receivables' => '1103',
            'payables' => '2101',
            'sales_income' => '410101',
            'cost_of_goods' => '510101',
            'retained_earnings' => '3102',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات سند
    |--------------------------------------------------------------------------
    */
    'document' => [
        'auto_number' => true,
        'number_prefix' => '',
        'number_format' => '%d',
        'number_reset_yearly' => true,
        'require_description' => false,
        'default_status' => 'draft',
        'allowed_types' => [
            'sale',
            'purchase',
            'receipt',
            'payment',
            'transfer',
            'opening',
            'closing',
            'adjustment',
        ],
        'workflow_enabled' => false,
        'min_items' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات مانده و Cache
    |--------------------------------------------------------------------------
    */
    'balance' => [
        'cache_enabled' => true,
        'cache_ttl' => 3600,
        'update_strategy' => 'immediate',
        'update_parents' => true,
        'use_summary_table' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات اعتبارسنجی
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'strict_balance' => true,
        'check_fiscal_year' => true,
        'check_account_active' => true,
        'check_account_level' => true,
        'check_date_range' => true,
        'max_amount' => null,
        'min_amount' => 0.01,
    ],

    /*
    |--------------------------------------------------------------------------
    | تنظیمات گزارش‌ها
    |--------------------------------------------------------------------------
    */
    'reports' => [
        'default_format' => 'array',
        'paginate' => true,
        'per_page' => 50,
        'include_zero_balance' => false,
        'cache_reports' => false,
        'cache_ttl' => 300,
    ],

];
```

---

## ۱۲. نمونه تنظیمات برای سناریوهای مختلف

### ۱۲.۱ فروشگاه ساده

```php
'branch' => [
    'enabled' => false,
],
'document' => [
    'workflow_enabled' => false,
    'allowed_types' => ['sale', 'purchase', 'receipt', 'payment'],
],
'validation' => [
    'strict_balance' => true,
    'check_account_level' => true,
],
```

### ۱۲.۲ سازمان چندشعبه‌ای

```php
'branch' => [
    'enabled' => true,
    'required' => true,
    'auto_detect' => true,
    'resolver' => function () {
        return auth()->user()?->branch_id;
    },
],
'document' => [
    'workflow_enabled' => true,
],
```

### ۱۲.۳ پروژه با تراکنش‌های بالا

```php
'balance' => [
    'cache_enabled' => true,
    'cache_ttl' => 300,
    'update_strategy' => 'delayed',
    'use_summary_table' => true,
],
'reports' => [
    'cache_reports' => true,
    'cache_ttl' => 600,
],
```

---

## ۱۳. دسترسی به Config در کد

### ۱۳.۱ استفاده از Helper

```php
// دریافت یک مقدار
$prefix = config('accounting.general.prefix');

// دریافت با مقدار پیش‌فرض
$ttl = config('accounting.balance.cache_ttl', 3600);
```

### ۱۳.۲ استفاده از Facade

```php
use YourVendor\Accounting\Facades\Accounting;

// دریافت Config
$config = Accounting::config('document.auto_number');

// دریافت حساب سیستمی
$cashCode = Accounting::config('account.system_accounts.cash');
```

---

## ۱۴. تغییر Config در Runtime

### ۱۴.۱ تغییر موقت

```php
config(['accounting.validation.strict_balance' => false]);

// انجام عملیات خاص

config(['accounting.validation.strict_balance' => true]);
```

### ۱۴.۲ استفاده در Test

```php
public function test_document_without_balance_check()
{
    config(['accounting.validation.strict_balance' => false]);
    
    // تست
}
```

---

## ۱۵. عیب‌یابی Config

### ۱۵.۱ بررسی مقادیر

```bash
php artisan tinker
```

```php
config('accounting');
```

### ۱۵.۲ پاکسازی Cache

```bash
php artisan config:clear
php artisan cache:clear
```

### ۱۵.۳ خطاهای رایج

| خطا | علت | راه‌حل |
|-----|-----|--------|
| Config not found | فایل منتشر نشده | `vendor:publish --tag=accounting-config` |
| Invalid value | مقدار نامعتبر | بررسی نوع داده |
| Class not found | مسیر کلاس اشتباه | بررسی namespace |

---

[→ ادامه: یکپارچه‌سازی (07-integration.md)](07-integration.md)

[← بازگشت: نصب (05-installation.md)](05-installation.md)

[⌂ فهرست (00-index.md)](00-index.md)
