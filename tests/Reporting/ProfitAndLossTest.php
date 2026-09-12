<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountingPeriodService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\ReversalService;
use Karnoweb\Accounting\Tests\TestCase;

class ProfitAndLossTest extends TestCase
{
    use PostsJournals;

    public function test_revenue_only(): void
    {
        $fy = $this->createActiveFiscalYear();
        $assets = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->postJournal($fy, $assets['detail'], $income['detail'], 80);

        $report = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(80, $report->totalRevenue);
        $this->assertAmount(0, $report->totalExpenses);
        $this->assertAmount(80, $report->netProfit);
        $this->assertAmount(80, $report->find($income['detail']->id)->amount);
        $this->assertAmount(80, $report->find($income['group']->id)->amount);
    }

    public function test_expense_only(): void
    {
        $fy = $this->createActiveFiscalYear();
        $liabilities = $this->createTypedChart(AccountType::LIABILITY, '2');
        $expenses = $this->createTypedChart(AccountType::EXPENSE, '5');
        $this->postJournal($fy, $expenses['detail'], $liabilities['detail'], 45);

        $report = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(0, $report->totalRevenue);
        $this->assertAmount(45, $report->totalExpenses);
        $this->assertAmount(-45, $report->netProfit);
    }

    public function test_revenue_and_expenses_net_profit(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 100);
        $this->postJournal($fy, $temps['expense'], $chart['detail2'], 40);

