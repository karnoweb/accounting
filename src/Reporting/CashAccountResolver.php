<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Services\AccountService;

/**
 * Resolves cash-like accounts from configured system-account keys.
 * Does not infer cash from titles or code ranges.
 */
final class CashAccountResolver
{
    public function __construct(
        private AccountService $accountService
    ) {}

    /**
     * @return list<int>
     */
    public function accountIds(?int $branchId, bool $branchFilterApplied): array
    {
        $keys = config('accounting.reports.cash_system_keys', ['cash', 'bank', 'gateway_clearing']);
        if ( ! is_array($keys)) {
            return [];
        }

        $ids = [];

        foreach ($keys as $key) {
            if ( ! is_string($key) || $key === '') {
                continue;
            }

            $code = config('accounting.account.system_accounts.'.$key);
            if ( ! is_string($code) || $code === '') {
                continue;
            }

            if ($branchFilterApplied) {
                $account = $this->accountService->findByCodeForBranch($code, $branchId);
                if ($account) {
                    $ids[] = $account->id;
                }

                continue;
            }

            foreach (Account::query()->where('code', $code)->get() as $account) {
                $ids[] = $account->id;
            }
        }

        return array_values(array_unique($ids));
    }
}
