# 09-reports.md

# گزارش‌های حسابداری

## Reports

---

## مقدمه

این بخش انواع گزارش‌های حسابداری پکیج را شرح می‌دهد. هر گزارش با پارامترها، خروجی و مثال‌های کاربردی توضیح داده شده است.

---

## ۱. دسترسی به گزارش‌ها

### ۱.۱ از طریق Facade

```php
use YourVendor\Accounting\Facades\Accounting;

$report = Accounting::report();
```

### ۱.۲ از طریق Service

```php
use YourVendor\Accounting\Services\ReportService;

$report = app(ReportService::class);
```

---

## ۲. تراز آزمایشی (Trial Balance)

### ۲.۱ تعریف

لیست تمام حساب‌ها با مجموع بدهکار، بستانکار و مانده. برای بررسی صحت ثبت‌ها استفاده می‌شود.

### ۲.۲ متد

```php
public function trialBalance(
    ?FiscalYear $fiscalYear = null,
    ?Carbon $asOfDate = null,
    ?int $branchId = null,
    array $options = []
): Collection
```

### ۲.۳ پارامترها

| پارامتر | نوع | پیش‌فرض | شرح |
|---------|-----|---------|-----|
| fiscalYear | FiscalYear | جاری | سال مالی |
| asOfDate | Carbon | امروز | تا تاریخ |
| branchId | int | همه | فیلتر شعبه |
| options | array | [] | تنظیمات اضافی |

**options:**

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| include_zero | bool | false | نمایش حساب‌های صفر |
| level | int | null | فیلتر سطح |
| type | string | null | فیلتر نوع |
| group_by_type | bool | false | گروه‌بندی بر اساس نوع |

### ۲.۴ خروجی

هر ردیف شامل:

| فیلد | نوع | شرح |
|------|-----|-----|
| account_id | int | شناسه حساب |
| code | string | کد حساب |
| title | string | عنوان حساب |
| level | int | سطح حساب |
| type | string | نوع حساب |
| nature | string | ماهیت حساب |
| debit | float | مجموع بدهکار |
| credit | float | مجموع بستانکار |
| balance | float | مانده (بدهکار - بستانکار) |
| balance_debit | float | مانده بدهکار (اگر مثبت) |
| balance_credit | float | مانده بستانکار (اگر منفی) |

### ۲.۵ مثال‌ها

**تراز آزمایشی ساده:**

```php
$trialBalance = Accounting::report()->trialBalance();

foreach ($trialBalance as $row) {
    echo "{$row['code']} - {$row['title']}: ";
    echo "بدهکار: {$row['debit']} | ";
    echo "بستانکار: {$row['credit']} | ";
    echo "مانده: {$row['balance']}\n";
}
```

**تراز در تاریخ مشخص:**

```php
$trialBalance = Accounting::report()->trialBalance(
    fiscalYear: null,
    asOfDate: Carbon::parse('2024-03-31')
);
```

**تراز یک شعبه:**

```php
$trialBalance = Accounting::report()->trialBalance(
    fiscalYear: null,
    asOfDate: null,
    branchId: 1
);
```

**تراز با گروه‌بندی:**

```php
$trialBalance = Accounting::report()->trialBalance(
    options: ['group_by_type' => true]
);

// خروجی:
// [
//     'asset' => [...],
//     'liability' => [...],
//     'equity' => [...],
//     'income' => [...],
//     'expense' => [...],
// ]
```

**تراز فقط سطح معین:**

```php
$trialBalance = Accounting::report()->trialBalance(
    options: ['level' => 2]
);
```

### ۲.۶ بررسی توازن

```php
$trialBalance = Accounting::report()->trialBalance();

$totalDebit = $trialBalance->sum('balance_debit');
$totalCredit = $trialBalance->sum('balance_credit');
$isBalanced = abs($totalDebit - $totalCredit) < 0.01;

if (!$isBalanced) {
    throw new Exception("تراز نیست! اختلاف: " . ($totalDebit - $totalCredit));
}
```

---

## ۳. دفتر کل (General Ledger)

### ۳.۱ تعریف

گردش یک حساب خاص با تمام تراکنش‌ها و مانده پس از هر تراکنش.

### ۳.۲ متد

```php
public function generalLedger(
    Account|int $account,
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null,
    array $options = []
): array
```

