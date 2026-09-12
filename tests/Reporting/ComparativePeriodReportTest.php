<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Reporting\ComparisonRow;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class ComparativePeriodReportTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_alignment_and_variance_rules(): void
    {
        $world = $this->turnoverWorld();
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 40, '2026-02-10', branchId: 1);

        $onlyComparison = $world['expenseChart']['detail2'];
        $this->postJournal($world['fy2025'], $onlyComparison, $world['equity'], 15, '2025-06-01', branchId: 1);

        $result = $this->report()->comparePeriods(
            [
                'from_date' => '2026-01-01',
                'to_date' => '2026-01-31',
                'branch_id' => 1,
                'include_zero_activity' => true,
                'include_zero_balance' => true,
            ],
            [
                'from_date' => '2025-01-01',
                'to_date' => '2025-12-31',
                'branch_id' => 1,
                'include_zero_activity' => true,
                'include_zero_balance' => true,
            ],
            $this->offsetPage(1, 200),
        );

        $byId = [];
        foreach ($result->data as $row) {
            $byId[$row->accountId] = $row;
        }

        $this->assertArrayHasKey($world['cash']->id, $byId);
        $this->assertArrayHasKey($onlyComparison->id, $byId);

        $cash = $byId[$world['cash']->id];
        $this->assertAmountEq(500, $cash->currentDebit);
        $this->assertAmountEq(1000, $cash->comparisonDebit);
        $this->assertAmountEq(-500, $cash->debitVariance);
        $this->assertSame('-50.00', $cash->debitVariancePercent);
        $this->assertSame('decreased', $cash->debitVarianceStatus);

        $new = ComparisonRow::variance('120', '0');
        $this->assertNull($new[1]);
        $this->assertSame('new', $new[2]);

        $bothZero = ComparisonRow::variance('0', '0');
        $this->assertSame('0.00', $bothZero[1]);
        $this->assertSame('unchanged', $bothZero[2]);

        $cleared = ComparisonRow::variance('0', '50');
        $this->assertSame('cleared', $cleared[2]);
        $this->assertSame('-100.00', $cleared[1]);
    }

    public function test_branch_all_cost_center_and_pagination_alignment(): void
    {
        $world = $this->turnoverWorld();
        $current = [
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'all_branches' => true,
        ];
        $comparison = [
            'from_date' => '2025-01-01',
            'to_date' => '2025-12-31',
            'all_branches' => true,
        ];

        $page1 = $this->report()->comparePeriods($current, $comparison, $this->offsetPage(1, 2));
        $page2 = $this->report()->comparePeriods($current, $comparison, $this->offsetPage(2, 2));

        $ids1 = array_map(fn ($row) => $row->accountId, $page1->data);
        $ids2 = array_map(fn ($row) => $row->accountId, $page2->data);
        $this->assertSame([], array_intersect($ids1, $ids2));
        $this->assertTrue(Amount::of($page1->reportTotals['current_debit'])->equals($page2->reportTotals['current_debit']));
        $this->assertFalse(Amount::of($page1->pageTotals['current_debit'])->equals($page1->reportTotals['current_debit']));

        $a = $this->report()->comparePeriods(
            [
                'from_date' => '2026-01-01',
                'to_date' => '2026-01-31',
                'branch_id' => 1,
            ],
            [
                'from_date' => '2025-01-01',
                'to_date' => '2025-12-31',
                'branch_id' => 1,
            ],
            $this->offsetPage(1, 200),
        );
        $this->assertGreaterThan(0, Amount::of($a->reportTotals['current_debit'])->compare(0));

        $cc = $this->report()->comparePeriods(
            [
                'from_date' => '2026-01-01',
                'to_date' => '2026-01-31',
                'branch_id' => 1,
                'cost_center_id' => $world['center']->id,
            ],
            [
                'from_date' => '2025-01-01',
                'to_date' => '2025-12-31',
                'branch_id' => 1,
                'cost_center_id' => $world['center']->id,
            ],
            $this->offsetPage(1, 200),
        );
        $this->assertAmountEq(700, $cc->reportTotals['current_debit']);
    }

    public function test_hierarchy_rollup_comparison(): void
    {
        $this->turnoverWorld();
        $result = $this->report()->comparePeriods(
            [
                'from_date' => '2026-01-01',
                'to_date' => '2026-01-31',
                'branch_id' => 1,
                'rollup_hierarchy' => true,
            ],
            [
                'from_date' => '2025-01-01',
                'to_date' => '2025-12-31',
                'branch_id' => 1,
                'rollup_hierarchy' => true,
            ],
            $this->offsetPage(1, 200),
        );

        $this->assertGreaterThan(3, $result->summary['row_count']);
    }
}
