<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\ReversalService;
use Karnoweb\Accounting\Tests\TestCase;

class CashMovementTest extends TestCase
{
    use PostsJournals;

    public function test_detects_configured_cash_accounts(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->bindCashAccounts($cash['detail']);
        $this->postJournal($fy, $cash['detail'], $income['detail'], 50);

        $report = Accounting::report()->cashMovements($fy);

        $this->assertSame([$cash['detail']->id], $report->cashAccountIds);
        $this->assertNotNull($report->accounts->firstWhere('accountId', $cash['detail']->id));
    }

    public function test_cash_inflow(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->bindCashAccounts($cash['detail']);
        $this->postJournal($fy, $cash['detail'], $income['detail'], 80);

        $report = Accounting::report()->cashMovements($fy);

        $this->assertAmount(80, $report->inflow);
        $this->assertAmount(0, $report->outflow);
        $this->assertAmount(0, $report->transfers);
        $this->assertAmount(80, $report->netChange);
    }

    public function test_cash_outflow(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $expense = $this->createTypedChart(AccountType::EXPENSE, '5');
        $this->bindCashAccounts($cash['detail']);
        $this->postJournal($fy, $expense['detail'], $cash['detail'], 35);

        $report = Accounting::report()->cashMovements($fy);

        $this->assertAmount(0, $report->inflow);
        $this->assertAmount(35, $report->outflow);
        $this->assertAmount(-35, $report->netChange);
    }

    public function test_transfer_between_cash_accounts(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $bank = $this->createTypedChart(AccountType::ASSET, '6');
        $this->bindCashAccounts($cash['detail'], $bank['detail']);
        $this->postJournal($fy, $bank['detail'], $cash['detail'], 40, type: 'transfer');

        $report = Accounting::report()->cashMovements($fy);

        $this->assertAmount(40, $report->inflow);
        $this->assertAmount(40, $report->outflow);
        $this->assertAmount(40, $report->transfers);
        $this->assertAmount(0, $report->netChange);
    }

    public function test_non_cash_journals_are_excluded(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $receivable = $this->createTypedChart(AccountType::ASSET, '7');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->bindCashAccounts($cash['detail']);
        $this->postJournal($fy, $receivable['detail'], $income['detail'], 200);

        $report = Accounting::report()->cashMovements($fy);

        $this->assertAmount(0, $report->inflow);
        $this->assertAmount(0, $report->outflow);
        $this->assertAmount(0, $report->netChange);
    }

    public function test_date_filter(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->bindCashAccounts($cash['detail']);
        $this->postJournal($fy, $cash['detail'], $income['detail'], 10, '2025-01-05');
        $this->postJournal($fy, $cash['detail'], $income['detail'], 90, '2025-09-01');

        $report = Accounting::report()->cashMovements(
            LedgerQuery::make()->forFiscalYear($fy)->from('2025-08-01')->to('2025-12-31')
        );

        $this->assertAmount(90, $report->inflow);
        $this->assertSame('2025-08-01', $report->from);
        $this->assertSame('2025-12-31', $report->to);
    }

    public function test_branch_filter(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->bindCashAccounts($cash['detail']);
        $this->postJournal($fy, $cash['detail'], $income['detail'], 12, branchId: 1);
        $this->postJournal($fy, $cash['detail'], $income['detail'], 88, branchId: 2);

        $this->assertAmount(12, Accounting::report()->cashMovements(
            LedgerQuery::make()->forFiscalYear($fy)->branch(1)
        )->inflow);
        $this->assertAmount(100, Accounting::report()->cashMovements($fy)->inflow);
    }

    public function test_reversal_cancels_cash_movement(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->bindCashAccounts($cash['detail']);
        $document = $this->postJournal($fy, $cash['detail'], $income['detail'], 55);
        app(ReversalService::class)->reverse($document);

        $report = Accounting::report()->cashMovements($fy);

        $this->assertAmount(55, $report->inflow);
        $this->assertAmount(55, $report->outflow);
        $this->assertAmount(0, $report->netChange);
    }

    public function test_void_excludes_cash_movement(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->bindCashAccounts($cash['detail']);
        $this->postJournal($fy, $cash['detail'], $income['detail'], 18);
        $this->postJournal($fy, $cash['detail'], $income['detail'], 77)->void('mistake');

        $this->assertAmount(18, Accounting::report()->cashMovements($fy)->inflow);
    }

    public function test_does_not_imply_operating_investing_financing_classification(): void
    {
        $fy = $this->createActiveFiscalYear();
        $cash = $this->createTypedChart(AccountType::ASSET, '1');
        $this->bindCashAccounts($cash['detail']);

        $array = Accounting::report()->cashMovements($fy)->toArray();

        $this->assertArrayNotHasKey('operating', $array);
        $this->assertArrayNotHasKey('investing', $array);
        $this->assertArrayNotHasKey('financing', $array);
    }
}
