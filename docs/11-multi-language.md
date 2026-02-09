# 11-multi-language.md

# چندزبانگی

## Multi-Language

---

## مقدمه

این بخش نحوه پیکربندی و استفاده از قابلیت چندزبانگی پکیج حسابداری را شرح می‌دهد. پکیج از سیستم ترجمه Laravel استفاده می‌کند.

---

## ۱. ساختار فایل‌های زبان

### ۱.۱ مسیر فایل‌ها در پکیج

```
vendor/your-vendor/laravel-accounting/
└── lang/
    ├── en/
    │   └── accounting.php
    └── fa/
        └── accounting.php
```

### ۱.۲ مسیر فایل‌ها پس از Publish

```
lang/
└── vendor/
    └── accounting/
        ├── en/
        │   └── accounting.php
        └── fa/
            └── accounting.php
```

### ۱.۳ اولویت بارگذاری

| اولویت | مسیر | شرح |
|--------|------|-----|
| ۱ | lang/vendor/accounting/{locale} | فایل‌های Publish شده پروژه |
| ۲ | vendor/.../lang/{locale} | فایل‌های پیش‌فرض پکیج |

---

## ۲. انتشار فایل‌های زبان

### ۲.۱ انتشار همه زبان‌ها

```bash
php artisan vendor:publish --tag="accounting-lang"
```

### ۲.۲ انتشار زبان خاص

```bash
php artisan vendor:publish --tag="accounting-lang-fa"
php artisan vendor:publish --tag="accounting-lang-en"
```

### ۲.۳ بازنویسی فایل‌های موجود

```bash
php artisan vendor:publish --tag="accounting-lang" --force
```

---

## ۳. ساختار فایل ترجمه