### ۳.۳ پارامترها

| پارامتر | نوع | پیش‌فرض | شرح |
|---------|-----|---------|-----|
| account | Account/int | الزامی | حساب |
| fiscalYear | FiscalYear | جاری | سال مالی |
| fromDate | Carbon | شروع سال | از تاریخ |
| toDate | Carbon | امروز | تا تاریخ |
| options | array | [] | تنظیمات |

**options:**

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| include_opening | bool | true | شامل مانده افتتاحیه |
| paginate | bool | false | صفحه‌بندی |
| per_page | int | 50 | تعداد در صفحه |

### ۳.۴ خروجی

```php
[
    'account' => [
        'id' => 1,
        'code' => '110101',
        'title' => 'صندوق',
        'type' => 'asset',
        'nature' => 'debit',
    ],
    'period' => [
        'from' => '2024-01-01',
        'to' => '2024-03-31',
    ],
    'opening_balance' => 1000000,
    'transactions' => [
        [
            'date' => '2024-01-15',
            'document_id' => 5,
            'document_number' => 5,
            'document_type' => 'receipt',
            'description' => 'دریافت از مشتری',
            'reference' => 'RCP-001',
            'debit' => 500000,
            'credit' => 0,
            'balance' => 1500000,
        ],
        [
            'date' => '2024-01-20',
            'document_id' => 8,
            'document_number' => 8,
            'document_type' => 'payment',
            'description' => 'پرداخت هزینه',
            'reference' => null,
            'debit' => 0,
            'credit' => 200000,
            'balance' => 1300000,
        ],
        // ...
    ],
    'closing_balance' => 2500000,
    'totals' => [
        'debit' => 2000000,
        'credit' => 500000,
        'count' => 15,
    ],
]
```

### ۳.۵ مثال‌ها

**دفتر کل یک حساب:**

```php
$ledger = Accounting::report()->generalLedger(
    account: $cashAccount
);

echo "حساب: {$ledger['account']['title']}\n";
echo "مانده اول: {$ledger['opening_balance']}\n";
echo "مانده آخر: {$ledger['closing_balance']}\n";
echo "تعداد تراکنش: {$ledger['totals']['count']}\n";

foreach ($ledger['transactions'] as $tx) {
    echo "{$tx['date']} | سند {$tx['document_number']} | ";
    echo "بدهکار: {$tx['debit']} | بستانکار: {$tx['credit']} | ";
    echo "مانده: {$tx['balance']}\n";
}
```

**دفتر کل در بازه زمانی:**

```php
$ledger = Accounting::report()->generalLedger(
    account: $bankAccount,
    fromDate: Carbon::parse('2024-01-01'),
    toDate: Carbon::parse('2024-03-31')
);
```

**دفتر کل با صفحه‌بندی:**

```php
$ledger = Accounting::report()->generalLedger(
    account: $customerAccount,
    options: [
        'paginate' => true,
        'per_page' => 20,
    ]
);

// $ledger['transactions'] یک LengthAwarePaginator است
```

---

## ۴. دفتر معین (Subsidiary Ledger)

### ۴.۱ تعریف

دفتر کل برای حساب‌های زیرمجموعه یک حساب معین.

### ۴.۲ متد

```php
public function subsidiaryLedger(
    Account|int $parentAccount,
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null
): array
```

### ۴.۳ خروجی

```php
[
    'parent_account' => [
        'id' => 10,
        'code' => '1103',
        'title' => 'حساب‌های دریافتنی',
    ],
    'period' => [...],
    'accounts' => [
        [
            'account' => ['id' => 101, 'code' => '110301', 'title' => 'مشتری الف'],
            'opening_balance' => 500000,
            'debit' => 1000000,
            'credit' => 800000,
            'closing_balance' => 700000,
        ],
        [
            'account' => ['id' => 102, 'code' => '110302', 'title' => 'مشتری ب'],
            'opening_balance' => 0,
            'debit' => 2000000,
            'credit' => 1500000,
            'closing_balance' => 500000,
        ],
        // ...
    ],
    'totals' => [
        'opening_balance' => 500000,
        'debit' => 3000000,
        'credit' => 2300000,
        'closing_balance' => 1200000,
    ],
]
```

### ۴.۴ مثال

