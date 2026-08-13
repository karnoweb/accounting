<?php

declare(strict_types=1);

return [
    'enabled' => env('ACCOUNTING_ENABLED', true),

    'general' => [
        'prefix' => env('ACCOUNTING_TABLE_PREFIX', 'acc_'),
        'date_format' => 'Y-m-d',
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
            'receivables' => '1103',
            'payables' => '2101',
            'sales_income' => '410101',
            'cost_of_goods' => '510101',
            'refund_expense' => '520101',
        ],
    ],

    'document' => [
        'min_items' => 2,
        'allowed_types' => ['sale', 'purchase', 'receipt', 'payment', 'transfer', 'opening', 'closing', 'adjustment'],
        'workflow_enabled' => false,
        // Bounded retries when auto-number collides with unique(fiscal_year_id, number).
        'number_allocation_retries' => 5,
    ],

    'fiscal_year' => [
        'auto_detect' => true,
        'default_id' => null,
        // Package model forbids overlapping date ranges.
        'allow_overlap' => false,
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
    ],
];