### ۳.۱ فایل فارسی (fa/accounting.php)

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | عمومی
    |--------------------------------------------------------------------------
    */
    'general' => [
        'accounting' => 'حسابداری',
        'account' => 'حساب',
        'accounts' => 'حساب‌ها',
        'document' => 'سند',
        'documents' => 'اسناد',
        'fiscal_year' => 'سال مالی',
        'branch' => 'شعبه',
        'branches' => 'شعب',
        'cost_center' => 'مرکز هزینه',
        'balance' => 'مانده',
        'debit' => 'بدهکار',
        'credit' => 'بستانکار',
        'amount' => 'مبلغ',
        'date' => 'تاریخ',
        'description' => 'توضیحات',
        'status' => 'وضعیت',
        'type' => 'نوع',
        'code' => 'کد',
        'title' => 'عنوان',
        'total' => 'جمع',
        'actions' => 'عملیات',
    ],

    /*
    |--------------------------------------------------------------------------
    | انواع حساب
    |--------------------------------------------------------------------------
    */
    'account_types' => [
        'asset' => 'دارایی',
        'liability' => 'بدهی',
        'equity' => 'سرمایه',
        'income' => 'درآمد',
        'expense' => 'هزینه',
    ],

    /*
    |--------------------------------------------------------------------------
    | ماهیت حساب
    |--------------------------------------------------------------------------
    */
    'account_natures' => [
        'debit' => 'بدهکار',
        'credit' => 'بستانکار',
    ],

    /*
    |--------------------------------------------------------------------------
    | سطوح حساب
    |--------------------------------------------------------------------------
    */
    'account_levels' => [
        0 => 'گروه',
        1 => 'کل',
        2 => 'معین',
        3 => 'تفصیلی',
        'group' => 'گروه',
        'main' => 'کل',
        'subsidiary' => 'معین',
        'detail' => 'تفصیلی',
    ],

    /*
    |--------------------------------------------------------------------------
    | وضعیت سند
    |--------------------------------------------------------------------------
    */
    'document_statuses' => [
        'draft' => 'پیش‌نویس',
        'pending' => 'در انتظار تأیید',
        'approved' => 'تأیید شده',
        'posted' => 'ثبت شده',
        'voided' => 'باطل شده',
    ],

    /*
    |--------------------------------------------------------------------------
    | انواع سند
    |--------------------------------------------------------------------------
    */
    'document_types' => [
        'sale' => 'فروش',
        'purchase' => 'خرید',
        'receipt' => 'دریافت',
        'payment' => 'پرداخت',
        'transfer' => 'انتقال',
        'opening' => 'افتتاحیه',
        'closing' => 'اختتامیه',
        'adjustment' => 'تعدیل',
    ],

    /*
    |--------------------------------------------------------------------------
    | وضعیت سال مالی
    |--------------------------------------------------------------------------
    */
    'fiscal_year_statuses' => [
        'draft' => 'پیش‌نویس',
        'active' => 'فعال',
        'closed' => 'بسته',
    ],

    /*
    |--------------------------------------------------------------------------
    | عملیات Audit
    |--------------------------------------------------------------------------
    */
    'audit_actions' => [
        'created' => 'ایجاد شد',
        'updated' => 'ویرایش شد',
        'submitted' => 'ارسال شد',
        'approved' => 'تأیید شد',
        'rejected' => 'رد شد',
        'posted' => 'ثبت شد',
        'voided' => 'باطل شد',
        'restored' => 'بازیابی شد',
    ],

    /*
    |--------------------------------------------------------------------------
    | گزارش‌ها
    |--------------------------------------------------------------------------
    */
    'reports' => [
        'trial_balance' => 'تراز آزمایشی',
        'general_ledger' => 'دفتر کل',
        'subsidiary_ledger' => 'دفتر معین',
        'account_statement' => 'گردش حساب',
        'balance_sheet' => 'ترازنامه',
        'income_statement' => 'صورت سود و زیان',
        'cash_flow' => 'جریان وجوه نقد',
        'cost_center_report' => 'گزارش مرکز هزینه',
        'branch_report' => 'گزارش شعبه',
        'daily_summary' => 'خلاصه روزانه',
        
        // عناوین ستون‌ها
        'columns' => [
            'code' => 'کد',
            'title' => 'عنوان',
            'debit' => 'بدهکار',
            'credit' => 'بستانکار',
            'balance' => 'مانده',
            'opening_balance' => 'مانده اول',
            'closing_balance' => 'مانده آخر',
            'period_debit' => 'گردش بدهکار',
            'period_credit' => 'گردش بستانکار',
        ],
        
        // بخش‌های ترازنامه
        'balance_sheet_sections' => [
            'assets' => 'دارایی‌ها',
            'current_assets' => 'دارایی جاری',
            'non_current_assets' => 'دارایی غیرجاری',
            'liabilities' => 'بدهی‌ها',
            'current_liabilities' => 'بدهی جاری',
            'non_current_liabilities' => 'بدهی غیرجاری',
            'equity' => 'حقوق صاحبان سهام',
            'total_assets' => 'جمع دارایی‌ها',
            'total_liabilities' => 'جمع بدهی‌ها',
            'total_equity' => 'جمع سرمایه',
        ],
        
        // بخش‌های سود و زیان
        'income_statement_sections' => [
            'revenue' => 'درآمدها',
            'operating_revenue' => 'درآمد عملیاتی',
            'non_operating_revenue' => 'درآمد غیرعملیاتی',
            'expenses' => 'هزینه‌ها',
            'cost_of_goods' => 'بهای تمام شده',
            'operating_expenses' => 'هزینه‌های عملیاتی',
            'non_operating_expenses' => 'هزینه‌های غیرعملیاتی',
            'gross_profit' => 'سود ناخالص',
            'operating_profit' => 'سود عملیاتی',
            'net_profit' => 'سود خالص',
            'net_loss' => 'زیان خالص',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | حساب‌های پیش‌فرض
    |--------------------------------------------------------------------------
    */
    'default_accounts' => [
        // گروه
        'assets' => 'دارایی‌ها',
        'liabilities' => 'بدهی‌ها',
        'equity' => 'حقوق صاحبان سهام',
        'income' => 'درآمدها',
        'expenses' => 'هزینه‌ها',
        
        // کل
        'current_assets' => 'دارایی جاری',
        'non_current_assets' => 'دارایی غیرجاری',
        'current_liabilities' => 'بدهی جاری',
        'non_current_liabilities' => 'بدهی غیرجاری',
        'capital' => 'سرمایه',
        'operating_income' => 'درآمد عملیاتی',
        'non_operating_income' => 'درآمد غیرعملیاتی',
        'operating_expenses' => 'هزینه‌های عملیاتی',
        'non_operating_expenses' => 'هزینه‌های غیرعملیاتی',
        
        // معین
        'cash' => 'موجودی نقد',
        'banks' => 'بانک‌ها',
        'receivables' => 'حساب‌های دریافتنی',
        'inventory' => 'موجودی کالا',
        'fixed_assets' => 'دارایی ثابت',
        'payables' => 'حساب‌های پرداختنی',
        'loans' => 'تسهیلات',
        'owner_capital' => 'سرمایه مالک',
        'retained_earnings' => 'سود انباشته',
        'sales_revenue' => 'درآمد فروش',
        'service_revenue' => 'درآمد خدمات',
        'cost_of_goods_sold' => 'بهای تمام شده کالای فروش رفته',
        'salary_expense' => 'هزینه حقوق',
        'rent_expense' => 'هزینه اجاره',
        
        // تفصیلی
        'main_cashier' => 'صندوق اصلی',
        'petty_cash' => 'تنخواه‌گردان',
        'main_bank' => 'بانک اصلی',
    ],

    /*
    |--------------------------------------------------------------------------
    | پیام‌های سیستم
    |--------------------------------------------------------------------------
    */
    'messages' => [
        // موفقیت
        'document_created' => 'سند با موفقیت ایجاد شد.',
        'document_updated' => 'سند با موفقیت ویرایش شد.',
        'document_posted' => 'سند با موفقیت ثبت شد.',
        'document_voided' => 'سند با موفقیت باطل شد.',
        'account_created' => 'حساب با موفقیت ایجاد شد.',
        'account_updated' => 'حساب با موفقیت ویرایش شد.',
        'fiscal_year_created' => 'سال مالی با موفقیت ایجاد شد.',
        'fiscal_year_closed' => 'سال مالی با موفقیت بسته شد.',
        'balance_refreshed' => 'مانده حساب‌ها بروزرسانی شد.',
        
        // هشدار
        'document_not_balanced' => 'سند بالانس نیست. مجموع بدهکار و بستانکار باید برابر باشد.',
        'abnormal_balance' => 'مانده غیرطبیعی: :account',
        'low_balance' => 'موجودی :account کم است.',
        
        // خطا
        'fiscal_year_closed_error' => 'سال مالی بسته است. امکان ثبت سند وجود ندارد.',
        'account_inactive' => 'حساب غیرفعال است.',
        'account_not_postable' => 'امکان ثبت مستقیم در این حساب وجود ندارد.',
        'document_not_editable' => 'سند قابل ویرایش نیست.',
        'document_not_deletable' => 'سند قابل حذف نیست.',
        'insufficient_balance' => 'موجودی کافی نیست.',
        'invalid_date_range' => 'بازه تاریخی نامعتبر است.',
        'system_account_protected' => 'حساب سیستمی قابل تغییر نیست.',
    ],

    /*
    |--------------------------------------------------------------------------
    | اعتبارسنجی
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'amount_required' => 'مبلغ الزامی است.',
        'amount_positive' => 'مبلغ باید بزرگ‌تر از صفر باشد.',
        'amount_max' => 'مبلغ نمی‌تواند بیشتر از :max باشد.',
        'account_required' => 'حساب الزامی است.',
        'account_invalid' => 'حساب نامعتبر است.',
        'date_required' => 'تاریخ الزامی است.',
        'date_invalid' => 'تاریخ نامعتبر است.',
        'date_out_of_fiscal_year' => 'تاریخ خارج از سال مالی است.',
        'type_required' => 'نوع سند الزامی است.',
        'type_invalid' => 'نوع سند نامعتبر است.',
        'items_required' => 'حداقل :min ردیف الزامی است.',
        'document_not_balanced' => 'مجموع بدهکار (:debit) با مجموع بستانکار (:credit) برابر نیست.',
    ],

    /*
    |--------------------------------------------------------------------------
    | واحدها و فرمت
    |--------------------------------------------------------------------------
    */
    'format' => [
        'currency' => 'ریال',
        'currency_symbol' => 'ریال',
        'date_format' => 'Y/m/d',
        'datetime_format' => 'Y/m/d H:i',
        'number_format' => [
            'decimals' => 0,
            'decimal_separator' => '.',
            'thousand_separator' => ',',
        ],
    ],

];
```

### ۳.۲ فایل انگلیسی (en/accounting.php)

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | General
    |--------------------------------------------------------------------------
    */
    'general' => [
        'accounting' => 'Accounting',
        'account' => 'Account',
        'accounts' => 'Accounts',
        'document' => 'Document',
        'documents' => 'Documents',
        'fiscal_year' => 'Fiscal Year',
        'branch' => 'Branch',
        'branches' => 'Branches',
        'cost_center' => 'Cost Center',
        'balance' => 'Balance',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'amount' => 'Amount',
        'date' => 'Date',
        'description' => 'Description',
        'status' => 'Status',
        'type' => 'Type',
        'code' => 'Code',
        'title' => 'Title',
        'total' => 'Total',
        'actions' => 'Actions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Types
    |--------------------------------------------------------------------------
    */
    'account_types' => [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'income' => 'Income',
        'expense' => 'Expense',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Natures
    |--------------------------------------------------------------------------
    */
    'account_natures' => [
        'debit' => 'Debit',
        'credit' => 'Credit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Levels
    |--------------------------------------------------------------------------
    */
    'account_levels' => [
        0 => 'Group',
        1 => 'Main',
        2 => 'Subsidiary',
        3 => 'Detail',
        'group' => 'Group',
        'main' => 'Main',
        'subsidiary' => 'Subsidiary',
        'detail' => 'Detail',
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Statuses
    |--------------------------------------------------------------------------
    */
    'document_statuses' => [
        'draft' => 'Draft',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'posted' => 'Posted',
        'voided' => 'Voided',
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Types
    |--------------------------------------------------------------------------
    */
    'document_types' => [
        'sale' => 'Sale',
        'purchase' => 'Purchase',
        'receipt' => 'Receipt',
        'payment' => 'Payment',
        'transfer' => 'Transfer',
        'opening' => 'Opening',
        'closing' => 'Closing',
        'adjustment' => 'Adjustment',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscal Year Statuses
    |--------------------------------------------------------------------------
    */
    'fiscal_year_statuses' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'closed' => 'Closed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Actions
    |--------------------------------------------------------------------------
    */
    'audit_actions' => [
        'created' => 'Created',
        'updated' => 'Updated',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'posted' => 'Posted',
        'voided' => 'Voided',
        'restored' => 'Restored',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */
    'reports' => [
        'trial_balance' => 'Trial Balance',
        'general_ledger' => 'General Ledger',
        'subsidiary_ledger' => 'Subsidiary Ledger',
        'account_statement' => 'Account Statement',
        'balance_sheet' => 'Balance Sheet',
        'income_statement' => 'Income Statement',
        'cash_flow' => 'Cash Flow Statement',
        'cost_center_report' => 'Cost Center Report',
        'branch_report' => 'Branch Report',
        'daily_summary' => 'Daily Summary',
        
        'columns' => [
            'code' => 'Code',
            'title' => 'Title',
            'debit' => 'Debit',
            'credit' => 'Credit',
            'balance' => 'Balance',
            'opening_balance' => 'Opening Balance',
            'closing_balance' => 'Closing Balance',
            'period_debit' => 'Period Debit',
            'period_credit' => 'Period Credit',
        ],
        
        'balance_sheet_sections' => [
            'assets' => 'Assets',
            'current_assets' => 'Current Assets',
            'non_current_assets' => 'Non-Current Assets',
            'liabilities' => 'Liabilities',
            'current_liabilities' => 'Current Liabilities',
            'non_current_liabilities' => 'Non-Current Liabilities',
            'equity' => 'Equity',
            'total_assets' => 'Total Assets',
            'total_liabilities' => 'Total Liabilities',
            'total_equity' => 'Total Equity',
        ],
        
        'income_statement_sections' => [
            'revenue' => 'Revenue',
            'operating_revenue' => 'Operating Revenue',
            'non_operating_revenue' => 'Non-Operating Revenue',
            'expenses' => 'Expenses',
            'cost_of_goods' => 'Cost of Goods Sold',
            'operating_expenses' => 'Operating Expenses',
            'non_operating_expenses' => 'Non-Operating Expenses',
            'gross_profit' => 'Gross Profit',
            'operating_profit' => 'Operating Profit',
            'net_profit' => 'Net Profit',
            'net_loss' => 'Net Loss',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Accounts
    |--------------------------------------------------------------------------
    */
    'default_accounts' => [
        'assets' => 'Assets',
        'liabilities' => 'Liabilities',
        'equity' => 'Equity',
        'income' => 'Income',
        'expenses' => 'Expenses',
        
        'current_assets' => 'Current Assets',
        'non_current_assets' => 'Non-Current Assets',
        'current_liabilities' => 'Current Liabilities',
        'non_current_liabilities' => 'Non-Current Liabilities',
        'capital' => 'Capital',
        'operating_income' => 'Operating Income',
        'non_operating_income' => 'Non-Operating Income',
        'operating_expenses' => 'Operating Expenses',
        'non_operating_expenses' => 'Non-Operating Expenses',
        
        'cash' => 'Cash',
        'banks' => 'Banks',
        'receivables' => 'Accounts Receivable',
        'inventory' => 'Inventory',
        'fixed_assets' => 'Fixed Assets',
        'payables' => 'Accounts Payable',
        'loans' => 'Loans',
        'owner_capital' => 'Owner Capital',
        'retained_earnings' => 'Retained Earnings',
        'sales_revenue' => 'Sales Revenue',
        'service_revenue' => 'Service Revenue',
        'cost_of_goods_sold' => 'Cost of Goods Sold',
        'salary_expense' => 'Salary Expense',
        'rent_expense' => 'Rent Expense',
        
        'main_cashier' => 'Main Cashier',
        'petty_cash' => 'Petty Cash',
        'main_bank' => 'Main Bank',
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */
    'messages' => [
        'document_created' => 'Document created successfully.',
        'document_updated' => 'Document updated successfully.',
        'document_posted' => 'Document posted successfully.',
        'document_voided' => 'Document voided successfully.',
        'account_created' => 'Account created successfully.',
        'account_updated' => 'Account updated successfully.',
        'fiscal_year_created' => 'Fiscal year created successfully.',
        'fiscal_year_closed' => 'Fiscal year closed successfully.',
        'balance_refreshed' => 'Account balances refreshed.',
        
        'document_not_balanced' => 'Document is not balanced. Total debit must equal total credit.',
        'abnormal_balance' => 'Abnormal balance: :account',
        'low_balance' => ':account balance is low.',
        
        'fiscal_year_closed_error' => 'Fiscal year is closed. Cannot post document.',
        'account_inactive' => 'Account is inactive.',
        'account_not_postable' => 'Cannot post directly to this account.',
        'document_not_editable' => 'Document is not editable.',
        'document_not_deletable' => 'Document cannot be deleted.',
        'insufficient_balance' => 'Insufficient balance.',
        'invalid_date_range' => 'Invalid date range.',
        'system_account_protected' => 'System account cannot be modified.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'amount_required' => 'Amount is required.',
        'amount_positive' => 'Amount must be greater than zero.',
        'amount_max' => 'Amount cannot exceed :max.',
        'account_required' => 'Account is required.',
        'account_invalid' => 'Invalid account.',
        'date_required' => 'Date is required.',
        'date_invalid' => 'Invalid date.',
        'date_out_of_fiscal_year' => 'Date is outside fiscal year.',
        'type_required' => 'Document type is required.',
        'type_invalid' => 'Invalid document type.',
        'items_required' => 'At least :min items are required.',
        'document_not_balanced' => 'Total debit (:debit) does not equal total credit (:credit).',
    ],

    /*
    |--------------------------------------------------------------------------
    | Format
    |--------------------------------------------------------------------------
    */
    'format' => [
        'currency' => 'USD',
        'currency_symbol' => '$',
        'date_format' => 'Y-m-d',
        'datetime_format' => 'Y-m-d H:i',
        'number_format' => [
            'decimals' => 2,
            'decimal_separator' => '.',
            'thousand_separator' => ',',
        ],
    ],

];
```

---

## ۴. استفاده از ترجمه‌ها

### ۴.۱ در کد PHP

```php
// ترجمه ساده
$label = __('accounting::accounting.general.account');

// ترجمه با پارامتر
$message = __('accounting::accounting.messages.abnormal_balance', [
    'account' => $account->title,
]);

// ترجمه با Fallback
$label = __('accounting::accounting.custom.key', [], 'fa') 
    ?: __('accounting::accounting.custom.key', [], 'en');
```

### ۴.۲ در Blade

```html
<!-- ترجمه ساده -->
<th>{{ __('accounting::accounting.general.debit') }}</th>
<th>{{ __('accounting::accounting.general.credit') }}</th>

<!-- ترجمه با پارامتر -->
<p>{{ __('accounting::accounting.messages.low_balance', ['account' => $account->title]) }}</p>

<!-- استفاده از trans -->
<h1>@lang('accounting::accounting.reports.trial_balance')</h1>
```

### ۴.۳ Helper سفارشی

```php
// در پکیج
function accounting_trans(string $key, array $replace = [], ?string $locale = null): string
{
    return __("accounting::accounting.{$key}", $replace, $locale);
}

// استفاده
$label = accounting_trans('general.account');
$message = accounting_trans('messages.document_posted');
```

---

## ۵. ترجمه عناوین حساب

### ۵.۱ استفاده از کلید ترجمه در Seeder

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use YourVendor\Accounting\Models\Account;

class AccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'code' => '1',
                'title_key' => 'default_accounts.assets',  // کلید ترجمه
                'level' => 0,
                'type' => 'asset',
            ],
            [
                'code' => '11',
                'title_key' => 'default_accounts.current_assets',
                'level' => 1,
                'type' => 'asset',
                'parent_code' => '1',
            ],
            [
                'code' => '1101',
                'title_key' => 'default_accounts.cash',
                'level' => 2,
                'type' => 'asset',
                'parent_code' => '11',
            ],
            // ...
        ];
        
        foreach ($accounts as $data) {
            $parentId = null;
            if (isset($data['parent_code'])) {
                $parent = Account::where('code', $data['parent_code'])->first();
                $parentId = $parent?->id;
            }
            
            Account::create([
                'code' => $data['code'],
                'title' => __("accounting::accounting.{$data['title_key']}"),
                'level' => $data['level'],
                'type' => $data['type'],
                'nature' => $data['type'] === 'asset' || $data['type'] === 'expense' ? 'debit' : 'credit',
                'parent_id' => $parentId,
            ]);
        }
    }
}
```

### ۵.۲ جدول ترجمه حساب‌ها (روش جایگزین)

برای پروژه‌های چندزبانه بزرگ:

```php
// Migration
Schema::create('account_translations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('account_id')->constrained()->cascadeOnDelete();
    $table->string('locale', 5);
    $table->string('title');
    $table->string('description')->nullable();
    $table->timestamps();
    
    $table->unique(['account_id', 'locale']);
});
```

```php
// Model
class Account extends Model
{
    public function translations()
    {
        return $this->hasMany(AccountTranslation::class);
    }
    
