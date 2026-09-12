<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class DailyJournalReportTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_daily_grouping_and_ordering(): void
    {
        $world = $this->turnoverWorld();
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 100, '2026-01-10', branchId: 1);
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 300, '2026-01-10', branchId: 1);
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 50, '2026-01-11', branchId: 1);

        $result = $this->report()->dailyJournal([
            'from_date' => '2026-01-10',
            'to_date' => '2026-01-11',
            'branch_id' => 1,
            'group_by' => 'day',
        ], $this->cursorPage());

        $this->assertSame('2026-01-11', $result->data[0]->date);
        $this->assertSame('2026-01-10', $result->data[1]->date);
        $this->assertSame(1, $result->data[0]->documentCount);
        $this->assertAmountEq(50, $result->data[0]->totalDebit);

        $jan10 = $result->data[1];
        $this->assertSame(3, $jan10->documentCount);
        $this->assertAmountEq(900, $jan10->totalDebit);
        $this->assertTrue(Amount::of($jan10->netDifference)->isZero());
    }

    public function test_day_branch_and_all_branches(): void
    {
        $this->turnoverWorld();
        $consolidated = $this->report()->dailyJournal([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'all_branches' => true,
            'group_by' => 'day',
        ], $this->cursorPage());

        $broken = $this->report()->dailyJournal([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'all_branches' => true,
            'group_by' => 'day_branch',
            'include_branch_breakdown' => true,
        ], $this->cursorPage());

        $this->assertNotEmpty($broken->data);
        $this->assertNotNull($broken->data[0]->branchId);
        $this->assertTrue(Amount::of($consolidated->reportTotals['total_debit'])->equals($broken->reportTotals['total_debit']));

        $a = $this->report()->dailyJournal([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->cursorPage());
        $b = $this->report()->dailyJournal([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 2,
        ], $this->cursorPage());
        $this->assertTrue(
            Amount::of($a->reportTotals['total_debit'])->add($b->reportTotals['total_debit'])
                ->equals($consolidated->reportTotals['total_debit'])
        );
    }

    public function test_filters_and_status_matrix(): void
    {
        $world = $this->turnoverWorld();
        $this->createDraft($world['fy2026'], $world['cash'], $world['revenue'], 40, '2026-01-12', 1);
        $voided = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 7, '2026-01-12', branchId: 1);
        $voided->void('x');
        $posted = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 8, '2026-01-12', branchId: 1);
        $posted->reverse('x');

        $financial = $this->report()->dailyJournal([
            'from_date' => '2026-01-12',
            'to_date' => '2026-01-12',
            'branch_id' => 1,
            'fiscal_year_id' => $world['fy2026']->id,
        ], $this->cursorPage());

        $this->assertCount(1, $financial->data);
        $this->assertSame(2, $financial->data[0]->documentCount);
        $this->assertAmountEq(16, $financial->data[0]->totalDebit);

        $byType = $this->report()->dailyJournal([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'document_type' => 'adjustment',
            'account_id' => $world['expense']->id,
            'cost_center_id' => $world['center']->id,
            'accounting_period_id' => $world['jan']->id,
        ], $this->cursorPage());
        $this->assertNotEmpty($byType->data);
    }

    public function test_pagination_by_day_keeps_report_totals(): void
    {
        $world = $this->turnoverWorld();
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 5, '2026-01-05', branchId: 1);
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 6, '2026-01-06', branchId: 1);
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 7, '2026-01-07', branchId: 1);

        $page1 = $this->report()->dailyJournal([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->cursorPage(2));
        $page2 = $this->report()->dailyJournal([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->cursorPage(2, $page1->pagination->nextCursor));

        $dates1 = array_map(fn ($row) => $row->date, $page1->data);
        $dates2 = array_map(fn ($row) => $row->date, $page2->data);
        $this->assertSame([], array_intersect($dates1, $dates2));
        $this->assertTrue(Amount::of($page1->reportTotals['total_debit'])->equals($page2->reportTotals['total_debit']));
    }
}
