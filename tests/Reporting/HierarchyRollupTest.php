<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Tests\TestCase;

class HierarchyRollupTest extends TestCase
{
    /**
     * group (L0)
     *  |- general1 (L1)
     *  |   |- sub1 (L2)
     *  |   |   |- detail1 (L3)
     *  |   |   `- detail2 (L3)
     *  |   `- sub2 (L2)
     *  |       `- detail3 (L3)
     *  `- general2 (L1)
     *      `- sub3 (L2)
     *          `- detail4 (L3)
     *
     * @return array{group: Account, general1: Account, general2: Account, sub1: Account, sub2: Account, sub3: Account, detail1: Account, detail2: Account, detail3: Account, detail4: Account}
     */
    private function createDeepChart(): array
    {
        $service = app(AccountService::class);

        $group = $service->create(['code' => '9', 'title' => 'Group', 'type' => 'asset']);
        $general1 = $service->create(['parent_id' => $group->id, 'title' => 'General 1', 'type' => 'asset']);
        $general2 = $service->create(['parent_id' => $group->id, 'title' => 'General 2', 'type' => 'asset']);
        $sub1 = $service->create(['parent_id' => $general1->id, 'title' => 'Sub 1', 'type' => 'asset']);
        $sub2 = $service->create(['parent_id' => $general1->id, 'title' => 'Sub 2', 'type' => 'asset']);
        $sub3 = $service->create(['parent_id' => $general2->id, 'title' => 'Sub 3', 'type' => 'asset']);
        $detail1 = $service->create(['parent_id' => $sub1->id, 'title' => 'Detail 1', 'type' => 'asset']);
        $detail2 = $service->create(['parent_id' => $sub1->id, 'title' => 'Detail 2', 'type' => 'asset']);
        $detail3 = $service->create(['parent_id' => $sub2->id, 'title' => 'Detail 3', 'type' => 'asset']);
        $detail4 = $service->create(['parent_id' => $sub3->id, 'title' => 'Detail 4', 'type' => 'asset']);

        return compact('group', 'general1', 'general2', 'sub1', 'sub2', 'sub3', 'detail1', 'detail2', 'detail3', 'detail4');
    }

    private function postDocument(FiscalYear $fy, string $date, Account $debitAccount, Account $creditAccount, float $amount): void
    {
        app(DocumentService::class)->post(app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($debitAccount, $creditAccount, $amount),
        ]));
    }

    public function test_hierarchy_rollup_at_every_level(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createDeepChart();

        $this->postDocument($fy, '2025-01-05', $chart['detail1'], $chart['detail4'], 100);
        $this->postDocument($fy, '2025-01-06', $chart['detail2'], $chart['detail3'], 50);
        $this->postDocument($fy, '2025-01-07', $chart['detail3'], $chart['detail1'], 30);

        $report = Accounting::report()->trialBalanceDetailed($fy);

        // 1. L3 direct values
        $detail1 = $report->find($chart['detail1']->id);
        $this->assertEqualsWithDelta(100.0, $detail1->periodDebit, 0.001);
        $this->assertEqualsWithDelta(30.0, $detail1->periodCredit, 0.001);

        $detail3 = $report->find($chart['detail3']->id);
        $this->assertEqualsWithDelta(30.0, $detail3->periodDebit, 0.001);
        $this->assertEqualsWithDelta(50.0, $detail3->periodCredit, 0.001);

        // 2. L2 aggregation: sub1 = detail1 + detail2
        $sub1 = $report->find($chart['sub1']->id);
        $this->assertEqualsWithDelta(150.0, $sub1->periodDebit, 0.001); // 100 + 50
        $this->assertEqualsWithDelta(30.0, $sub1->periodCredit, 0.001);

        $sub3 = $report->find($chart['sub3']->id);
        $this->assertEqualsWithDelta(0.0, $sub3->periodDebit, 0.001);
        $this->assertEqualsWithDelta(100.0, $sub3->periodCredit, 0.001);

        // 3. L1 aggregation: general1 = sub1 + sub2
        $general1 = $report->find($chart['general1']->id);
        $this->assertEqualsWithDelta(180.0, $general1->periodDebit, 0.001); // 150 + 30
        $this->assertEqualsWithDelta(80.0, $general1->periodCredit, 0.001); // 30 + 50

        $general2 = $report->find($chart['general2']->id);
        $this->assertEqualsWithDelta(0.0, $general2->periodDebit, 0.001);
        $this->assertEqualsWithDelta(100.0, $general2->periodCredit, 0.001);

        // 4. L0 aggregation: group = general1 + general2
        $group = $report->find($chart['group']->id);
        $this->assertEqualsWithDelta(180.0, $group->periodDebit, 0.001);
        $this->assertEqualsWithDelta(180.0, $group->periodCredit, 0.001);

        // Sanity: parent metrics equal the sum of descendant L3 metrics, for every metric.
        $leaves = [$chart['detail1'], $chart['detail2'], $chart['detail3'], $chart['detail4']];
        $sumLeaf = fn (string $field) => array_sum(array_map(
            fn (Account $a) => $report->find($a->id)->{$field},
            $leaves
        ));

        $this->assertEqualsWithDelta($sumLeaf('periodDebit'), $group->periodDebit, 0.001);
        $this->assertEqualsWithDelta($sumLeaf('periodCredit'), $group->periodCredit, 0.001);
        $this->assertEqualsWithDelta($sumLeaf('openingDebit'), $group->openingDebit, 0.001);
        $this->assertEqualsWithDelta($sumLeaf('endingDebit'), $group->endingDebit, 0.001);
    }
}
