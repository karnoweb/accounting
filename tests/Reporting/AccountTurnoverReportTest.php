<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Reporting\AccountTurnoverReport;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class AccountTurnoverReportTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_opening_period_and_closing_for_branch_a(): void
    {
        $world = $this->turnoverWorld();
        $filters = [
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ];

        $cash = $this->turnoverRow($filters, $world['cash']);
        $this->assertNotNull($cash);
        $this->assertAmountEq(1000, $cash->openingDebit);
        $this->assertAmountEq(0, $cash->openingCredit);
        $this->assertAmountEq(1000, $cash->openingBalance);
        $this->assertAmountEq(500, $cash->periodDebitTurnover);
        $this->assertAmountEq(200, $cash->periodCreditTurnover);
        $this->assertAmountEq(1300, $cash->closingBalance);

        $revenue = $this->turnoverRow($filters, $world['revenue']);
        $this->assertAmountEq(0, $revenue->openingBalance);
        $this->assertAmountEq(500, $revenue->periodCreditTurnover);
        $this->assertAmountEq(-500, $revenue->closingBalance);

        $expense = $this->turnoverRow($filters, $world['expense']);
        $this->assertAmountEq(0, $expense->openingBalance);
        $this->assertAmountEq(200, $expense->periodDebitTurnover);
        $this->assertAmountEq(200, $expense->closingBalance);
    }

    public function test_branch_b_is_independent(): void
    {
        $world = $this->turnoverWorld();
        $cash = $this->turnoverRow([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 2,
        ], $world['cash']);

        $this->assertAmountEq(400, $cash->openingBalance);
        $this->assertAmountEq(100, $cash->periodDebitTurnover);
        $this->assertAmountEq(50, $cash->periodCreditTurnover);
        $this->assertAmountEq(450, $cash->closingBalance);
    }

    public function test_selected_and_all_branches_equal_sum_of_individuals(): void
    {
        $this->turnoverWorld();
        $base = ['from_date' => '2026-01-01', 'to_date' => '2026-01-31'];

        $a = $this->report()->accountTurnover($base + ['branch_id' => 1], $this->offsetPage());
        $b = $this->report()->accountTurnover($base + ['branch_id' => 2], $this->offsetPage());
        $selected = $this->report()->accountTurnover($base + ['branch_ids' => [1, 2]], $this->offsetPage());
        $all = $this->report()->accountTurnover($base + ['all_branches' => true], $this->offsetPage());

        foreach (['period_debit', 'period_credit', 'opening_debit', 'closing_debit'] as $key) {
            $sum = Amount::of($a->reportTotals[$key])->add($b->reportTotals[$key]);
            $this->assertTrue($sum->equals($selected->reportTotals[$key]), $key.' selected');
            $this->assertTrue($sum->equals($all->reportTotals[$key]), $key.' all');
        }
    }

    public function test_branch_breakdown_matches_consolidated(): void
    {
        $this->turnoverWorld();
        $result = $this->report()->accountTurnover([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'all_branches' => true,
            'include_branch_breakdown' => true,
        ], $this->offsetPage());

        $debit = Amount::zero();
        $credit = Amount::zero();
        foreach ($result->branches as $branch) {
            $debit = $debit->add($branch['debit']);
            $credit = $credit->add($branch['credit']);
        }

        $this->assertTrue($debit->equals($result->reportTotals['period_debit']));
        $this->assertTrue($credit->equals($result->reportTotals['period_credit']));
    }

    public function test_cost_center_and_date_boundaries(): void
    {
        $world = $this->turnoverWorld();
        $center = $this->report()->accountTurnover([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'cost_center_id' => $world['center']->id,
        ], $this->offsetPage());

        $this->assertAmountEq(700, $center->reportTotals['period_debit']);

        $before = $this->report()->accountTurnover([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-09',
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertAmountEq(0, $before->reportTotals['period_debit']);

        $onFrom = $this->report()->accountTurnover([
            'from_date' => '2026-01-10',
            'to_date' => '2026-01-10',
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertAmountEq(500, $onFrom->reportTotals['period_debit']);

        $after = $this->report()->accountTurnover([
            'from_date' => '2026-01-22',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertAmountEq(0, $after->reportTotals['period_debit']);
    }

    public function test_fiscal_year_and_period_filters(): void
    {
        $world = $this->turnoverWorld();

        $fy2026 = $this->report()->accountTurnover([
            'fiscal_year_id' => $world['fy2026']->id,
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertAmountEq(0, $fy2026->reportTotals['opening_debit']);
        $this->assertAmountEq(700, $fy2026->reportTotals['period_debit']);

        $jan = $this->report()->accountTurnover([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertAmountEq(700, $jan->reportTotals['period_debit']);
    }

    public function test_closed_fiscal_year_still_reports(): void
    {
        $world = $this->turnoverWorld();
        $world['fy2025']->update([
            'status' => FiscalYearStatus::CLOSED,
            'closed_at' => now(),
            'is_current' => false,
        ]);

        $result = $this->report()->accountTurnover([
            'fiscal_year_id' => $world['fy2025']->id,
            'branch_id' => 1,
        ], $this->offsetPage());

        $this->assertAmountEq(1000, $result->reportTotals['period_debit']);
    }

    public function test_hierarchy_rollup_does_not_double_count(): void
    {
        $world = $this->turnoverWorld();
        $rows = $this->turnoverRows([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'rollup_hierarchy' => true,
        ]);

        $parent = null;
        foreach ($rows as $row) {
            if ($row->accountId === $world['asset']['subsidiary']->id) {
                $parent = $row;
            }
        }

        $this->assertNotNull($parent);
        $this->assertAmountEq(500, $parent->periodDebitTurnover);
        $this->assertAmountEq(200, $parent->periodCreditTurnover);
    }

    public function test_zero_account_inclusion_and_exclusion(): void
    {
        $world = $this->turnoverWorld();
        $unused = $world['asset']['detail2'];

        $excluded = $this->turnoverRow([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $unused);
        $this->assertNull($excluded);

        $included = $this->turnoverRow([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'account_id' => $unused->id,
            'include_zero_activity' => true,
            'include_zero_balance' => true,
        ], $unused);
        $this->assertNotNull($included);
        $this->assertAmountEq(0, $included->closingBalance);
    }

    public function test_reversal_and_void_follow_ledger_semantics(): void
    {
        $world = $this->turnoverWorld();
        $doc = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 80, '2026-01-25', branchId: 1);
        $doc->reverse('test');

        $afterReverse = $this->report()->accountTurnover([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertAmountEq(860, $afterReverse->reportTotals['period_debit']);

        $voidable = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 30, '2026-01-26', branchId: 1);
        $voidable->void('test');
        $afterVoid = $this->report()->accountTurnover([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertAmountEq(860, $afterVoid->reportTotals['period_debit']);
    }

    public function test_pagination_does_not_change_report_totals(): void
    {
        $world = $this->turnoverWorld();
        $filters = $this->filters([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'include_zero_activity' => true,
            'include_zero_balance' => true,
            'account_ids' => [$world['cash']->id, $world['revenue']->id, $world['expense']->id],
        ]);

        $page1 = (new AccountTurnoverReport($filters, $this->offsetPage(1, 2)))->build();
        $page2 = (new AccountTurnoverReport($filters, $this->offsetPage(2, 2)))->build();

        $this->assertCount(2, $page1->data);
        $this->assertCount(1, $page2->data);
        $this->assertTrue(Amount::of($page1->reportTotals['period_debit'])->equals($page2->reportTotals['period_debit']));
        $this->assertFalse(Amount::of($page1->pageTotals['period_debit'])->equals($page1->reportTotals['period_debit']));

        $ids1 = array_map(fn ($row) => $row->accountId, $page1->data);
        $ids2 = array_map(fn ($row) => $row->accountId, $page2->data);
        $this->assertSame([], array_intersect($ids1, $ids2));

        $codes = array_map(fn ($row) => $row->accountCode, array_merge($page1->data, $page2->data));
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes);
    }
}
