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
    'balance' => [...],
    'validation' => [...],
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
| `date_format` | `Y-m-d` | فرمت مرجع تاریخ |
| `decimal_places` | `2` | دقت مبلغ‌ها |

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
- اگر `separate_numbering = true` باشد، `DocumentNumberSequence` به ازای هر FY+Branch جدا می‌شود.

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

کلیدهای پیش‌فرض:

- `cash`
- `bank`
- `receivables`
- `payables`
- `sales_income`
- `cost_of_goods`
- `refund_expense`
- `retained_earnings`

این کلیدها قرارداد مهم سرویس‌ها هستند؛ مخصوصاً `retained_earnings` برای `ClosingService`.

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
| `auto_detect` | `true` | تلاش برای یافتن FY از روی تاریخ |
| `default_id` | `null` | در config وجود دارد، اما `DocumentService` امروز عملاً ابتدا `findByDate()` و بعد `current()` را استفاده می‌کند |
| `allow_overlap` | `false` | اجازه هم‌پوشانی سال‌های مالی |

اگر `allow_overlap = false` باشد، `FiscalYearService::assertNoOverlap()` هم‌پوشانی را رد می‌کند.

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

## `reports`

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `per_page` | `50` | در config وجود دارد، اما گزارش‌های هسته‌ای فعلی DTO-based هستند و صفحه‌بندی داخلی از این کلید استفاده نمی‌کند |

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

## تغییرات حساس

این تنظیمات را بدون بررسی اثرات جانبی تغییر ندهید:

1. `general.prefix`
2. `account.code_length`
3. `account.posting_level`
4. `account.system_accounts.retained_earnings`
5. `branch.separate_numbering`
6. `fiscal_year.allow_overlap`
