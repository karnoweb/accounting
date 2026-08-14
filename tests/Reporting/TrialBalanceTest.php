<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    private function postDocument(FiscalYear $fy, string $date, Account $debitAccount, Account $creditAccount, float $amount, ?int $branchId = null): void
    {
        app(DocumentService::class)->post(app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'items' => $this->balancedItems($debitAccount, $creditAccount, $amount),
        ]));
    }

    // 1. empty ledger
    public function test_empty_ledger_returns_empty_report(): void
    {
        $report = Accounting::report()->trialBalanceDetailed(LedgerQuery::make()->from('2025-01-01')->to('2025-12-31'));

        $this->assertCount(0, $report->detail());
        $totals = $report->totals();
        $this->assertSame(0.0, $totals['opening_debit']);
        $this->assertSame(0.0, $totals['period_debit']);
        $this->assertSame(0.0, $totals['ending_debit']);
    }

    // 2. one balanced document
    public function test_one_balanced_document(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-03-01', $chart['detail'], $chart['detail2'], 100);

        $report = Accounting::report()->trialBalanceDetailed($fy);

        $row = $report->find($chart['detail']->id);
        $this->assertEqualsWithDelta(100.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $row->periodCredit, 0.001);

        $row2 = $report->find($chart['detail2']->id);
        $this->assertEqualsWithDelta(0.0, $row2->periodDebit, 0.001);
        $this->assertEqualsWithDelta(100.0, $row2->periodCredit, 0.001);
    }

    // 3. multiple documents
    public function test_multiple_documents_accumulate(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 40);
        $this->postDocument($fy, '2025-02-10', $chart['detail'], $chart['detail2'], 60);

        $row = Accounting::report()->trialBalanceDetailed($fy)->find($chart['detail']->id);

        $this->assertEqualsWithDelta(100.0, $row->periodDebit, 0.001);
    }

    // 4. multiple accounts
    public function test_multiple_accounts_each_tracked_independently(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chartA = $this->createPostableChart('1');
        $chartB = $this->createPostableChart('2');
        $this->postDocument($fy, '2025-01-05', $chartA['detail'], $chartA['detail2'], 30);
        $this->postDocument($fy, '2025-01-06', $chartB['detail'], $chartB['detail2'], 70);

        $report = Accounting::report()->trialBalanceDetailed($fy);

        $this->assertEqualsWithDelta(30.0, $report->find($chartA['detail']->id)->periodDebit, 0.001);
        $this->assertEqualsWithDelta(70.0, $report->find($chartB['detail']->id)->periodDebit, 0.001);
    }

    // 5. opening + period
    public function test_opening_plus_period(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 200);

        $query = LedgerQuery::make()->from('2025-02-01')->to('2025-12-31');
        $row = Accounting::report()->trialBalanceDetailed($query)->find($chart['detail']->id);

        $this->assertEqualsWithDelta(200.0, $row->openingDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(200.0, $row->endingDebit, 0.001);
    }

    // 6. FY boundary
    public function test_fy_boundary_dates_are_inclusive(): void
    {
        $fy = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-01', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-12-31', $chart['detail'], $chart['detail2'], 20);

        $row = Accounting::report()->trialBalanceDetailed($fy)->find($chart['detail']->id);

        $this->assertEqualsWithDelta(30.0, $row->periodDebit, 0.001);
    }

    // 7. FY-scoped opening ignores previous FY; date-only still sees it
    public function test_previous_fiscal_year_does_not_contribute_to_fy_scoped_opening(): void
    {
        $fy2024 = $this->createActiveFiscalYear('FY2024', '2024-01-01', '2024-12-31', false);
        $fy2025 = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31', true);
        $chart = $this->createPostableChart();

        $this->postDocument($fy2024, '2024-06-01', $chart['detail'], $chart['detail2'], 500);
        $this->postDocument($fy2025, '2025-03-01', $chart['detail'], $chart['detail2'], 80);

        $scoped = Accounting::report()->trialBalanceDetailed($fy2025)->find($chart['detail']->id);

        $this->assertEqualsWithDelta(0.0, $scoped->openingDebit, 0.001);
        $this->assertEqualsWithDelta(80.0, $scoped->periodDebit, 0.001);
        $this->assertEqualsWithDelta(80.0, $scoped->endingDebit, 0.001);

        $lifetime = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->from('2025-01-01')->to('2025-12-31')
        )->find($chart['detail']->id);

        $this->assertEqualsWithDelta(500.0, $lifetime->openingDebit, 0.001);
        $this->assertEqualsWithDelta(80.0, $lifetime->periodDebit, 0.001);
        $this->assertEqualsWithDelta(580.0, $lifetime->endingDebit, 0.001);
    }

    // 8. closed FY remains readable in its own reports; target FY does not inherit it
    public function test_closed_fiscal_year_remains_readable(): void
    {
        $fy2024 = $this->createActiveFiscalYear('FY2024', '2024-01-01', '2024-12-31', false);
        $chart = $this->createPostableChart();
        $this->postDocument($fy2024, '2024-06-01', $chart['detail'], $chart['detail2'], 300);
        $fy2024->update(['status' => FiscalYearStatus::CLOSED]);

        $fy2025 = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31', true);

        $own = Accounting::report()->trialBalanceDetailed($fy2024)->find($chart['detail']->id);
        $this->assertEqualsWithDelta(0.0, $own->openingDebit, 0.001);
        $this->assertEqualsWithDelta(300.0, $own->periodDebit, 0.001);
        $this->assertEqualsWithDelta(300.0, $own->endingDebit, 0.001);

        $next = Accounting::report()->trialBalanceDetailed($fy2025)->find($chart['detail']->id);
        $this->assertEqualsWithDelta(0.0, $next->openingDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $next->periodDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $next->endingDebit, 0.001);
    }

    public function test_fy_scoped_mid_period_opening_includes_same_fy_prior_activity(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 200);

        $row = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($fy)->from('2025-02-01')->to('2025-12-31')
        )->find($chart['detail']->id);

        $this->assertEqualsWithDelta(200.0, $row->openingDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(200.0, $row->endingDebit, 0.001);
    }

    public function test_fy_scoped_full_year_treats_start_date_opening_as_period(): void
    {
        $fy2024 = $this->createActiveFiscalYear('FY2024', '2024-01-01', '2024-12-31', false);
        $fy2025 = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31', true);
        $chart = $this->createPostableChart();

        $this->postDocument($fy2024, '2024-06-01', $chart['detail'], $chart['detail2'], 500);

        app(DocumentService::class)->post(app(DocumentService::class)->create([
            'type' => 'opening',
            'date' => '2025-01-01',
            'fiscal_year_id' => $fy2025->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 500),
        ]));
        $this->postDocument($fy2025, '2025-03-01', $chart['detail'], $chart['detail2'], 80);

        $fullYear = Accounting::report()->trialBalanceDetailed($fy2025)->find($chart['detail']->id);
        $this->assertEqualsWithDelta(0.0, $fullYear->openingDebit, 0.001);
        $this->assertEqualsWithDelta(580.0, $fullYear->periodDebit, 0.001);
        $this->assertEqualsWithDelta(580.0, $fullYear->endingDebit, 0.001);

        $midYear = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($fy2025)->from('2025-02-01')->to('2025-12-31')
        )->find($chart['detail']->id);
        $this->assertEqualsWithDelta(500.0, $midYear->openingDebit, 0.001);
        $this->assertEqualsWithDelta(80.0, $midYear->periodDebit, 0.001);
        $this->assertEqualsWithDelta(580.0, $midYear->endingDebit, 0.001);
    }

    // 9. branch filter
    public function test_branch_filter_isolates_movements(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 40, 1);
        $this->postDocument($fy, '2025-01-06', $chart['detail'], $chart['detail2'], 90, 2);

        $branch1 = Accounting::report()->trialBalanceDetailed(LedgerQuery::make()->forFiscalYear($fy)->branch(1))
            ->find($chart['detail']->id);
        $branch2 = Accounting::report()->trialBalanceDetailed(LedgerQuery::make()->forFiscalYear($fy)->branch(2))
            ->find($chart['detail']->id);

        $this->assertEqualsWithDelta(40.0, $branch1->periodDebit, 0.001);
        $this->assertEqualsWithDelta(90.0, $branch2->periodDebit, 0.001);
    }

    // 10. voided excluded
    public function test_voided_document_excluded(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->post(app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-03-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 999),
        ]));
        $document->void('mistake');

        $row = Accounting::report()->trialBalanceDetailed($fy)->find($chart['detail']->id);

        $this->assertEqualsWithDelta(0.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $row->endingBalance(), 0.001);
    }

    // 11. zero balances (account never used still appears with zero row)
    public function test_unused_account_shows_zero_row(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $unused = app(AccountService::class)->create([
            'parent_id' => $chart['subsidiary']->id,
            'title' => 'Unused Detail',
            'type' => 'asset',
        ]);

        $report = Accounting::report()->trialBalanceDetailed($fy);
        $row = $report->find($unused->id);

        $this->assertNotNull($row);
        $this->assertSame(0.0, $row->openingDebit);
        $this->assertSame(0.0, $row->periodDebit);
        $this->assertSame(0.0, $row->endingDebit);
    }

    // 12. debit-only account
    public function test_debit_only_account(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 60);
        $this->postDocument($fy, '2025-01-06', $chart['detail'], $chart['detail2'], 40);

        $row = Accounting::report()->trialBalanceDetailed($fy)->find($chart['detail']->id);

        $this->assertEqualsWithDelta(100.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $row->periodCredit, 0.001);
    }

    // 13. credit-only account
    public function test_credit_only_account(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 60);
        $this->postDocument($fy, '2025-01-06', $chart['detail'], $chart['detail2'], 40);

        $row = Accounting::report()->trialBalanceDetailed($fy)->find($chart['detail2']->id);

        $this->assertEqualsWithDelta(0.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(100.0, $row->periodCredit, 0.001);
    }

    // 14. hierarchy rollup (see also HierarchyRollupTest for full-depth coverage)
    public function test_parent_rows_roll_up_children(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 60);

        $report = Accounting::report()->trialBalanceDetailed($fy);

        $subsidiary = $report->find($chart['subsidiary']->id);
        $this->assertEqualsWithDelta(60.0, $subsidiary->periodDebit, 0.001);
        $this->assertEqualsWithDelta(60.0, $subsidiary->periodCredit, 0.001);

        $group = $report->find($chart['group']->id);
        $this->assertEqualsWithDelta(60.0, $group->periodDebit, 0.001);
    }

    // 15-18: reconciliation invariants
    public function test_reconciliation_invariants_hold(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chartA = $this->createPostableChart('1');
        $chartB = $this->createPostableChart('2');
        $this->postDocument($fy, '2025-01-05', $chartA['detail'], $chartA['detail2'], 30);
        $this->postDocument($fy, '2025-02-10', $chartB['detail2'], $chartB['detail'], 55);
        $this->postDocument($fy, '2025-03-15', $chartA['detail'], $chartB['detail'], 15);

        $report = Accounting::report()->trialBalanceDetailed($fy);
        $totals = $report->totals();

        // 15. opening debit == opening credit
        $this->assertEqualsWithDelta($totals['opening_debit'], $totals['opening_credit'], 0.001);
        // 16. period debit == period credit
        $this->assertEqualsWithDelta($totals['period_debit'], $totals['period_credit'], 0.001);
        // 17. ending debit == ending credit
        $this->assertEqualsWithDelta($totals['ending_debit'], $totals['ending_credit'], 0.001);
        // 18. opening + period = ending, per posting-level row
        foreach ($report->detail() as $row) {
            $this->assertEqualsWithDelta($row->endingBalance(), $row->openingBalance() + $row->periodNet(), 0.001);
            $this->assertEqualsWithDelta($row->endingDebit, $row->openingDebit + $row->periodDebit, 0.001);
            $this->assertEqualsWithDelta($row->endingCredit, $row->openingCredit + $row->periodCredit, 0.001);
        }
    }

    public function test_deterministic_order_for_same_date_documents(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-05-01', $chart['detail'], $chart['detail2'], 1);
        $this->postDocument($fy, '2025-05-01', $chart['detail'], $chart['detail2'], 2);
        $this->postDocument($fy, '2025-05-01', $chart['detail'], $chart['detail2'], 3);

        $lines = LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)->get();

        $this->assertSame([1.0, 2.0, 3.0], $lines->pluck('debit')->all());

        $again = LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)->get();
        $this->assertSame($lines->pluck('itemId')->all(), $again->pluck('itemId')->all());
    }
}
