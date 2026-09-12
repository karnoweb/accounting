<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class CrossReportReconciliationTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_journal_daily_turnover_and_trial_balance_agree(): void
    {
        $world = $this->turnoverWorld();
        $original = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 17, '2026-01-23', branchId: 1);
        $original->reverse('x');
        $voided = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 9, '2026-01-23', branchId: 1);
        $voided->void('x');

        $scopes = [
            ['from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'branch_id' => 1],
            ['from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'all_branches' => true],
            ['from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'branch_id' => 1, 'cost_center_id' => $world['center']->id],
            ['from_date' => '2026-01-10', 'to_date' => '2026-01-20', 'branch_id' => 1],
        ];

        foreach ($scopes as $filters) {
            $journal = $this->report()->journalBook($filters, $this->cursorPage(200));
            $daily = $this->report()->dailyJournal($filters, $this->cursorPage(200));
            $turnover = $this->report()->accountTurnover($filters, $this->offsetPage(1, 200));

            $query = LedgerQuery::make()->from($filters['from_date'])->to($filters['to_date']);
            if (isset($filters['branch_id'])) {
                $query->branch($filters['branch_id']);
            }
            if (! empty($filters['all_branches'])) {
                $query->allBranches();
            }
            if (isset($filters['cost_center_id'])) {
                $query->costCenter($filters['cost_center_id']);
            }
            $tb = $this->report()->trialBalanceDetailed($query);
            $tbTotals = $tb->totals();

            $this->assertTrue(
                Amount::of($journal->reportTotals['total_debit'])->equals($daily->reportTotals['total_debit']),
                'Journal vs Daily debit: '.json_encode($filters)
            );
            $this->assertTrue(
                Amount::of($journal->reportTotals['total_debit'])->equals($turnover->reportTotals['period_debit']),
                'Journal vs Turnover debit: '.json_encode($filters)
            );
            $this->assertTrue(
                Amount::of($journal->reportTotals['total_credit'])->equals($daily->reportTotals['total_credit'])
            );
            $this->assertTrue(
                Amount::of($journal->reportTotals['total_credit'])->equals($turnover->reportTotals['period_credit'])
            );
            $this->assertTrue(
                Amount::of($journal->reportTotals['total_debit'])->equals($tbTotals['period_debit'])
            );
        }
    }
}