    public function getTranslatedTitleAttribute(): string
    {
        $locale = app()->getLocale();
        
        $translation = $this->translations
            ->where('locale', $locale)
            ->first();
        
        return $translation?->title ?? $this->title;
    }
}
```

---

## ۶. تغییر زبان

### ۶.۱ تنظیم زبان پیش‌فرض

در `config/app.php`:

```php
'locale' => 'fa',
'fallback_locale' => 'en',
```

### ۶.۲ تغییر زبان در Runtime

```php
// در Controller
app()->setLocale('en');

// یا با Middleware
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('Accept-Language', 'fa');
        
        if (in_array($locale, ['fa', 'en'])) {
            app()->setLocale($locale);
        }
        
        return $next($request);
    }
}
```

### ۶.۳ ذخیره زبان کاربر

```php
// در User Model
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'locale'];
}

// Middleware
class SetUserLocale
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check() && auth()->user()->locale) {
            app()->setLocale(auth()->user()->locale);
        }
        
        return $next($request);
    }
}
```

---

## ۷. افزودن زبان جدید

### ۷.۱ ایجاد فایل زبان

```bash
# ایجاد پوشه
mkdir -p lang/vendor/accounting/ar

# کپی از فایل موجود
cp lang/vendor/accounting/en/accounting.php lang/vendor/accounting/ar/accounting.php
```

### ۷.۲ ترجمه فایل

```php
<?php
// lang/vendor/accounting/ar/accounting.php

