# پیکربندی

Source of Truth این فایل، `config/accounting.php` است.

## ساختار کلی

```php
return [
    'enabled' => true,
    'general' => [...],
    'user' => [...],
    'branch' => [...],
    'account' => [...],
    'document' => [...],
    'fiscal_year' => [...],
    'opening' => [...],
    'period' => [...],
    'balance' => [...],
    'validation' => [...],
    'routes' => [...],
    'reports' => [...],
];
```

## `enabled`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `enabled` | `true` | در config وجود دارد، اما در سرویس‌های اصلی enforce مستقیمی برای آن دیده نمی‌شود. |

## `general`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `prefix` | `acc_` | پیشوند جداول |
| `date_format` | `Y-m-d` | در config هست؛ سرویس‌های اصلی آن را نمی‌خوانند (informational) |
| `decimal_places` | `2` | مقیاس ذخیره‌سازی، مقایسه و نمایش مبلغ‌ها. با ستون‌های `decimal(15,2)` یکی است. محاسبات حسابداری با `Amount` (BCMath) انجام می‌شود، نه float. |

نکته: `BaseModel::getTable()` این پیشوند را به نام جداول مدل‌ها اضافه می‌کند.

## `user`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `model` | `App\Models\User::class` | مدل کاربر برای روابط `created_by`, `posted_by`, `user_id` |
| `table` | `users` | جدول کاربر |
| `foreign_key` | `user_id` | کلید خارجی کاربر |

نکته: گرفتن کاربر جاری در observer و `DocumentService` با `auth()->id()` انجام می‌شود و resolver سفارشی در config فعلی وجود ندارد.

## `branch`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `enabled` | `true` | فعال بودن مفهوم شعبه |
| `model` | `App\Models\Branch::class` | مدل اختیاری شعبه |
| `table` | `branches` | نام جدول شعبه در اپلیکیشن |
| `foreign_key` | `branch_id` | کلید خارجی |
| `default_id` | `1` | شعبه پیش‌فرض |
| `separate_numbering` | `false` | جدا بودن شماره‌گذاری اسناد به تفکیک شعبه |
| `resolver` | `null` | callback اختیاری برای resolve شعبه پیش‌فرض |

نکات:

- خود پکیج جدول `branches` را ایجاد نمی‌کند.
- اگر `separate_numbering = false` باشد، شماره سند در هر سال مالی مشترک است.
- اگر `separate_numbering = true` باشد، `DocumentNumberSequence` به ازای هر FY+Branch جدا می‌شود و یکتایی شماره روی `(fiscal_year_id, numbering_bucket, number)` است.

## `account`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `code_length` | `[1,2,4,6]` | طول کدها در سطوح حساب |
| `max_level` | `null` | اگر `null` باشد از `code_length` مشتق می‌شود |
| `posting_level` | `null` | اگر `null` باشد آخرین سطح است |
| `auto_code` | `true` | تولید خودکار کد حساب |
| `custom_seed` | `[]` | حساب‌های اضافی برای seeder |
| `system_accounts` | مجموعه کدها | نگاشت کلیدهای سیستمی به کد حساب |

### `system_accounts`

کلیدهای پیش‌فرض در `config/accounting.php` (نگاشت به کد حساب):

- هسته: `cash`, `bank`, `receivables`, `payables`, `sales_income`, `cost_of_goods`, `refund_expense`, `retained_earnings`
- انبار/یکپارچگی: `inventory`, `inventory_shrinkage`, `inventory_count_gain`
- پرداخت آنلاین: `gateway_clearing`, `bank_fee`
- فروش: `sales_discount`, `sales_return`
- مالیات: `vat_payable`, `payroll_tax_payable`
- حقوق: `employee_loan_receivable`, `payroll_payable`, `payroll_insurance_payable`, `payroll_salary_expense`, `payroll_employer_insurance`

وجود کلید فقط نگاشت کد است؛ ماژول انبار/حقوق/درگاه داخل این پکیج نیست. `ClosingService` به `retained_earnings` وابسته است.

`DefaultAccountsSeeder` اگر `config('accounting.seed.branch_id')` ست باشد همان شعبه را استفاده می‌کند. این کلید در فایل منتشرشدهٔ config **نیست**؛ فقط در صورت نیاز در اپ میزبان تعریف کنید.

## `document`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `min_items` | `2` | حداقل تعداد ردیف سند |
| `allowed_types` | آرایه انواع | فهرست قراردادی انواع سند |
| `workflow_enabled` | `false` | در config وجود دارد، اما در کد فعلی رفتار workflow کامل از آن مشتق نمی‌شود |
| `number_allocation_retries` | `5` | تعداد retry برای برخورد شماره سند |

نکته مهم: `allowed_types` در کد فعلی guard سخت‌گیرانه سراسری ندارد و بیشتر نقش contract/config را دارد.

