<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Reporting\AccountTurnoverReport;
use Karnoweb\Accounting\Reporting\ReportPagination;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class AccountTurnoverLevelTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_levels_one_two_three_roll_up_posting_activity(): void
    {
        $world = $this->turnoverWorld();
        $base = [
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'include_zero_activity' => true,
        ];

        $l1 = $this->report()->accountTurnover($base + [
            'level' => 1,
            'parent_id' => null,
        ], $this->offsetPage());
        $assetGroup = $this->rowById($l1->data, $world['asset']['group']->id);
        $this->assertNotNull($assetGroup);
        $this->assertAmountEq(500, $assetGroup->periodDebitTurnover);
        $this->assertAmountEq(200, $assetGroup->periodCreditTurnover);
        $this->assertNotNull($assetGroup->childrenCount);

        $l2 = $this->report()->accountTurnover($base + [
            'level' => 2,
            'parent_id' => $world['asset']['group']->id,
        ], $this->offsetPage());
        $general = $this->rowById($l2->data, $world['asset']['general']->id);
        $this->assertAmountEq(500, $general->periodDebitTurnover);

        $l3 = $this->report()->accountTurnover($base + [
            'level' => 3,
            'parent_id' => $world['asset']['general']->id,
        ], $this->offsetPage());
        $sub = $this->rowById($l3->data, $world['asset']['subsidiary']->id);
        $this->assertAmountEq(500, $sub->periodDebitTurnover);
        $this->assertNotNull($sub->level4Count);
        $this->assertTrue($sub->hasLevel4);
    }

    public function test_level_four_pagination_opening_movement_and_totals(): void
    {
        $world = $this->turnoverWorld();
        $this->createExtraLeaves($world, 8);

        $filters = [
            'level' => 4,
            'parent_id' => $world['asset']['subsidiary']->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'include_zero_activity' => true,
        ];

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $page1 = $this->report()->accountTurnover($filters, ReportPagination::offset(1, 3));
        $page2 = $this->report()->accountTurnover($filters, ReportPagination::offset(2, 3));

        $this->assertTrue($this->accountSelectHasLimit($sql));
        $this->assertCount(3, $page1->data);
        $this->assertNotEmpty($page2->data);
        $ids1 = array_map(fn ($row) => $row->accountId, $page1->data);
        $ids2 = array_map(fn ($row) => $row->accountId, $page2->data);
        $this->assertSame([], array_intersect($ids1, $ids2));

        $this->assertTrue(Amount::of($page1->reportTotals['period_debit'])->equals($page2->reportTotals['period_debit']));
        $pageWithoutCash = in_array($world['cash']->id, $ids1, true) ? $page2 : $page1;
        $this->assertTrue(Amount::of($pageWithoutCash->pageTotals['period_debit'])->isZero());
        $this->assertFalse(Amount::of($pageWithoutCash->reportTotals['period_debit'])->isZero());

        $cash = $this->rowById($page1->data, $world['cash']->id)
            ?? $this->rowById($page2->data, $world['cash']->id);
        $this->assertNotNull($cash);
        $this->assertAmountEq(1000, $cash->openingDebit);
        $this->assertAmountEq(500, $cash->periodDebitTurnover);
        $this->assertAmountEq(200, $cash->periodCreditTurnover);
        $this->assertAmountEq(1300, $cash->closingBalance);
        $this->assertSame(3, $cash->level);
        $this->assertSame($world['asset']['subsidiary']->id, $cash->parentId);
    }

    public function test_level_four_branch_cost_center_dates_and_search(): void
    {
        $world = $this->turnoverWorld();
        $base = [
            'level' => 4,
            'parent_id' => $world['asset']['subsidiary']->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'include_zero_activity' => true,
        ];

        $a = $this->report()->accountTurnover($base + ['branch_id' => 1], $this->offsetPage());
        $b = $this->report()->accountTurnover($base + ['branch_id' => 2], $this->offsetPage());
        $all = $this->report()->accountTurnover($base + ['all_branches' => true], $this->offsetPage());
        $sum = Amount::of($a->reportTotals['period_debit'])->add($b->reportTotals['period_debit']);
        $this->assertTrue($sum->equals($all->reportTotals['period_debit']));

        $center = $this->report()->accountTurnover($base + [
            'branch_id' => 1,
            'cost_center_id' => $world['center']->id,
        ], $this->offsetPage());
        $this->assertAmountEq(500, $center->reportTotals['period_debit']);

        $fy = $this->report()->accountTurnover([
            'level' => 4,
            'parent_id' => $world['asset']['subsidiary']->id,
            'fiscal_year_id' => $world['fy2026']->id,
            'branch_id' => 1,
            'include_zero_activity' => true,
        ], $this->offsetPage());
        $this->assertAmountEq(500, $fy->reportTotals['period_debit']);

        $period = $this->report()->accountTurnover([
            'level' => 4,
            'parent_id' => $world['asset']['subsidiary']->id,
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
            'include_zero_activity' => true,
        ], $this->offsetPage());
        $this->assertAmountEq(500, $period->reportTotals['period_debit']);

        $search = $this->report()->accountTurnover($base + [
            'branch_id' => 1,
            'search' => $world['cash']->code,
        ], $this->offsetPage());
        $this->assertSame([$world['cash']->id], array_map(fn ($row) => $row->accountId, $search->data));

        $code = $this->report()->accountTurnover($base + [
            'branch_id' => 1,
            'code' => $world['cash']->code,
        ], $this->offsetPage());
        $this->assertSame([$world['cash']->id], array_map(fn ($row) => $row->accountId, $code->data));
    }

    public function test_level_four_reversal_and_void(): void
    {
        $world = $this->turnoverWorld();
        $filters = [
            'level' => 4,
            'parent_id' => $world['asset']['subsidiary']->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'include_zero_activity' => true,
        ];

        $doc = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 80, '2026-01-25', branchId: 1);
        $doc->reverse('level-4');
        $afterReverse = $this->report()->accountTurnover($filters, $this->offsetPage());
        $this->assertAmountEq(580, $afterReverse->reportTotals['period_debit']);

        $voidable = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 30, '2026-01-26', branchId: 1);
        $voidable->void('level-4');
        $afterVoid = $this->report()->accountTurnover($filters, $this->offsetPage());
        $this->assertAmountEq(580, $afterVoid->reportTotals['period_debit']);
    }

    public function test_default_path_still_matches_unleveled_compiled_rows(): void
    {
        $world = $this->turnoverWorld();
        $filters = $this->filters([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ]);

        $compiled = (new AccountTurnoverReport($filters, $this->offsetPage()))->compiled();
        $cash = null;
        foreach ($compiled[0] as $row) {
            if ($row->accountId === $world['cash']->id) {
                $cash = $row;
            }
        }

        $this->assertNotNull($cash);
        $this->assertAmountEq(1300, $cash->closingBalance);
    }

    private function createExtraLeaves(array $world, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            \Karnoweb\Accounting\Facades\Accounting::account()->create([
                'parent_id' => $world['asset']['subsidiary']->id,
                'title' => 'Extra leaf '.$i,
                'type' => $world['cash']->type,
            ]);
        }
    }

    /** @param  list<\Karnoweb\Accounting\Reporting\AccountTurnoverRow>  $rows */
    private function rowById(array $rows, int $id): ?\Karnoweb\Accounting\Reporting\AccountTurnoverRow
    {
        foreach ($rows as $row) {
            if ($row->accountId === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @param  list<string>  $sql */
    private function accountSelectHasLimit(array $sql): bool
    {
        foreach ($sql as $statement) {
            $normalized = strtolower($statement);
            if (str_contains($normalized, 'acc_accounts')
                && str_starts_with(ltrim($normalized), 'select')
                && ! str_contains($normalized, 'count(')
                && preg_match('/limit/i', $statement)
            ) {
                return true;
            }
        }

        return false;
    }
}
