<?php

declare(strict_types=1);

return [
    'account_types' => [
        'asset' => 'دارایی',
        'liability' => 'بدهی',
        'equity' => 'سرمایه',
        'income' => 'درآمد',
        'expense' => 'هزینه',
    ],

    'account_natures' => [
        'debit' => 'بدهکار',
        'credit' => 'بستانکار',
    ],

    'document_statuses' => [
        'draft' => 'پیش‌نویس',
        'pending' => 'در انتظار',
        'approved' => 'تأیید شده',
        'posted' => 'ثبت شده',
        'voided' => 'باطل شده',
    ],

    'fiscal_year_statuses' => [
        'draft' => 'پیش‌نویس',
        'active' => 'فعال',
        'closed' => 'بسته',
    ],

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

    'general' => [
        'debit' => 'بدهکار',
        'credit' => 'بستانکار',
    ],

    'messages' => [
        'system_account_protected' => 'حساب سیستمی قابل تغییر نیست.',
        'account_has_transactions' => 'حساب دارای تراکنش است.',
        'account_has_children' => 'حساب دارای زیرمجموعه است.',
        'account_inactive' => 'حساب غیرفعال است.',
        'account_not_found' => 'حساب یافت نشد.',
        'document_not_editable' => 'سند قابل ویرایش نیست.',
        'document_not_voidable' => 'سند قابل ابطال نیست.',
        'document_not_balanced' => 'سند متعادل نیست.',
        'document_cannot_post' => 'سند قابل ثبت قطعی نیست.',
        'fiscal_year_closed' => 'سال مالی بسته است.',
        'fiscal_year_not_active' => 'سال مالی فعال نیست.',
        'no_active_fiscal_year' => 'سال مالی فعالی وجود ندارد.',
        'abnormal_balance' => 'مانده غیرطبیعی برای حساب :account.',
    ],

    'validation' => [
        'date_required' => 'تاریخ الزامی است.',
        'type_required' => 'نوع سند الزامی است.',
        'type_invalid' => 'نوع سند نامعتبر است.',
        'items_required' => 'حداقل :min آیتم الزامی است.',
        'account_required' => 'حساب الزامی است.',
        'account_invalid' => 'حساب نامعتبر است.',
        'amount_positive' => 'مبلغ باید مثبت باشد.',
        'document_not_balanced' => 'سند متعادل نیست (بدهکار: :debit، بستانکار: :credit).',
        'date_out_of_fiscal_year' => 'تاریخ خارج از بازه سال مالی است.',
    ],

    'documents' => [
        'order_payment' => 'پرداخت سفارش #:order_id',
        'wallet_charge' => 'شارژ کیف پول #:wallet_id',
        'refund' => 'استرداد مرجوعی #:order_return_id',
        'deduct_wallet' => 'کسر از کیف پول #:wallet_id',
    ],

    'audit_actions' => [
        'created' => 'ایجاد',
        'updated' => 'ویرایش',
        'submitted' => 'ارسال برای تأیید',
        'approved' => 'تأیید',
        'rejected' => 'رد',
        'posted' => 'ثبت قطعی',
        'voided' => 'ابطال',
        'restored' => 'بازیابی',
    ],
];
