<?php

declare(strict_types=1);

return [
    'enabled' => env('ACCOUNTING_ENABLED', true),

    'general' => [
        'prefix' => env('ACCOUNTING_TABLE_PREFIX', ''),
        'date_format' => 'Y-m-d',
        'decimal_places' => 2,
    ],

    'user' => [
        'model' => env('ACCOUNTING_USER_MODEL', App\Models\User::class),
        'table' => 'users',
        'foreign_key' => 'user_id',
    ],

    'fiscal_year' => [
        'auto_detect' => true,
        'default_id' => null,
    ],

    'branch' => [
        'enabled' => true,
        'default_id' => 1,
        'separate_numbering' => false,
        'resolver' => null,
    ],

    'account' => [
        'code_length' => [1, 2, 4, 6],
        'auto_code' => true,
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