```php
$receivablesAccount = Accounting::account()->findByCode('1103');

$subsidiaryLedger = Accounting::report()->subsidiaryLedger(
    parentAccount: $receivablesAccount,
    fromDate: Carbon::parse('2024-01-01'),
    toDate: Carbon::parse('2024-03-31')
);

echo "دفتر معین: {$subsidiaryLedger['parent_account']['title']}\n\n";

foreach ($subsidiaryLedger['accounts'] as $row) {
    echo "{$row['account']['code']} - {$row['account']['title']}\n";
    echo "  اول دوره: {$row['opening_balance']}\n";
    echo "  گردش بدهکار: {$row['debit']}\n";
    echo "  گردش بستانکار: {$row['credit']}\n";
    echo "  مانده: {$row['closing_balance']}\n\n";
}
```

---

## ۵. گردش حساب (Account Statement)

### ۵.۱ تعریف

گزارش گردش حساب بدون محدودیت سال مالی. مناسب برای نمایش به مشتریان.

### ۵.۲ متد

```php
public function accountStatement(
    Account|int $account,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null,
    array $options = []
): array
```

### ۵.۳ خروجی

مشابه دفتر کل اما:
- بدون محدودیت سال مالی
- فرمت ساده‌تر برای نمایش

```php
[
    'account' => [...],
    'from_date' => '2024-01-01',
    'to_date' => '2024-03-31',
    'opening_balance' => 500000,
    'rows' => [
        [
            'date' => '2024-01-15',
            'document_number' => 5,
            'description' => 'فاکتور فروش #1001',
            'debit' => 1000000,
            'credit' => null,
            'balance' => 1500000,
        ],
        // ...
    ],
    'totals' => [
        'debit' => 3000000,
        'credit' => 2000000,
    ],
    'closing_balance' => 1500000,
]
```

### ۵.۴ مثال کاربردی

```php
// گردش حساب مشتری
$customer = User::find(1);

$statement = Accounting::report()->accountStatement(
    account: $customer->account,
    fromDate: Carbon::now()->subMonths(3),
    toDate: Carbon::now()
);

// ارسال به View یا PDF
return view('customer.statement', [
    'customer' => $customer,
    'statement' => $statement,
]);
```

---

## ۶. ترازنامه (Balance Sheet)

### ۶.۱ تعریف

صورت وضعیت مالی در یک تاریخ مشخص. نشان‌دهنده دارایی‌ها، بدهی‌ها و سرمایه.

### ۶.۲ متد

```php
public function balanceSheet(
    ?FiscalYear $fiscalYear = null,
    ?Carbon $asOfDate = null,
    ?int $branchId = null,
    array $options = []
): array
```

### ۶.۳ پارامترها

| پارامتر | نوع | پیش‌فرض | شرح |
|---------|-----|---------|-----|
| fiscalYear | FiscalYear | جاری | سال مالی |
| asOfDate | Carbon | امروز | تا تاریخ |
| branchId | int | همه | فیلتر شعبه |
| options | array | [] | تنظیمات |

**options:**

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| detail_level | int | 3 | سطح جزئیات (2 یا 3) |
| include_zero | bool | false | نمایش حساب‌های صفر |
| comparative | bool | false | مقایسه با دوره قبل |

### ۶.۴ خروجی

```php
[
    'as_of_date' => '2024-03-31',
    'fiscal_year' => 'سال مالی ۱۴۰۳',
    
    'assets' => [
        'current' => [
            'items' => [
                [
                    'code' => '1101',
                    'title' => 'موجودی نقد',
                    'amount' => 5000000,
                    'children' => [
                        ['code' => '110101', 'title' => 'صندوق', 'amount' => 2000000],
                        ['code' => '110102', 'title' => 'تنخواه', 'amount' => 3000000],
                    ],
                ],
                [
                    'code' => '1102',
                    'title' => 'بانک‌ها',
                    'amount' => 20000000,
                    'children' => [...],
                ],
                // ...
            ],
            'total' => 35000000,
        ],
        'non_current' => [
            'items' => [...],
            'total' => 15000000,
        ],
        'total' => 50000000,
    ],
    
    'liabilities' => [
        'current' => [
            'items' => [...],
            'total' => 10000000,
        ],
        'non_current' => [
            'items' => [...],
            'total' => 5000000,
        ],
        'total' => 15000000,
    ],
    
    'equity' => [
        'items' => [
            ['code' => '3101', 'title' => 'سرمایه', 'amount' => 30000000],
            ['code' => '3102', 'title' => 'سود انباشته', 'amount' => 5000000],
        ],
        'total' => 35000000,
    ],
    
    'totals' => [
        'assets' => 50000000,
        'liabilities' => 15000000,
        'equity' => 35000000,
        'liabilities_and_equity' => 50000000,
        'is_balanced' => true,
        'difference' => 0,
    ],
]
```

