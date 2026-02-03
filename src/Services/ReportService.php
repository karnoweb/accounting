<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Service for accounting reports (trial balance, etc.).
 */
class ReportService
{
    public function __construct(
        private BalanceService $balanceService,
        private AccountService $accountService
    ) {}

    /**
     * Trial balance: list of leaf accounts (level 3) with non-zero balance, debit and credit columns.
     *
     * @return array<int, array{account: \Karnoweb\Accounting\Models\Account, debit: float, credit: float}>
     */
    public function trialBalance(?FiscalYear $fiscalYear = null): array
    {
        $fiscalYear ??= FiscalYear::current();

        if ( ! $fiscalYear) {
            return [];
        }

        $accounts = $this->accountService->search([
            'is_active' => true,
            'level' => 3,
        ]);

        $rows = [];
        foreach ($accounts as $account) {
            $balance = $this->balanceService->getBalance($account, $fiscalYear);
            if (abs($balance) >= 0.01) {
                $rows[] = [
                    'account' => $account,
                    'debit' => $balance > 0 ? $balance : 0,
                    'credit' => $balance < 0 ? abs($balance) : 0,
                ];
            }
        }

        return $rows;
    }
}
