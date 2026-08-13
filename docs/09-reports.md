# 09-reports.md

# گزارش‌های حسابداری

## Reports

---

## گزارش‌های موجود در پکیج

پکیج در حال حاضر **فقط** گزارش تراز آزمایشی را در لایه سرویس ارائه می‌دهد. گزارش‌های دفتر کل، سود و زیان، ترازنامه، و UI مربوطه متعلق به اپلیکیشن مصرف‌کننده هستند.

---

## دسترسی

```php
use Karnoweb\Accounting\Facades\Accounting;

$rows = Accounting::report()->trialBalance($fiscalYear);
```

یا:

```php
use Karnoweb\Accounting\Services\ReportService;

$rows = app(ReportService::class)->trialBalance($fiscalYear);
```

---

## تراز آزمایشی (`trialBalance`)

### امضا

```php
public function trialBalance(?FiscalYear $fiscalYear = null): array
```

### رفتار

- اگر `$fiscalYear` ندهید، سال مالی جاری (`FiscalYear::current()`) استفاده می‌شود.
- اگر سال مالی فعال نباشد، آرایه خالی برمی‌گردد.
- فقط حساب‌های **سطح ثبت** (`accounting.account.posting_level`، پیش‌فرض آخرین سطح `code_length`) و فعال را بررسی می‌کند.
- مانده از `BalanceService::getBalance($account, $fiscalYear)` خوانده می‌شود؛ بنابراین **سال‌مالی‌محور** است و کش FY دیگر را آلوده نمی‌کند.
- ردیف‌هایی با مانده نزدیک صفر (`|balance| < 0.01`) حذف می‌شوند.

### خروجی

```php
[
    [
        'account' => Account, // مدل حساب
        'debit'   => float,   // مانده مثبت
        'credit'  => float,   // قدر مطلق مانده منفی
    ],
    // ...
]
```

### مثال

```php
$fy = Accounting::currentFiscalYear();
$tb = Accounting::report()->trialBalance($fy);

foreach ($tb as $row) {
    echo $row['account']->code, ' ', $row['debit'], ' ', $row['credit'], PHP_EOL;
}
```

---

## آنچه پکیج ارائه نمی‌دهد

موارد زیر عمداً خارج از کرنل هستند و باید در اپلیکیشن (مثلاً Karno Base) پیاده شوند:

- UI گزارش‌ها
- دفتر روزنامه / دفتر کل تعاملی
- صورت سود و زیان کسب‌وکار
- ترازنامه مدیریتی
- زیرسیستم مشتری/تأمین‌کننده
- نگاشت کیف پول / فاکتور / سفارش
