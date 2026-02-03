<?php

declare(strict_types=1);

return [
    'account_types' => [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'income' => 'Income',
        'expense' => 'Expense',
    ],

    'account_natures' => [
        'debit' => 'Debit',
        'credit' => 'Credit',
    ],

    'document_statuses' => [
        'draft' => 'Draft',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'posted' => 'Posted',
        'voided' => 'Voided',
    ],

    'fiscal_year_statuses' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'closed' => 'Closed',
    ],

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

    'general' => [
        'debit' => 'Debit',
        'credit' => 'Credit',
    ],

    'messages' => [
        'system_account_protected' => 'System account cannot be modified.',
        'account_has_transactions' => 'Account has transactions.',
        'account_has_children' => 'Account has child accounts.',
        'account_inactive' => 'Account is inactive.',
        'account_not_found' => 'Account not found.',
        'document_not_editable' => 'Document is not editable.',
        'document_not_voidable' => 'Document cannot be voided.',
        'document_not_balanced' => 'Document is not balanced.',
        'document_cannot_post' => 'Document cannot be posted.',
        'fiscal_year_closed' => 'Fiscal year is closed.',
        'fiscal_year_not_active' => 'Fiscal year is not active.',
        'no_active_fiscal_year' => 'No active fiscal year.',
        'abnormal_balance' => 'Abnormal balance for account :account.',
    ],

    'validation' => [
        'date_required' => 'Date is required.',
        'type_required' => 'Document type is required.',
        'type_invalid' => 'Invalid document type.',
        'items_required' => 'At least :min items are required.',
        'account_required' => 'Account is required.',
        'account_invalid' => 'Invalid account.',
        'amount_positive' => 'Amount must be positive.',
        'document_not_balanced' => 'Document is not balanced (debit: :debit, credit: :credit).',
        'date_out_of_fiscal_year' => 'Date is outside fiscal year range.',
    ],

    'documents' => [
        'order_payment' => 'Order payment #:order_id',
        'wallet_charge' => 'Wallet charge #:wallet_id',
        'refund' => 'Refund order return #:order_return_id',
        'deduct_wallet' => 'Wallet deduction #:wallet_id',
    ],

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
];