return [
    'general' => [
        'accounting' => 'المحاسبة',
        'account' => 'حساب',
        'accounts' => 'الحسابات',
        'document' => 'مستند',
        'documents' => 'المستندات',
        // ...
    ],
    // ...
];
```

### ۷.۳ ثبت زبان جدید

در `config/app.php`:

```php
'available_locales' => ['fa', 'en', 'ar'],
```

---

## ۸. فرمت اعداد و تاریخ

### ۸.۱ Helper فرمت مبلغ

```php
function accounting_format_amount(float $amount, ?string $locale = null): string
{
    $locale = $locale ?? app()->getLocale();
    $format = __('accounting::accounting.format.number_format', [], $locale);
    
    return number_format(
        $amount,
        $format['decimals'],
        $format['decimal_separator'],
        $format['thousand_separator']
    );
}

// استفاده
echo accounting_format_amount(1234567.89);
// فارسی: 1,234,568
// انگلیسی: 1,234,567.89
```

### ۸.۲ Helper فرمت تاریخ

```php
function accounting_format_date($date, ?string $locale = null): string
{
    $locale = $locale ?? app()->getLocale();
    $format = __('accounting::accounting.format.date_format', [], $locale);
    
    if ($date instanceof Carbon) {
        return $date->format($format);
    }
    
    return Carbon::parse($date)->format($format);
}

