<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Karnoweb\Accounting\Support\Amount;

trait PostsJournals
{
    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function postJournal(
        FiscalYear $fy,
        Account $debit,
        Account $credit,
        int|float|string $amount,
        string $date = '2025-06-01',
        ?int $branchId = null,
        ?int $costCenterId = null,
        string $type = 'adjustment'
    ): Document {
        $items = $this->balancedItems($debit, $credit, $amount);
        if ($costCenterId !== null) {
            $items[0]['cost_center_id'] = $costCenterId;
            $items[1]['cost_center_id'] = $costCenterId;
        }

        return $this->documents()->post($this->documents()->create([
            'type' => $type,
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'items' => $items,
        ]));
    }

    private function assertAmount(int|float|string $expected, int|float|string $actual): void
    {
        $this->assertTrue(
            Amount::of($expected)->equals($actual),
            sprintf('Failed asserting that %s equals %s.', Amount::of($actual), Amount::of($expected))
        );
    }

    /**
     * Homogeneous chart so statement parent rollups stay type-correct.
     *
     * @return array{group: Account, general: Account, subsidiary: Account, detail: Account, detail2: Account}
     */
    private function createTypedChart(AccountType $type, string $prefix): array
    {
        $postingLevel = AccountHierarchy::postingLevel();
        $nature = $type->defaultNature()->value;

        $group = Account::create([
            'code' => $prefix,
            'title' => $type->value.' group',
            'level' => 0,
            'type' => $type,
            'nature' => $nature,
            'allow_direct_posting' => false,
            'is_active' => true,
        ]);
        $general = Account::create([
            'parent_id' => $group->id,
            'code' => $prefix.'1',
            'title' => $type->value.' general',
            'level' => 1,
            'type' => $type,
            'nature' => $nature,
            'allow_direct_posting' => false,
            'is_active' => true,
        ]);
        $subsidiary = Account::create([
            'parent_id' => $general->id,
            'code' => $prefix.'101',
            'title' => $type->value.' subsidiary',
            'level' => 2,
            'type' => $type,
            'nature' => $nature,
            'allow_direct_posting' => false,
            'is_active' => true,
        ]);
        $detail = Account::create([
            'parent_id' => $subsidiary->id,
            'code' => $prefix.'10101',
            'title' => $type->value.' detail A',
            'level' => $postingLevel,
            'type' => $type,
            'nature' => $nature,
            'allow_direct_posting' => true,
            'is_active' => true,
        ]);
        $detail2 = Account::create([
            'parent_id' => $subsidiary->id,
            'code' => $prefix.'10102',
            'title' => $type->value.' detail B',
            'level' => $postingLevel,
            'type' => $type,
            'nature' => $nature,
            'allow_direct_posting' => true,
            'is_active' => true,
        ]);

        return compact('group', 'general', 'subsidiary', 'detail', 'detail2');
    }

    private function createTemporaryAccounts(Account $parent): array
    {
        $income = app(AccountService::class)->create([
            'parent_id' => $parent->id,
            'title' => 'Sales income',
            'type' => AccountType::INCOME,
        ]);
        $expense = app(AccountService::class)->create([
            'parent_id' => $parent->id,
            'title' => 'Rent expense',
            'type' => AccountType::EXPENSE,
        ]);

        return compact('income', 'expense');
    }

    private function bindRetainedEarnings(): Account
    {
        $equity = $this->createTypedChart(AccountType::EQUITY, '3');
        config(['accounting.account.system_accounts.retained_earnings' => $equity['detail']->code]);

        return $equity['detail'];
    }

    private function bindCashAccounts(Account $cash, ?Account $bank = null): void
    {
        config([
            'accounting.account.system_accounts.cash' => $cash->code,
            'accounting.reports.cash_system_keys' => $bank ? ['cash', 'bank'] : ['cash'],
        ]);

        if ($bank) {
            config(['accounting.account.system_accounts.bank' => $bank->code]);
        }
    }
}