        $report = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(100, $report->totalRevenue);
        $this->assertAmount(40, $report->totalExpenses);
        $this->assertAmount(60, $report->netProfit);
        $this->assertSame($report->netProfit, $report->totalRevenue - $report->totalExpenses);
    }

    public function test_net_loss(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 20);
        $this->postJournal($fy, $temps['expense'], $chart['detail2'], 90);

        $report = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(-70, $report->netProfit);
    }

    public function test_nested_revenue_accounts_roll_up_without_double_count(): void
    {
        $fy = $this->createActiveFiscalYear();
        $assets = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->postJournal($fy, $assets['detail'], $income['detail'], 30);
        $this->postJournal($fy, $assets['detail'], $income['detail2'], 70);

        $report = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(30, $report->find($income['detail']->id)->amount);
        $this->assertAmount(70, $report->find($income['detail2']->id)->amount);
        $this->assertAmount(100, $report->find($income['subsidiary']->id)->amount);
        $this->assertAmount(100, $report->find($income['group']->id)->amount);
        $this->assertAmount(100, $report->totalRevenue);
        $this->assertCount(2, $report->revenueDetail());
    }

    public function test_nested_expense_accounts_roll_up_without_double_count(): void
    {
        $fy = $this->createActiveFiscalYear();
        $liabilities = $this->createTypedChart(AccountType::LIABILITY, '2');
        $expenses = $this->createTypedChart(AccountType::EXPENSE, '5');
        $this->postJournal($fy, $expenses['detail'], $liabilities['detail'], 15);
        $this->postJournal($fy, $expenses['detail2'], $liabilities['detail'], 25);

        $report = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(15, $report->find($expenses['detail']->id)->amount);
        $this->assertAmount(25, $report->find($expenses['detail2']->id)->amount);
        $this->assertAmount(40, $report->find($expenses['group']->id)->amount);
        $this->assertAmount(40, $report->totalExpenses);
        $this->assertCount(2, $report->expenseDetail());
    }

    public function test_date_range_is_period_flow_not_inception_to_date(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 50, '2025-01-10');
        $this->postJournal($fy, $chart['detail'], $temps['income'], 80, '2025-06-10');
        $this->postJournal($fy, $temps['expense'], $chart['detail2'], 20, '2025-06-15');

        $report = Accounting::report()->profitAndLoss(
            LedgerQuery::make()->forFiscalYear($fy)->from('2025-06-01')->to('2025-06-30')
        );

        $this->assertAmount(80, $report->totalRevenue);
        $this->assertAmount(20, $report->totalExpenses);
        $this->assertAmount(60, $report->netProfit);
        $this->assertSame('2025-06-01', $report->from);
        $this->assertSame('2025-06-30', $report->to);
    }

    public function test_fiscal_year_isolation(): void
    {
        $fy1 = $this->createActiveFiscalYear('FY 2025', '2025-01-01', '2025-12-31', current: true);
        $fy2 = $this->createActiveFiscalYear('FY 2026', '2026-01-01', '2026-12-31', current: false);
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->postJournal($fy1, $chart['detail'], $temps['income'], 100, '2025-03-01');
        $this->postJournal($fy2, $chart['detail'], $temps['income'], 40, '2026-03-01');

        $this->assertAmount(100, Accounting::report()->profitAndLoss($fy1)->totalRevenue);
        $this->assertAmount(40, Accounting::report()->profitAndLoss($fy2)->totalRevenue);
    }

    public function test_accounting_period_filter(): void
    {
        config(['accounting.period.auto_create_on_activate' => false]);

        $years = app(FiscalYearService::class);
        $periods = app(AccountingPeriodService::class);
        $fy = $years->activate($years->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $h1 = $periods->open($periods->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'H1',
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-30',
        ]));
        $periods->open($periods->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'H2',
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ]));

        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 70, '2025-02-01');
        $this->postJournal($fy, $chart['detail'], $temps['income'], 30, '2025-08-01');

        $this->assertInstanceOf(AccountingPeriod::class, $h1);
        $report = Accounting::report()->profitAndLoss(
            LedgerQuery::make()->forAccountingPeriod($h1)
        );

        $this->assertAmount(70, $report->totalRevenue);
        $this->assertSame('2025-01-01', $report->from);
        $this->assertSame('2025-06-30', $report->to);
    }

    public function test_branch_isolation(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 25, branchId: 1);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 75, branchId: 2);

        $this->assertAmount(25, Accounting::report()->profitAndLoss(
            LedgerQuery::make()->forFiscalYear($fy)->branch(1)
        )->totalRevenue);
        $this->assertAmount(75, Accounting::report()->profitAndLoss(
            LedgerQuery::make()->forFiscalYear($fy)->branch(2)
        )->totalRevenue);
        $this->assertAmount(100, Accounting::report()->profitAndLoss($fy)->totalRevenue);
    }

    public function test_cost_center_filter(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $ops = CostCenter::create(['code' => 'CC1', 'title' => 'Ops']);
        $other = CostCenter::create(['code' => 'CC2', 'title' => 'Other']);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 40, costCenterId: $ops->id);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 60, costCenterId: $other->id);

        $report = Accounting::report()->profitAndLoss(
            LedgerQuery::make()->forFiscalYear($fy)->costCenter($ops)
        );

        $this->assertAmount(40, $report->totalRevenue);
    }

    public function test_reversal_cancels_period_profit(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $document = $this->postJournal($fy, $chart['detail'], $temps['income'], 90);

        app(ReversalService::class)->reverse($document);

        $this->assertAmount(0, Accounting::report()->profitAndLoss($fy)->netProfit);
    }

    public function test_void_excludes_the_document(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->postJournal($fy, $chart['detail'], $temps['income'], 50);
        $voided = $this->postJournal($fy, $chart['detail'], $temps['income'], 999);
        $voided->void('mistake');

        $this->assertAmount(50, Accounting::report()->profitAndLoss($fy)->totalRevenue);
    }

    public function test_draft_documents_are_excluded(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $temps['income'], 500),
        ]);

        $this->assertAmount(0, Accounting::report()->profitAndLoss($fy)->totalRevenue);
    }

    public function test_closing_journals_are_excluded_so_year_end_pnl_stays_a_flow(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->bindRetainedEarnings();
        $this->postJournal($fy, $chart['detail'], $temps['income'], 100);
        $this->postJournal($fy, $temps['expense'], $chart['detail2'], 30);

        $before = Accounting::report()->profitAndLoss($fy);
        app(ClosingService::class)->closeProfitAndLoss($fy);
        $after = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(70, $before->netProfit);
        $this->assertAmount(70, $after->netProfit);
        $this->assertAmount(100, $after->totalRevenue);
        $this->assertAmount(30, $after->totalExpenses);

        $query = LedgerQuery::make()->forFiscalYear($fy);
        Accounting::report()->profitAndLoss($query);
        $this->assertSame([], $query->excludedDocumentTypes());
    }

    public function test_contra_income_reduces_revenue(): void
    {
        $fy = $this->createActiveFiscalYear();
        $assets = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $this->postJournal($fy, $assets['detail'], $income['detail'], 100);
        $this->postJournal($fy, $income['detail2'], $assets['detail'], 15);

        $report = Accounting::report()->profitAndLoss($fy);

        $this->assertAmount(-15, $report->find($income['detail2']->id)->amount);
        $this->assertAmount(85, $report->totalRevenue);
    }
}