// استفاده
echo accounting_format_date(now());
// فارسی: 1403/03/15
// انگلیسی: 2024-06-04
```

### ۸.۳ نمایش واحد پول

```php
function accounting_currency(float $amount, ?string $locale = null): string
{
    $locale = $locale ?? app()->getLocale();
    $formatted = accounting_format_amount($amount, $locale);
    $currency = __('accounting::accounting.format.currency_symbol', [], $locale);
    
    if ($locale === 'fa') {
        return $formatted . ' ' . $currency;
    }
    
    return $currency . $formatted;
}

// استفاده
echo accounting_currency(1000000);
// فارسی: 1,000,000 ریال
// انگلیسی: $1,000,000.00
```

---

## ۹. ترجمه در Model ها

### ۹.۱ Accessor برای وضعیت

```php
class Document extends Model
{
    public function getStatusLabelAttribute(): string
    {
        return __("accounting::accounting.document_statuses.{$this->status}");
    }
    
    public function getTypeLabelAttribute(): string
    {
        return __("accounting::accounting.document_types.{$this->type}");
    }
}

// استفاده
echo $document->status_label;  // "ثبت شده" یا "Posted"
echo $document->type_label;    // "فروش" یا "Sale"
```

### ۹.۲ Accessor برای نوع حساب

```php
class Account extends Model
{
    public function getTypeLabelAttribute(): string
    {
        return __("accounting::accounting.account_types.{$this->type}");
    }
    