### ۶.۵ مثال‌ها

**ترازنامه ساده:**

```php
$balanceSheet = Accounting::report()->balanceSheet();

echo "ترازنامه در تاریخ {$balanceSheet['as_of_date']}\n\n";

echo "دارایی‌ها:\n";
echo "  جاری: {$balanceSheet['assets']['current']['total']}\n";
echo "  غیرجاری: {$balanceSheet['assets']['non_current']['total']}\n";
echo "  جمع: {$balanceSheet['assets']['total']}\n\n";

echo "بدهی‌ها:\n";
echo "  جاری: {$balanceSheet['liabilities']['current']['total']}\n";
echo "  غیرجاری: {$balanceSheet['liabilities']['non_current']['total']}\n";
echo "  جمع: {$balanceSheet['liabilities']['total']}\n\n";

echo "سرمایه: {$balanceSheet['equity']['total']}\n\n";

echo "بدهی + سرمایه: {$balanceSheet['totals']['liabilities_and_equity']}\n";
echo "تراز: " . ($balanceSheet['totals']['is_balanced'] ? 'بله' : 'خیر') . "\n";
```

**ترازنامه مقایسه‌ای:**

```php
$balanceSheet = Accounting::report()->balanceSheet(
    options: ['comparative' => true]
);

// خروجی شامل current_period و previous_period
```

**ترازنامه شعبه:**

```php
$balanceSheet = Accounting::report()->balanceSheet(
    branchId: 1
);
```

---

## ۷. صورت سود و زیان (Income Statement)

### ۷.۱ تعریف

عملکرد مالی در یک دوره. نشان‌دهنده درآمدها، هزینه‌ها و سود/زیان خالص.

### ۷.۲ متد

```php
public function incomeStatement(
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null,
    ?int $branchId = null,
    array $options = []
): array
```

### ۷.۳ پارامترها

| پارامتر | نوع | پیش‌فرض | شرح |
|---------|-----|---------|-----|
| fiscalYear | FiscalYear | جاری | سال مالی |
| fromDate | Carbon | شروع سال | از تاریخ |
| toDate | Carbon | امروز | تا تاریخ |
| branchId | int | همه | فیلتر شعبه |
| options | array | [] | تنظیمات |

**options:**

| کلید | نوع | پیش‌فرض | شرح |
|------|-----|---------|-----|
| detail_level | int | 3 | سطح جزئیات |
| include_zero | bool | false | نمایش حساب‌های صفر |
| comparative | bool | false | مقایسه با دوره قبل |
| show_percentage | bool | true | نمایش درصد |

### ۷.۴ خروجی

```php
[
    'period' => [
        'from' => '2024-01-01',
        'to' => '2024-03-31',
        'days' => 90,
    ],
    'fiscal_year' => 'سال مالی ۱۴۰۳',
    
    'income' => [
        'operating' => [
            'items' => [
                [
                    'code' => '4101',
                    'title' => 'درآمد فروش کالا',
                    'amount' => 50000000,
                    'percentage' => 83.33,
                ],
                [
                    'code' => '4102',
                    'title' => 'درآمد خدمات',
                    'amount' => 10000000,
                    'percentage' => 16.67,
                ],
            ],
            'total' => 60000000,
        ],
        'non_operating' => [
            'items' => [
                [
                    'code' => '4201',
                    'title' => 'سود بانکی',
                    'amount' => 500000,
                    'percentage' => 100,
                ],
            ],
            'total' => 500000,
        ],
        'total' => 60500000,
    ],
    
    'expenses' => [
        'cost_of_goods' => [
            'items' => [
                [
                    'code' => '5101',
                    'title' => 'بهای تمام شده کالا',
                    'amount' => 30000000,
                    'percentage' => 66.67,
                ],
            ],
            'total' => 30000000,
        ],
        'operating' => [
            'items' => [
                [
                    'code' => '5201',
                    'title' => 'هزینه حقوق',
                    'amount' => 10000000,
                    'percentage' => 50,
                ],
                [
                    'code' => '5202',
                    'title' => 'هزینه اجاره',
                    'amount' => 5000000,
                    'percentage' => 25,
                ],
                [
                    'code' => '5203',
                    'title' => 'هزینه تبلیغات',
                    'amount' => 5000000,
                    'percentage' => 25,
                ],
            ],
            'total' => 20000000,
        ],
        'non_operating' => [
            'items' => [...],
            'total' => 500000,
        ],
        'total' => 50500000,
    ],
    
    'summary' => [
        'gross_revenue' => 60500000,
        'cost_of_goods' => 30000000,
        'gross_profit' => 30500000,
        'gross_margin' => 50.41,
        'operating_expenses' => 20000000,
        'operating_profit' => 10500000,
        'operating_margin' => 17.36,
        'non_operating_income' => 500000,
        'non_operating_expenses' => 500000,
        'net_profit' => 10500000,
        'net_margin' => 17.36,
    ],
]
```

