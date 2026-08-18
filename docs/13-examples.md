# مثال‌های استفاده

این فایل فقط مثال‌هایی را نگه می‌دارد که مستقیماً با API هسته پکیج منطبق‌اند.  
سناریوهای دامنه‌محور بزرگ‌تر در `docs/examples/shop/` قرار دارند و الزاماً قرارداد هسته نیستند.

## ۱. ثبت یک سند ساده

```php
Accounting::document()
    ->type('adjustment')
    ->date('2026-08-18')
    ->description('ثبت اصلاحی')
    ->debit($cashAccount, 1000)
    ->credit($capitalAccount, 1000)
    ->post();
```

## ۲. ثبت پیش‌نویس و سپس ثبت قطعی

```php
$document = Accounting::document()
    ->type('receipt')
    ->date(now())
    ->reference('RCPT-1001')
    ->debit(Accounting::systemAccount('cash'), 500)
    ->credit($customer->account, 500)
    ->save();

$document->post();
```

## ۳. استفاده از شعبه

```php
Accounting::document()
    ->branch(3)
    ->type('payment')
    ->date(now())
    ->debit($expenseAccount, 200)
    ->credit(Accounting::systemAccount('cash'), 200)
    ->post();
```

## ۴. استفاده از مرکز هزینه

```php
Accounting::document()
    ->type('adjustment')
    ->debit($expenseAccount, 200, 'هزینه پروژه')->costCenter($projectCenter)
    ->credit(Accounting::systemAccount('cash'), 200)
    ->post();
```

## ۵. ثبت افتتاحیه دستی

```php
Accounting::opening()->post($fiscalYear, [
    ['account_id' => $cashAccount->id, 'amount' => 1000, 'sign' => 1],
    ['account_id' => $capitalAccount->id, 'amount' => 1000, 'sign' => -1],
]);
```

## ۶. بستن سود و زیان

```php
$documents = Accounting::closing()->closeProfitAndLoss($fiscalYear);
```

این عملیات:

- حساب‌های موقت را صفر می‌کند
- خالص را به `retained_earnings` می‌برد
- سال مالی را خودکار `closed` نمی‌کند

## ۷. برگشت سند

```php
$reversal = $document->reverse('ثبت اشتباه');
```

یا:

```php
$reversal = Accounting::reversal()->reverse($document, [
    'reason' => 'ثبت اشتباه',
]);
```

## ۸. گزارش تراز آزمایشی

```php
$report = Accounting::report()->trialBalanceDetailed($fiscalYear);

$rows = $report->rows;
$totals = $report->totals();
```

## ۹. دفتر یک حساب

```php
use Karnoweb\Accounting\Reporting\LedgerQuery;

$statement = Accounting::report()->accountStatement(
    LedgerQuery::make()
        ->forAccount($cashAccount)
        ->forFiscalYear($fiscalYear)
);
```

## ۱۰. اتصال مدل پروژه به حساب

```php
class Customer extends Model
{
    use \Karnoweb\Accounting\Traits\HasAccount;

    protected function accountConfig(): array
    {
        return [
            'parent_code' => '1103',
            'title' => $this->full_name,
            'type' => 'asset',
            'nature' => 'debit',
            'auto_create' => true,
        ];
    }
}
```

## مرز این مثال‌ها

این مثال‌ها فقط نشان می‌دهند با API هسته چه می‌توان کرد.  
برای سناریوهای فروشگاهی، مالیاتی، انباری یا گردش بانکی باید منطق اپلیکیشن مصرف‌کننده را هم در نظر بگیرید.