## `fiscal_year`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `auto_detect` | `true` | یافتن FY از روی تاریخ سند |
| `default_id` | `null` | رزرو شده؛ مسیر اصلی resolve همان `findByDate` است |
| `allow_overlap` | `false` | اجازه هم‌پوشانی بازهٔ تاریخی سال‌ها |
| `allow_multiple_active` | `true` | چند سال هم‌زمان `active` (سال قبل باز برای اصلاح، سال جدید برای عملیات) |

اگر تاریخ سند داده شود و سالی پیدا نشود، خطای `no_fiscal_year_for_date` پرتاب می‌شود و به `current()` fallback نمی‌شود.

اگر `allow_multiple_active = false` باشد، `activate()` سال دوم را تا بستن سال اول رد می‌کند.

## `opening`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `allow_after_posted_activity` | `true` | اجازهٔ `confirm`/`post` افتتاحیه بعد از اسناد عملیاتی posted |
| `require_prior_year_closed_for_confirm` | `true` | تا بسته شدن سال متوالی قبلی، قطعی‌سازی افتتاحیه ممنوع |
| `allow_provisional_carry_forward` | `true` | `carryForward` از سال هنوز `active` فقط به‌صورت draft موقت |

راهنمای فارسی کامل: [17-multi-active-years-and-opening.md](17-multi-active-years-and-opening.md).

## `period`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `auto_create_on_activate` | `true` | ساخت دورهٔ باز تمام‌سال هنگام activate |

## `balance`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `cache_enabled` | `true` | فعال بودن کش مانده |
| `cache_ttl` | `3600` | عمر کش |
| `update_strategy` | `immediate` | در config وجود دارد؛ رفتار موثر اصلی در کد همان به‌روزرسانی فوری observer است |
| `update_parents` | `true` | به‌روزرسانی زنجیره والدها بعد از ثبت/ابطال |

نکته: گزارش‌ها از این کش استفاده نمی‌کنند.

## `validation`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `check_account_active` | `true` | رد حساب غیرفعال |
| `check_date_range` | `true` | کنترل تاریخ داخل بازه FY |
| `strict_balance` | `true` | رد سند نامتعادل |

## `routes`

پکیج به‌صورت پیش‌فرض HTTP ثبت نمی‌کند. برای `GET /accounts` و `GET /accounts/hierarchy`:

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `enabled` | `false` | ثبت routeهای فهرست و درخت حساب |
| `prefix` | `''` | پیشوند URL |
| `middleware` | `[]` | middleware میزبان؛ خالی یعنی بدون گروه اجباری |

## `reports`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `per_page` | `50` | اندازهٔ پیش‌فرض صفحه |
| `per_page_min` | `1` | کف `per_page` گزارش‌های پیشرفته |
| `per_page_max` | `200` | سقف `per_page`؛ مقدارهایی مثل `1000000` رد می‌شوند |
| `pagination_default` | `cursor` | پیش‌فرض گزارش‌های ترتیبی جدید؛ صورت‌حساب تک‌حساب همچنان offset است |
| `cash_system_keys` | `['cash', 'bank', 'gateway_clearing']` | کلیدهای `account.system_accounts` که `cashMovements()` آن‌ها را حساب نقد می‌داند. از عنوان یا کد حساب استنتاج نمی‌شود. |

## پیشنهاد پیکربندی اولیه

```php
'general' => [
    'prefix' => 'acc_',
],

'branch' => [
    'enabled' => true,
    'default_id' => 1,
    'separate_numbering' => false,
],

'account' => [
    'code_length' => [1, 2, 4, 6],
    'auto_code' => true,
],

'document' => [
    'min_items' => 2,
    'number_allocation_retries' => 5,
],

'fiscal_year' => [
    'auto_detect' => true,
    'allow_overlap' => false,
],
```

## وضعیت اجرا (enforcement)

| کلید | وضعیت |
|------|--------|
| `enabled` | **unused** — سرویس‌ها آن را نمی‌خوانند |
| `general.date_format` | **unused** / informational |
| `document.allowed_types` | **unused** — قرارداد؛ create/post نوع آزاد را رد نمی‌کند |
| `document.workflow_enabled` | **unused** — وضعیت‌های `pending`/`approved` وجود دارند، سرویس workflow نیست |
| `fiscal_year.default_id` | **unused** — resolve از `findByDate` / `current()` است |
| `balance.update_strategy` | **unused** — observer همیشه immediate است |
| بقیهٔ کلیدهای همین فایل | **fully enforced** مگر جایی که صریحاً گفته شده |

این کلیدهای unused را implement / deprecate / حذف کنید؛ مستندات آن‌ها را طوری ننویسید که انگار امروز enforce می‌شوند.

## تغییرات حساس

این تنظیمات را بدون بررسی اثرات جانبی تغییر ندهید:

1. `general.prefix`
2. `account.code_length`
3. `account.posting_level`
4. `account.system_accounts.retained_earnings`
5. `branch.separate_numbering`
6. `fiscal_year.allow_overlap`