### ۷.۵ مثال‌ها

**سود و زیان ساده:**

```php
$incomeStatement = Accounting::report()->incomeStatement();

echo "صورت سود و زیان\n";
echo "دوره: {$incomeStatement['period']['from']} تا {$incomeStatement['period']['to']}\n\n";

echo "درآمدها: {$incomeStatement['income']['total']}\n";
echo "هزینه‌ها: {$incomeStatement['expenses']['total']}\n";
echo "سود خالص: {$incomeStatement['summary']['net_profit']}\n";
echo "حاشیه سود: {$incomeStatement['summary']['net_margin']}%\n";
```

**سود و زیان ماهانه:**

```php
$incomeStatement = Accounting::report()->incomeStatement(
    fromDate: Carbon::now()->startOfMonth(),
    toDate: Carbon::now()->endOfMonth()
);
```

**سود و زیان مقایسه‌ای:**

```php
$incomeStatement = Accounting::report()->incomeStatement(
    options: ['comparative' => true]
);

// مقایسه با دوره مشابه سال قبل
```

---

## ۸. گزارش مرکز هزینه (Cost Center Report)

### ۸.۱ تعریف

تحلیل هزینه‌ها و درآمدها بر اساس مرکز هزینه (پروژه/بخش).

### ۸.۲ متد

```php
public function costCenterReport(
    CostCenter|int|null $costCenter = null,
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null,
    array $options = []
): array
```

### ۸.۳ خروجی (برای یک مرکز هزینه)

```php
[
    'cost_center' => [
        'id' => 1,
        'code' => 'CC001',
        'title' => 'پروژه توسعه اپ موبایل',
    ],
    'period' => [...],
    
    'income' => [
        'items' => [
            ['account' => '4102', 'title' => 'درآمد خدمات', 'amount' => 50000000],
        ],
        'total' => 50000000,
    ],
    
    'expenses' => [
        'items' => [
            ['account' => '5201', 'title' => 'هزینه حقوق', 'amount' => 20000000],
            ['account' => '5203', 'title' => 'هزینه سفر', 'amount' => 5000000],
            ['account' => '5204', 'title' => 'هزینه نرم‌افزار', 'amount' => 10000000],
        ],
        'total' => 35000000,
    ],
    
    'profit' => 15000000,
    'margin' => 30.0,
]
```

### ۸.۴ خروجی (همه مراکز هزینه)

```php
[
    'period' => [...],
    
    'cost_centers' => [
        [
            'cost_center' => ['id' => 1, 'code' => 'CC001', 'title' => 'پروژه الف'],
            'income' => 50000000,
            'expenses' => 35000000,
            'profit' => 15000000,
            'margin' => 30.0,
        ],
        [
            'cost_center' => ['id' => 2, 'code' => 'CC002', 'title' => 'پروژه ب'],
            'income' => 30000000,
            'expenses' => 25000000,
            'profit' => 5000000,
            'margin' => 16.67,
        ],
        // ...
    ],
    
    'totals' => [
        'income' => 80000000,
        'expenses' => 60000000,
        'profit' => 20000000,
    ],
    
    'unallocated' => [
        'income' => 10000000,
        'expenses' => 5000000,
        'profit' => 5000000,
    ],
]
```

