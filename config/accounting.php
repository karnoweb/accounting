<?php

declare(strict_types=1);

return [
    'enabled' => env('ACCOUNTING_ENABLED', true),

    'general' => [
        'prefix' => env('ACCOUNTING_TABLE_PREFIX', 'acc_'),
        'date_format' => 'Y-m-d',
        // Storage, comparison, and presentation scale. Matches decimal(15,2)
        // columns. Never use PHP float for accounting math — see Amount.
        'decimal_places' => 2,
    ],

    'user' => [
        'model' => env('ACCOUNTING_USER_MODEL', App\Models\User::class),
        'table' => 'users',
        'foreign_key' => 'user_id',
    ],

    'branch' => [
        // جدول/مدل شعبه توسط پکیج ایجاد نمی‌شود؛ فقط branch_id در accounts و documents ذخیره می‌شود.
        // در صورت داشتن جدول Branch در اپ، model و table را تنظیم کنید تا رابطه branch() کار کند.
        'enabled' => true,
        'model' => env('ACCOUNTING_BRANCH_MODEL', App\Models\Branch::class),
        'table' => env('ACCOUNTING_BRANCH_TABLE', 'branches'),
        'foreign_key' => 'branch_id',
        'default_id' => 1,
        'separate_numbering' => false,
        'resolver' => null,
    ],

    'account' => [
        // Levels are derived from code_length unless overridden:
        // max_level = count(code_length) - 1, posting_level defaults to max_level.
        'code_length' => [1, 2, 4, 6],
        'max_level' => null,
        'posting_level' => null,
        'auto_code' => true,
        'custom_seed' => [],
        'system_accounts' => [
            'cash' => '110101',
            'bank' => '110201',
            // '1103'/'2101' are level-2 group (moein) accounts — not postable.
            // Point these at the level-3 detail leaves added below instead.
            'receivables' => '110300',
            'payables' => '210101',
            'sales_income' => '410101',
            'cost_of_goods' => '510101',
            'refund_expense' => '520101',
            'retained_earnings' => '310101',
            // Inventory (used by e.g. karnoweb/laravel-inventory integrations).
            'inventory' => '110901',
            'inventory_shrinkage' => '520401',
            'inventory_count_gain' => '410201',
            // Loans/advances to employees (HR payroll integrations).
            'employee_loan_receivable' => '111101',
            // Online payment gateway settlement.
            'gateway_clearing' => '110501',
            // Contra-revenue.
            'sales_discount' => '490101',
            'sales_return' => '490201',
            // Tax.
            'vat_payable' => '210401',
            'payroll_tax_payable' => '210402',
            // Payroll (HR integrations).
            'payroll_payable' => '210501',
            'payroll_insurance_payable' => '210502',
            'payroll_salary_expense' => '520201',
            'payroll_employer_insurance' => '520202',
            // Bank/gateway charges.
            'bank_fee' => '520301',
        ],
    ],

    'document' => [
        'min_items' => 2,
        'allowed_types' => ['sale', 'purchase', 'receipt', 'payment', 'transfer', 'opening', 'closing', 'adjustment', 'reversal'],
        'workflow_enabled' => false,
        // Bounded retries when auto-number collides with unique(fiscal_year_id, number).
        'number_allocation_retries' => 5,
    ],

    'fiscal_year' => [
        'auto_detect' => true,
        'default_id' => null,
        // Overlapping ranges are rejected in FiscalYearService (portable across SQLite/MySQL/PostgreSQL).
        // Exact duplicate (start_date, end_date) is also unique at the database.
        'allow_overlap' => false,
        // When true, more than one fiscal year may be `active` at once (e.g. prior
        // year still open for tax adjustments while the new year receives sales).
        // `is_current` still marks the UI/default year; posting resolves by document date.
        'allow_multiple_active' => true,
    ],

    'opening' => [
        // When true, confirm()/post() of opening journals is allowed even after
        // operational (non-opening) documents have been posted in the target year.
        'allow_after_posted_activity' => true,
        // When true, confirm()/post()/completeOpening() refuse while the consecutive
        // prior fiscal year exists and is not yet `closed`.
        'require_prior_year_closed_for_confirm' => true,
        // When true, carryForward() may run from an still-`active` source and only
        // writes/refreshes DRAFT openings (provisional). Final confirm still follows
        // require_prior_year_closed_for_confirm.
        'allow_provisional_carry_forward' => true,
    ],

    'period' => [
        // When a fiscal year is activated and has no periods yet, create one OPEN
        // period spanning the whole year so posting has a canonical period gate.
        'auto_create_on_activate' => true,
    ],

    'balance' => [
        'cache_enabled' => true,
        'cache_ttl' => 3600,
        'update_strategy' => 'immediate',
        'update_parents' => true,
    ],

    'validation' => [
        'check_account_active' => true,
        'check_date_range' => true,
        'strict_balance' => true,
    ],

    'reports' => [
        'per_page' => 50,
        // System-account keys treated as cash/bank for cashMovements().
        // Codes come from account.system_accounts. Not inferred from titles.
        'cash_system_keys' => ['cash', 'bank', 'gateway_clearing'],
    ],
];