    public function getNatureLabelAttribute(): string
    {
        return __("accounting::accounting.account_natures.{$this->nature}");
    }
    
    public function getLevelLabelAttribute(): string
    {
        return __("accounting::accounting.account_levels.{$this->level}");
    }
}
```

---

## ۱۰. ترجمه در Enum ها

### ۱۰.۱ Enum با متد ترجمه

```php
<?php

namespace YourVendor\Accounting\Enums;

enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case INCOME = 'income';
    case EXPENSE = 'expense';
    
    public function label(): string
    {
        return __("accounting::accounting.account_types.{$this->value}");
    }
    
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}

// استفاده
echo AccountType::ASSET->label();  // "دارایی" یا "Asset"

// برای Select
$options = AccountType::options();
// ['asset' => 'دارایی', 'liability' => 'بدهی', ...]
```

### ۱۰.۲ سایر Enum ها

```php
enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case VOIDED = 'voided';
    
    public function label(): string
    {
        return __("accounting::accounting.document_statuses.{$this->value}");
    }
    
    public function color(): string
    {
        return match($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'yellow',
            self::APPROVED => 'blue',
            self::POSTED => 'green',
            self::VOIDED => 'red',
        };
    }
}
```

---

## ۱۱. ترجمه در گزارش‌ها

### ۱۱.۱ عناوین گزارش

```php
class ReportService
{
    public function trialBalance(...): array
    {
        return [
            'title' => __('accounting::accounting.reports.trial_balance'),
            'columns' => [
                'code' => __('accounting::accounting.reports.columns.code'),
                'title' => __('accounting::accounting.reports.columns.title'),
                'debit' => __('accounting::accounting.reports.columns.debit'),
                'credit' => __('accounting::accounting.reports.columns.credit'),
                'balance' => __('accounting::accounting.reports.columns.balance'),
            ],
            'data' => [...],
        ];
    }
}
```

### ۱۱.۲ View گزارش

```html
<h1>{{ __('accounting::accounting.reports.trial_balance') }}</h1>