### ۸.۵ مثال

```php
// گزارش همه مراکز هزینه
$report = Accounting::report()->costCenterReport();

echo "مقایسه مراکز هزینه:\n\n";

foreach ($report['cost_centers'] as $cc) {
    echo "{$cc['cost_center']['title']}:\n";
    echo "  درآمد: {$cc['income']}\n";
    echo "  هزینه: {$cc['expenses']}\n";
    echo "  سود: {$cc['profit']} ({$cc['margin']}%)\n\n";
}

// گزارش یک مرکز هزینه
$projectReport = Accounting::report()->costCenterReport(
    costCenter: $projectA
);
```

---

## ۹. گزارش شعبه (Branch Report)

### ۹.۱ تعریف

عملکرد مالی هر شعبه به صورت جداگانه و مقایسه‌ای.

### ۹.۲ متد

```php
public function branchReport(
    Branch|int|null $branch = null,
    ?FiscalYear $fiscalYear = null,
    ?Carbon $fromDate = null,
    ?Carbon $toDate = null
): array
```

### ۹.۳ خروجی (همه شعب)

```php
[
    'period' => [...],
    
    'branches' => [
        [
            'branch' => ['id' => 1, 'code' => 'HQ', 'title' => 'دفتر مرکزی'],
            'documents_count' => 150,
            'income' => 100000000,
            'expenses' => 70000000,
            'profit' => 30000000,
            'assets' => 50000000,
            'liabilities' => 20000000,
        ],
        [
            'branch' => ['id' => 2, 'code' => 'BR01', 'title' => 'شعبه تهران'],
            'documents_count' => 80,
            'income' => 60000000,
            'expenses' => 45000000,
            'profit' => 15000000,
            'assets' => 30000000,
            'liabilities' => 10000000,
        ],
        // ...
    ],
    
    'totals' => [
        'documents_count' => 230,
        'income' => 160000000,
        'expenses' => 115000000,
        'profit' => 45000000,
        'assets' => 80000000,
        'liabilities' => 30000000,
    ],
]
```

### ۹.۴ مثال

```php
$branchReport = Accounting::report()->branchReport();

echo "مقایسه شعب:\n\n";

foreach ($branchReport['branches'] as $branch) {
    $profitMargin = $branch['income'] > 0 
        ? round($branch['profit'] / $branch['income'] * 100, 2) 
        : 0;
    
    echo "{$branch['branch']['title']}:\n";
    echo "  تعداد سند: {$branch['documents_count']}\n";
    echo "  درآمد: {$branch['income']}\n";
    echo "  سود: {$branch['profit']} ({$profitMargin}%)\n\n";
}
```

---

## ۱۰. گزارش مقایسه‌ای دوره‌ای

### ۱۰.۱ متد

```php
public function periodComparison(
    string $reportType,
    array $periods,
    ?int $branchId = null
): array
```

### ۱۰.۲ پارامترها

| پارامتر | نوع | شرح |
|---------|-----|-----|
| reportType | string | نوع گزارش (income, expense, balance) |
| periods | array | لیست دوره‌ها |
| branchId | int | فیلتر شعبه |

### ۱۰.۳ مثال

```php
$comparison = Accounting::report()->periodComparison(
    reportType: 'income',
    periods: [
        ['from' => '2024-01-01', 'to' => '2024-01-31', 'label' => 'فروردین'],
        ['from' => '2024-02-01', 'to' => '2024-02-29', 'label' => 'اردیبهشت'],
        ['from' => '2024-03-01', 'to' => '2024-03-31', 'label' => 'خرداد'],
    ]
);

// خروجی:
// [
//     'فروردین' => 10000000,
//     'اردیبهشت' => 12000000,
//     'خرداد' => 15000000,
// ]
```

---

## ۱۱. گزارش خلاصه روزانه

### ۱۱.۱ متد

```php
public function dailySummary(
    ?Carbon $date = null,
    ?int $branchId = null
): array
```

### ۱۱.۲ خروجی

