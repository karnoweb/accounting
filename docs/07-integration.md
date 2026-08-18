# یکپارچه‌سازی با پروژه

این فایل توضیح می‌دهد چگونه پروژه مصرف‌کننده را به هسته حسابداری وصل کنید، بدون این‌که فرض کنید پکیج خودش ماژول فروش، مشتری، بانک یا کالا دارد.

## الگوی کلی اتصال

پروژه مصرف‌کننده معمولاً سه کار انجام می‌دهد:

1. برخی مدل‌ها را با `HasAccount` به یک حساب متصل می‌کند
2. هنگام رخدادهای تجاری، سند حسابداری می‌سازد
3. برای گزارش‌گیری یا مانده‌گیری از سرویس‌های پکیج استفاده می‌کند

## ۱. اتصال مدل‌ها با `HasAccount`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Karnoweb\Accounting\Traits\HasAccount;

class Customer extends Model
{
    use HasAccount;
}
```

### این Trait چه می‌کند؟

- رابطه `account()` می‌سازد
- می‌تواند هنگام `created` به‌صورت خودکار حساب بسازد
- متدهای کمکی مثل `balance()`, `documents()`, `transactions()` می‌دهد

### سفارشی‌سازی رفتار

```php
protected function accountConfig(): array
{
    return [
        'parent_code' => '1103',
        'title' => $this->full_name,
        'type' => 'asset',
        'nature' => 'debit',
        'branch_id' => null,
        'is_active' => true,
        'allow_direct_posting' => true,
        'auto_create' => true,
    ];
}
```

### نکته مهم

`HasAccount` فقط یک **پل** است. این Trait به معنی وجود ماژول مستقل مشتری، بانک، صندوق یا کالا در پکیج نیست.

## ۲. ساخت سند هنگام رخداد تجاری

پکیج domain event تجاری شما را نمی‌شناسد. پروژه مصرف‌کننده باید خودش در نقطه مناسب سند بسازد.

### مثال: ثبت دریافت وجه

```php
Accounting::document()
    ->type('receipt')
    ->date(now())
    ->reference($payment->tracking_code)
    ->source($payment)
    ->debit(Accounting::systemAccount('cash'), $payment->amount)
    ->credit($customer->account, $payment->amount)
    ->post();
```

### مثال: ثبت فروش نسیه

```php
Accounting::document()
    ->type('sale')
    ->date($invoice->date)
    ->reference($invoice->number)
    ->source($invoice)
    ->debit($customer->account, $invoice->total)
    ->credit(Accounting::systemAccount('sales_income'), $invoice->total)
    ->post();
```

نکته: این‌ها فقط الگوی استفاده از API هستند. این‌که فروش شما چند ردیف لازم دارد یا بهای تمام‌شده چگونه ثبت می‌شود، به domain اپلیکیشن شما بستگی دارد.

## ۳. اتصال شعبه

اگر اپلیکیشن شما جدول `branches` دارد:

- مدل آن را در `accounting.branch.model` تنظیم کنید
- در سندها `branch($branch)` یا `branch($branchId)` بدهید

اگر جدول شعبه ندارید:

- فقط از `branch.default_id` استفاده کنید
- یا اسناد را بدون شعبه ثبت کنید

## ۴. اتصال سال مالی

دو الگوی رایج:

### Explicit

```php
Accounting::document()
    ->fiscalYear($fiscalYear)
    ->date('2026-08-18')
    ->debit($a, 100)
    ->credit($b, 100)
    ->post();
```

### Auto-detect

اگر `fiscal_year.auto_detect = true` باشد، `DocumentService` می‌تواند از روی تاریخ FY را پیدا کند.

## ۵. استفاده از `source_type` و `source_id`

برای trace کردن منشأ سند:

```php
Accounting::document()
    ->source($order)
    ->debit($a, 100)
    ->credit($b, 100)
    ->post();
```

این اتصال:

- برای trace و audit مفید است
- اما unique نیست
- و جایگزین `idempotency_key` نیست

## ۶. Retry امن با `idempotency_key`

اگر عملیات شما ممکن است دوبار اجرا شود:

```php
Accounting::document()
    ->idempotencyKey('payment:'.$payment->id)
    ->debit($a, 100)
    ->credit($b, 100)
    ->post();
```

این کلید در دیتابیس unique است و برای جلوگیری از ثبت تکراری کاربرد دارد.

## ۷. گوش دادن به رویدادهای پکیج

رویدادهای موجود:

- `DocumentCreated`
- `DocumentPosted`
- `DocumentVoided`

مثال:

```php
use Illuminate\Support\Facades\Event;
use Karnoweb\Accounting\Events\DocumentPosted;

Event::listen(DocumentPosted::class, function (DocumentPosted $event) {
    $document = $event->document;
});
```

## ۸. چه چیزهایی را نباید به پکیج تحمیل کنید؟

این فرض‌ها نادرست‌اند مگر خودتان در اپلیکیشن بسازید:

- هر مشتری حتماً حساب دریافتنی دارد
- هر بانک یا صندوق موجودیت داخلی پکیج است
- هر فروش حتماً بهای تمام‌شده و انبار همزمان دارد
- `reference` همیشه شماره فاکتور است
- `source_type/source_id` یکتا هستند

## ۹. الگوی پیشنهادی معماری در اپلیکیشن

بهترین الگو این است که در اپلیکیشن خود یک service یا action لایه بالا بسازید و پکیج را از آنجا فراخوانی کنید:

```php
final class RecordCustomerReceipt
{
    public function handle(Customer $customer, Payment $payment): void
    {
        Accounting::document()
            ->type('receipt')
            ->date($payment->paid_at)
            ->reference($payment->tracking_code)
            ->source($payment)
            ->idempotencyKey('payment:'.$payment->id)
            ->debit(Accounting::systemAccount('cash'), $payment->amount)
            ->credit($customer->account, $payment->amount)
            ->post();
    }
}
```

این کار باعث می‌شود:

- منطق domain اپ شما از هسته پکیج جدا بماند
- تغییر API هسته در یک نقطه مهار شود
- تست‌پذیری بهتر شود
