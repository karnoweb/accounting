# 10-multi-branch.md

# استفاده از شعبه (branch_id)

## Multi-Branch / branch_id

---

## مقدمه

پکیج حسابداری **جدول شعبه ایجاد نمی‌کند**. در جداول `accounts` و `documents` فقط فیلد **`branch_id`** (nullable) وجود دارد. شعبه پیش‌فرض از `config('accounting.branch.default_id')` و در صورت نیاز از **resolver** تأمین می‌شود. اگر در اپلیکیشن جدول/مدل Branch دارید، می‌توانید در config به آن اشاره کنید تا رابطهٔ `branch()` روی Document و Account کار کند.

---

## ۱. مفهوم شعبه در پکیج

### ۱.۱ تعریف

- پکیج فقط **شناسه عددی شعبه** (`branch_id`) را ذخیره می‌کند.
- جدول و مدل شعبه در اختیار **اپلیکیشن** است (اختیاری).
- شعبه پیش‌فرض: `config('accounting.branch.default_id')` یا خروجی **resolver**.

### ۱.۲ کاربردها

| سناریو | مثال |
|--------|------|
| یک شعبه | فقط `default_id` را در config تنظیم کنید. |
| چند شعبه | جدول/مدل Branch در اپ داشته باشید؛ در سند و حساب `branch_id` را عدد یا مدل بدهید. |
| بدون شعبه | `branch.enabled => false` یا `default_id => null` و resolver در صورت نیاز. |

---

## ۲. تنظیمات Config

در `config/accounting.php`:

```php
'branch' => [
    'enabled' => true,
    'model'   => env('ACCOUNTING_BRANCH_MODEL', App\Models\Branch::class),  // اختیاری؛ برای رابطه branch()
    'table'   => env('ACCOUNTING_BRANCH_TABLE', 'branches'),
    'foreign_key' => 'branch_id',
    'default_id'  => 1,
    'separate_numbering' => false,
    'resolver' => null,
],
```

- **default_id**: شعبه پیش‌فرض (عدد). وقتی resolver مقدار ندهد یا در DocumentBuilder شعبه تعیین نشود، استفاده می‌شود.
- **resolver**: تابعی که شناسه شعبه جاری را برمی‌گرداند (مثلاً از کاربر، session یا header). در صورت تعریف، برای سندهای بدون شعبهٔ صریح استفاده می‌شود.
- **model / table**: فقط برای رابطهٔ Eloquent `$document->branch` و `$account->branch`؛ اگر در اپ جدول/مدل Branch ندارید، می‌توانید مدل را خالی بگذارید یا غیرفعال کنید.

### ۲.۱ غیرفعال کردن شعبه

```php
'branch' => [
    'enabled' => false,
],
```

در این حالت `branch_id` در سندها و حساب‌ها می‌تواند null باشد و `Accounting::currentBranch()` مقدار null برمی‌گرداند.

### ۲.۲ Resolver برای شعبه جاری

```php
'branch' => [
    'enabled'  => true,
    'default_id' => 1,
    'resolver' => function () {
        if (auth()->check() && auth()->user()->branch_id) {
            return auth()->user()->branch_id;
        }
        if (session()->has('current_branch_id')) {
            return session('current_branch_id');
        }
        if (request()->hasHeader('X-Branch-ID')) {
            return (int) request()->header('X-Branch-ID');
        }
        return config('accounting.branch.default_id');
    },
],
```

---

## ۳. جدول/مدل شعبه (اختیاری – در اپلیکیشن)

پکیج مایگریشن جدول `branches` ندارد. اگر چند شعبه دارید و می‌خواهید عنوان/کد شعبه را نگه دارید، جدول و مدل Branch را **در اپلیکیشن** ایجاد کنید و در config مقدار `accounting.branch.model` و در صورت نیاز `accounting.branch.table` را تنظیم کنید. سپس می‌توانید از رابطهٔ `$document->branch` و `$account->branch` استفاده کنید.

نمونه فیلدهای پیشنهادی برای جدول اپ: `id`, `code`, `title`, `is_active`, `is_default`, `meta`, `created_at`, `updated_at`.

---

## ۴. ثبت سند با شعبه

### ۴.۱ با شناسه عددی

```php
Accounting::document()
    ->type('sale')
    ->date(now())
    ->branch(2)   // branch_id = 2
    ->debit($cashAccount, 1_000_000)
    ->credit($salesAccount, 1_000_000)
    ->post();
```

### ۴.۲ با مدل Branch (در صورت وجود در اپ)

```php
$tehranBranch = Branch::find(2);
Accounting::document()
    ->type('sale')
    ->branch($tehranBranch)
    ->debit($cashAccount, 1_000_000)
    ->credit($salesAccount, 1_000_000)
    ->post();
```

### ۴.۳ شعبه خودکار (از resolver / default_id)

اگر در DocumentBuilder متد `branch()` را فراخوانی نکنید، مقدار از **resolver** یا در نهایت **default_id** گرفته می‌شود.

### ۴.۴ سند بدون شعبه

اگر `branch.enabled` false باشد یا resolver/default_id مقدار null برگرداند، سند با `branch_id = null` ذخیره می‌شود.

---

## ۵. حساب‌های با branch_id

### ۵.۱ ایجاد حساب با شعبه

```php
Accounting::account()->create([
    'parent_code' => '1101',
    'title'      => 'صندوق شعبه تهران',
    'type'       => 'asset',
    'nature'     => 'debit',
    'branch_id'  => 2,
]);
```

### ۵.۲ Query حساب‌ها

```php
use Karnoweb\Accounting\Models\Account;

$branchAccounts = Account::where('branch_id', $branchId)->get();
$sharedAccounts = Account::whereNull('branch_id')->get();
$accessibleAccounts = Account::where(function ($q) use ($branchId) {
    $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
})->get();
```

---

## ۶. گزارش‌گیری با branch_id

```php
$trialBalance = Accounting::report()->trialBalance(branchId: 2);
$allBranches   = Accounting::report()->trialBalance(branchId: null);
```

سایر متدهای گزارش در صورت پشتیبانی، پارامتر `branchId` را به همین شکل می‌پذیرند.

---

## ۷. شعبه جاری و رابطه branch()

```php
$currentBranch = Accounting::currentBranch();  // از config (default_id یا model با is_default)
```

اگر در config مدل Branch تنظیم شده باشد، `$document->branch` و `$account->branch` به همان مدل لینک می‌شوند.

---

## ۸. شماره‌گذاری سند به تفکیک شعبه

```php
'branch' => [
    'separate_numbering' => true,
],
```

در این حالت شمارهٔ سند به `branch_id` وابسته می‌شود (هر شعبه سری شمارهٔ خودش را دارد).

---

## ۹. خلاصه

| موضوع | نکته |
|-------|------|
| جدول branches | توسط پکیج ایجاد **نمی‌شود**؛ در صورت نیاز در اپ تعریف کنید. |
| شعبه در پکیج | فقط **branch_id** (عدد یا null). |
| شعبه پیش‌فرض | `config('accounting.branch.default_id')` و اختیاری **resolver**. |
| ثبت سند | `->branch($branchId)` یا `->branch($branchModel)`. |
| رابطه branch() | در صورت تنظیم `accounting.branch.model` در اپ. |

---

[→ ادامه: چندزبانگی (11-multi-language.md)](11-multi-language.md)

[← بازگشت: گزارش‌ها (09-reports.md)](09-reports.md)

[⌂ فهرست (00-index.md)](00-index.md)