```php
[
    'date' => '2024-03-15',
    
    'documents' => [
        'total' => 15,
        'by_type' => [
            'sale' => 8,
            'receipt' => 5,
            'payment' => 2,
        ],
        'by_status' => [
            'posted' => 12,
            'draft' => 3,
        ],
    ],
    
    'cash_flow' => [
        'receipts' => 5000000,
        'payments' => 2000000,
        'net' => 3000000,
    ],
    
    'sales' => [
        'count' => 8,
        'amount' => 8000000,
    ],
    
    'balances' => [
        'cash' => 10000000,
        'bank' => 50000000,
        'receivables' => 15000000,
        'payables' => 5000000,
    ],
]
```

### ۱۱.۳ مثال

```php
$today = Accounting::report()->dailySummary();

echo "خلاصه امروز ({$today['date']}):\n";
echo "تعداد سند: {$today['documents']['total']}\n";
echo "فروش: {$today['sales']['amount']}\n";
echo "جریان نقدی: {$today['cash_flow']['net']}\n";
```

---

## ۱۲. گزارش موجودی حساب‌ها

### ۱۲.۱ متد

```php
public function accountBalances(
    string $type,
    ?Carbon $asOfDate = null,
    ?int $branchId = null
): Collection
```

### ۱۲.۲ مثال‌ها

**موجودی بانک‌ها:**

```php
$bankBalances = Accounting::report()->accountBalances(
    type: 'bank',
    asOfDate: now()
);

foreach ($bankBalances as $bank) {
    echo "{$bank['title']}: {$bank['balance']}\n";
}
```

**مانده بدهکاران:**

```php
$receivables = Accounting::report()->accountBalances(
    type: 'receivable'
);
```

**مانده بستانکاران:**

```php
$payables = Accounting::report()->accountBalances(
    type: 'payable'
);
```

---

## ۱۳. خروجی گزارش‌ها

### ۱۳.۱ فرمت‌های خروجی

```php
// آرایه (پیش‌فرض)
$report = Accounting::report()->trialBalance();

// Collection
$report = Accounting::report()->trialBalance()->toCollection();

// JSON
$report = Accounting::report()->trialBalance()->toJson();

// CSV
$report = Accounting::report()->trialBalance()->toCsv();

// Excel (نیاز به پکیج اضافی)
$report = Accounting::report()->trialBalance()->toExcel();

// PDF (نیاز به پکیج اضافی)
$report = Accounting::report()->trialBalance()->toPdf();
```

### ۱۳.۲ مثال ذخیره فایل

```php
$report = Accounting::report()->trialBalance();

// ذخیره CSV
Storage::put('reports/trial-balance.csv', $report->toCsv());

// ذخیره JSON
Storage::put('reports/trial-balance.json', $report->toJson());
```

---

## ۱۴. کش کردن گزارش‌ها

### ۱۴.۱ کش خودکار

در `config/accounting.php`:

```php
'reports' => [
    'cache_reports' => true,
    'cache_ttl' => 300,  // 5 دقیقه
],
```

### ۱۴.۲ کش دستی

```php
// با کش
$report = Accounting::report()
    ->cache(300)  // 5 دقیقه
    ->trialBalance();

// بدون کش (فقط این درخواست)
$report = Accounting::report()
    ->noCache()
    ->trialBalance();

// پاکسازی کش
Accounting::report()->clearCache();
Accounting::report()->clearCache('trialBalance');
```

---

## ۱۵. نکات عملکردی

### ۱۵.۱ گزارش‌های سنگین

برای گزارش‌های با داده زیاد:

```php
// صفحه‌بندی
$report = Accounting::report()->generalLedger(
    account: $account,
    options: ['paginate' => true, 'per_page' => 100]
);

// محدود کردن بازه
$report = Accounting::report()->trialBalance(
    options: ['level' => 2]  // فقط تا سطح معین
);
```

### ۱۵.۲ گزارش‌های زمان‌بندی شده

```php
// در Job
class GenerateMonthlyReports implements ShouldQueue
{
    public function handle()
    {
        $report = Accounting::report()->incomeStatement(
            fromDate: now()->subMonth()->startOfMonth(),
            toDate: now()->subMonth()->endOfMonth()
        );
        
        // ذخیره یا ارسال
        Storage::put("reports/monthly/{$this->month}.json", json_encode($report));
    }
}
```

---

[→ ادامه: چند شعبه‌ای (10-multi-branch.md)](10-multi-branch.md)

[← بازگشت: مرجع API (08-api-reference.md)](08-api-reference.md)

[⌂ فهرست (00-index.md)](00-index.md)