<table>
    <thead>
        <tr>
            <th>{{ __('accounting::accounting.reports.columns.code') }}</th>
            <th>{{ __('accounting::accounting.reports.columns.title') }}</th>
            <th>{{ __('accounting::accounting.reports.columns.debit') }}</th>
            <th>{{ __('accounting::accounting.reports.columns.credit') }}</th>
            <th>{{ __('accounting::accounting.reports.columns.balance') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data as $row)
            <tr>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['title'] }}</td>
                <td>{{ accounting_format_amount($row['debit']) }}</td>
                <td>{{ accounting_format_amount($row['credit']) }}</td>
                <td>{{ accounting_format_amount($row['balance']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<tfoot>
    <tr>
        <td colspan="2">{{ __('accounting::accounting.general.total') }}</td>
        <td>{{ accounting_format_amount($totals['debit']) }}</td>
        <td>{{ accounting_format_amount($totals['credit']) }}</td>
        <td></td>
    </tr>
</tfoot>
```

---

## ۱۲. پیام‌های خطا و اعتبارسنجی

### ۱۲.۱ Exception با ترجمه

```php
class UnbalancedDocumentException extends Exception
{
    public function __construct(float $debit, float $credit)
    {
        $message = __('accounting::accounting.validation.document_not_balanced', [
            'debit' => accounting_format_amount($debit),
            'credit' => accounting_format_amount($credit),
        ]);
        
        parent::__construct($message);
    }
}
```

### ۱۲.۲ Validation Rules

```php
class DocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'type' => 'required|in:sale,purchase,receipt,payment',
            'items' => 'required|array|min:2',
            'items.*.account_id' => 'required|exists:accounts,id',
            'items.*.amount' => 'required|numeric|min:0.01',
        ];
    }
    
    public function messages(): array
    {
        return [
            'date.required' => __('accounting::accounting.validation.date_required'),
            'type.required' => __('accounting::accounting.validation.type_required'),
            'items.min' => __('accounting::accounting.validation.items_required', ['min' => 2]),
            'items.*.amount.required' => __('accounting::accounting.validation.amount_required'),
            'items.*.amount.min' => __('accounting::accounting.validation.amount_positive'),
        ];
    }
}
```

---

## ۱۳. خلاصه

| موضوع | نکته کلیدی |
|-------|------------|
| مسیر فایل‌ها | `lang/vendor/accounting/{locale}/accounting.php` |
| انتشار | `vendor:publish --tag=accounting-lang` |
| استفاده | `__('accounting::accounting.key')` |
| Helper | `accounting_trans('key')` |
| فرمت عدد | `accounting_format_amount()` |
| افزودن زبان | کپی فایل + ترجمه |
| Enum | متد `label()` برای ترجمه |

---

[→ ادامه: امنیت (12-security.md)](12-security.md)

[← بازگشت: چند شعبه‌ای (10-multi-branch.md)](10-multi-branch.md)

[⌂ فهرست (00-index.md)](00-index.md)
