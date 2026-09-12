<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Reporting\BalanceSheetReport;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Services\ReversalService;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class BalanceSheetTest extends TestCase
{
    use PostsJournals;

    private function assertEquation(BalanceSheetReport $report): void
    {
        $this->assertTrue($report->balanced, 'Balance Sheet must satisfy Assets = Liabilities + Equity + Current Earnings');
        $this->assertTrue(
            Amount::of($report->totalAssets)->equals($report->totalLiabilitiesAndEquity()),
            sprintf(
                'Assets %s != L+E+CE %s',
                Amount::of($report->totalAssets),
                Amount::of($report->totalLiabilitiesAndEquity())
            )
        );
    }

    public function test_basic_accounting_equation(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 100);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(100, $report->totalAssets);
        $this->assertAmount(100, $report->totalLiabilities);
        $this->assertAmount(0, $report->totalEquity);
        $this->assertAmount(0, $report->currentEarnings);
        $this->assertEquation($report);
    }

    public function test_nested_asset_accounts_roll_up(): void
    {
        $fy = $this->createActiveFiscalYear();
        $assets = $this->createTypedChart(AccountType::ASSET, '1');
        $liabilities = $this->createTypedChart(AccountType::LIABILITY, '2');
        $this->postJournal($fy, $assets['detail'], $liabilities['detail'], 40);
        $this->postJournal($fy, $assets['detail2'], $liabilities['detail'], 60);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(40, $report->find($assets['detail']->id)->amount);
        $this->assertAmount(60, $report->find($assets['detail2']->id)->amount);
        $this->assertAmount(100, $report->find($assets['group']->id)->amount);
        $this->assertAmount(100, $report->totalAssets);
        $this->assertEquation($report);
    }

    public function test_nested_liability_and_equity_accounts(): void
    {
        $fy = $this->createActiveFiscalYear();
        $assets = $this->createTypedChart(AccountType::ASSET, '1');
        $liabilities = $this->createTypedChart(AccountType::LIABILITY, '2');
        $equity = $this->createTypedChart(AccountType::EQUITY, '3');
        $this->postJournal($fy, $assets['detail'], $liabilities['detail'], 70);
        $this->postJournal($fy, $assets['detail2'], $equity['detail'], 30);
        $this->postJournal($fy, $assets['detail2'], $equity['detail2'], 20);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(70, $report->find($liabilities['group']->id)->amount);
        $this->assertAmount(50, $report->find($equity['group']->id)->amount);
        $this->assertAmount(120, $report->totalAssets);
        $this->assertAmount(70, $report->totalLiabilities);
        $this->assertAmount(50, $report->totalEquity);
        $this->assertEquation($report);
    }

    public function test_as_of_date_is_stock_not_period_flow(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 80, '2025-01-15');
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 20, '2025-08-01');

        $june = Accounting::report()->balanceSheet(
            LedgerQuery::make()->forFiscalYear($fy)->to('2025-06-30')
        );
        $year = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(80, $june->totalAssets);
        $this->assertSame('2025-06-30', $june->asOf);
        $this->assertAmount(100, $year->totalAssets);
        $this->assertSame('2025-12-31', $year->asOf);
        $this->assertEquation($june);
        $this->assertEquation($year);
    }

    public function test_first_fiscal_year_without_opening_document(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 55);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(55, $report->find($chart['detail']->id)->amount);
        $this->assertEquation($report);
    }

    public function test_posted_opening_is_included_once(): void
    {
        $years = app(FiscalYearService::class);
        $fy1 = $years->activate($years->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $chart = $this->createPostableChart();
        $this->postJournal($fy1, $chart['detail'], $chart['detail2'], 100, '2025-03-01');
        $years->close($fy1);

        $fy2 = $years->activate($years->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]));
        app(OpeningService::class)->post($fy2, $this->balancedItems($chart['detail'], $chart['detail2'], 100));
        $this->postJournal($fy2, $chart['detail'], $chart['detail2'], 20, '2026-02-01');

        $report = Accounting::report()->balanceSheet($fy2);

        $this->assertAmount(120, $report->totalAssets);
        $this->assertAmount(120, $report->totalLiabilities);
        $this->assertEquation($report);
    }

    public function test_draft_opening_is_excluded(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        app(OpeningService::class)->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 400));

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(0, $report->totalAssets);
        $this->assertEquation($report);
    }

    public function test_branch_specific_opening(): void
    {
        $years = app(FiscalYearService::class);
        $fy1 = $years->activate($years->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $chart = $this->createPostableChart();
        $this->postJournal($fy1, $chart['detail'], $chart['detail2'], 10, '2025-03-01', branchId: 1);
        $years->close($fy1);

        $fy2 = $years->activate($years->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]));
        $opening = app(OpeningService::class);
        $opening->saveDraft($fy2, $this->balancedItems($chart['detail'], $chart['detail2'], 10), 1);
        $opening->saveDraft($fy2, $this->balancedItems($chart['detail'], $chart['detail2'], 90), 2);
        $opening->confirm($fy2, 1);
        $opening->confirm($fy2, 2);

        $branch1 = Accounting::report()->balanceSheet(
            LedgerQuery::make()->forFiscalYear($fy2)->branch(1)
        );
        $all = Accounting::report()->balanceSheet($fy2);

        $this->assertAmount(10, $branch1->totalAssets);
        $this->assertAmount(100, $all->totalAssets);
        $this->assertEquation($branch1);
        $this->assertEquation($all);
    }

    public function test_current_year_profit_before_closing(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->bindRetainedEarnings();
        $this->postJournal($fy, $chart['detail'], $temps['income'], 100);
        $this->postJournal($fy, $temps['expense'], $chart['detail2'], 40);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(100, $report->totalAssets);
        $this->assertAmount(40, $report->totalLiabilities);
        $this->assertAmount(0, $report->totalEquity);
        $this->assertAmount(60, $report->currentEarnings);
        $this->assertEquation($report);
    }

    public function test_current_year_loss_before_closing(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $this->bindRetainedEarnings();
        $this->postJournal($fy, $chart['detail'], $temps['income'], 10);
        $this->postJournal($fy, $temps['expense'], $chart['detail2'], 70);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(-60, $report->currentEarnings);
        $this->assertEquation($report);
    }

    public function test_post_closing_moves_profit_to_equity_without_double_count(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $temps = $this->createTemporaryAccounts($chart['subsidiary']);
        $retained = $this->bindRetainedEarnings();
        $this->postJournal($fy, $chart['detail'], $temps['income'], 100);
        $this->postJournal($fy, $temps['expense'], $chart['detail2'], 40);

        $before = Accounting::report()->balanceSheet($fy);
        app(ClosingService::class)->closeProfitAndLoss($fy);
        $after = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(60, $before->currentEarnings);
        $this->assertAmount(0, $before->totalEquity);
        $this->assertAmount(0, $after->currentEarnings);
        $this->assertAmount(60, $after->totalEquity);
        $this->assertAmount(60, $after->find($retained->id)->amount);
        $this->assertAmount($before->totalAssets, $after->totalAssets);
        $this->assertEquation($before);
        $this->assertEquation($after);

        $pnl = Accounting::report()->profitAndLoss($fy);
        $this->assertAmount(60, $pnl->netProfit);
    }

    public function test_reversal_restores_position(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = $this->postJournal($fy, $chart['detail'], $chart['detail2'], 88);
        app(ReversalService::class)->reverse($document);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(0, $report->totalAssets);
        $this->assertAmount(0, $report->totalLiabilities);
        $this->assertEquation($report);
    }

    public function test_void_excludes_the_document(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 25);
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 400)->void('mistake');

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(25, $report->totalAssets);
        $this->assertEquation($report);
    }

    public function test_draft_documents_are_excluded(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 300),
        ]);

        $report = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(0, $report->totalAssets);
        $this->assertEquation($report);
    }

    public function test_branch_isolation(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 15, branchId: 1);
        $this->postJournal($fy, $chart['detail'], $chart['detail2'], 85, branchId: 2);

        $one = Accounting::report()->balanceSheet(
            LedgerQuery::make()->forFiscalYear($fy)->branch(1)
        );
        $all = Accounting::report()->balanceSheet($fy);

        $this->assertAmount(15, $one->totalAssets);
        $this->assertAmount(100, $all->totalAssets);
        $this->assertEquation($one);
        $this->assertEquation($all);
    }

    public function test_multiple_fiscal_years_do_not_leak(): void
    {
        $fy1 = $this->createActiveFiscalYear('FY 2025', '2025-01-01', '2025-12-31', current: true);
        $fy2 = $this->createActiveFiscalYear('FY 2026', '2026-01-01', '2026-12-31', current: false);
        $chart = $this->createPostableChart();
        $this->postJournal($fy1, $chart['detail'], $chart['detail2'], 200, '2025-03-01');
        $this->postJournal($fy2, $chart['detail'], $chart['detail2'], 30, '2026-03-01');

        $y1 = Accounting::report()->balanceSheet($fy1);
        $y2 = Accounting::report()->balanceSheet($fy2);

        $this->assertAmount(200, $y1->totalAssets);
        $this->assertAmount(30, $y2->totalAssets);
        $this->assertEquation($y1);
        $this->assertEquation($y2);
    }
}
