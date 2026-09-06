<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Karnoweb\Accounting\Enums\AccountingPeriodStatus;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Events\AccountingPeriodClosed;
use Karnoweb\Accounting\Events\AccountingPeriodOpened;
use Karnoweb\Accounting\Events\PostingRejectedForClosedPeriod;
use Karnoweb\Accounting\Exceptions\AccountingPeriodOverlapException;
use Karnoweb\Accounting\Exceptions\AccountingPeriodStateException;
use Karnoweb\Accounting\Exceptions\ClosedAccountingPeriodException;
use Karnoweb\Accounting\Exceptions\InvalidAccountingPeriodException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountingPeriodService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\PostingService;
use Karnoweb\Accounting\Services\ReportService;

class AccountingPeriodTest extends TestCase
{
    private function periods(): AccountingPeriodService
    {
        return app(AccountingPeriodService::class);
    }

    private function years(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function posting(): PostingService
    {
        return app(PostingService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function activateYear(
        string $title = 'FY 2025',
        string $start = '2025-01-01',
        string $end = '2025-12-31'
    ): FiscalYear {
        return $this->years()->activate($this->years()->create([
            'title' => $title,
            'start_date' => $start,
            'end_date' => $end,
        ]));
    }

    public function test_facade_resolves_period_service(): void
    {
        $this->assertInstanceOf(AccountingPeriodService::class, Accounting::period());
        $this->assertSame($this->periods(), Accounting::period());
    }

    public function test_activate_ensures_full_year_open_period(): void
    {
        Event::fake([AccountingPeriodOpened::class]);

        $fy = $this->activateYear();

        $period = AccountingPeriod::query()->forFiscalYear($fy)->first();
        $this->assertNotNull($period);
        $this->assertTrue($period->isOpen());
        $this->assertSame('2025-01-01', $period->start_date->toDateString());
        $this->assertSame('2025-12-31', $period->end_date->toDateString());
        Event::assertDispatched(AccountingPeriodOpened::class);
    }

    public function test_create_valid_period(): void
    {
        $fy = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $period = $this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'Q1',
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-31',
        ]);

        $this->assertTrue($period->isDraft());
        $this->assertSame('Q1', $period->name);
    }

    public function test_create_rejects_invalid_date_range(): void
    {
        $fy = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->expectException(InvalidAccountingPeriodException::class);
        $this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'Bad',
            'start_date' => '2025-03-31',
            'end_date' => '2025-01-01',
        ]);
    }

    public function test_create_rejects_overlap_within_fiscal_year(): void
    {
        $fy = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'Q1',
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-31',
        ]);

        $this->expectException(AccountingPeriodOverlapException::class);
        $this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'Overlap',
            'start_date' => '2025-03-01',
            'end_date' => '2025-04-30',
        ]);
    }

    public function test_create_rejects_period_outside_fiscal_year(): void
    {
        $fy = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->expectException(InvalidAccountingPeriodException::class);
        $this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'Outside',
            'start_date' => '2024-12-01',
            'end_date' => '2025-01-15',
        ]);
    }

    public function test_periods_in_different_fiscal_years_may_share_dates(): void
    {
        $fy1 = $this->years()->create([
            'title' => 'FY A',
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-30',
        ]);
        $fy2 = $this->years()->create([
            'title' => 'FY B',
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ]);

        $p1 = $this->periods()->create([
            'fiscal_year_id' => $fy1->id,
            'name' => 'H1',
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-30',
        ]);
        $p2 = $this->periods()->create([
            'fiscal_year_id' => $fy2->id,
            'name' => 'H2',
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ]);

        $this->assertNotSame($p1->fiscal_year_id, $p2->fiscal_year_id);
    }

    public function test_open_and_close_lifecycle(): void
    {
        Event::fake([AccountingPeriodOpened::class, AccountingPeriodClosed::class]);

        config(['accounting.period.auto_create_on_activate' => false]);

        $fy = $this->years()->activate($this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));

        $q1 = $this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'Q1',
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-31',
        ]);
        $opened = $this->periods()->open($q1);
        $this->assertTrue($opened->isOpen());
        Event::assertDispatched(AccountingPeriodOpened::class);

        $closed = $this->periods()->close($opened);
        $this->assertTrue($closed->isClosed());
        Event::assertDispatched(AccountingPeriodClosed::class);
    }

    public function test_cannot_reopen_closed_period(): void
    {
        $fy = $this->activateYear();
        $period = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();
        $this->periods()->close($period);

        $this->expectException(AccountingPeriodStateException::class);
        $this->periods()->open($period->fresh());
    }

    public function test_duplicate_close_fails(): void
    {
        $fy = $this->activateYear();
        $period = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();
        $this->periods()->close($period);

        $this->expectException(AccountingPeriodStateException::class);
        $this->periods()->close($period->fresh());
    }

    public function test_posting_into_open_period_succeeds(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();

        $doc = $this->documents()->create([
            'type' => 'sale',
            'date' => '2025-06-15',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);
        $posted = $this->documents()->post($doc);

        $this->assertSame(DocumentStatus::POSTED, $posted->status);
        $this->assertNotNull($posted->accounting_period_id);
        $this->assertTrue($this->posting()->isAllowed('2025-06-15', $fy));
    }

    public function test_posting_into_closed_period_fails(): void
    {
        Event::fake([PostingRejectedForClosedPeriod::class]);

        $fy = $this->activateYear();
        $period = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();
        $this->periods()->close($period);
        $chart = $this->createPostableChart();

        try {
            $this->documents()->create([
                'type' => 'sale',
                'date' => '2025-06-15',
                'fiscal_year_id' => $fy->id,
                'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
            ]);
            $this->fail('Posting into closed period must fail');
        } catch (ClosedAccountingPeriodException $e) {
            $this->assertSame($period->id, $e->period?->id);
            Event::assertDispatched(PostingRejectedForClosedPeriod::class);
        }
    }

    public function test_posting_outside_any_period_fails(): void
    {
        $fy = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        config(['accounting.period.auto_create_on_activate' => false]);
        $this->years()->activate($fy);

        $this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'Q1',
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-31',
        ]);
        $q1 = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();
        $this->periods()->open($q1);

        $chart = $this->createPostableChart();

        $this->expectException(AccountingPeriodStateException::class);
        $this->documents()->create([
            'type' => 'sale',
            'date' => '2025-06-15',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);
    }

    public function test_canonical_resolve_period(): void
    {
        $fy = $this->activateYear();
        $period = $this->posting()->resolvePeriod($fy, '2025-06-15');

        $this->assertNotNull($period);
        $this->assertSame(
            $this->periods()->resolve($fy, '2025-06-15')?->id,
            $period->id
        );
    }

    public function test_fiscal_year_close_closes_open_periods(): void
    {
        Event::fake([AccountingPeriodClosed::class]);

        $fy = $this->activateYear();
        $period = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();
        $this->assertTrue($period->isOpen());

        $this->years()->close($fy);

        $this->assertTrue($period->fresh()->isClosed());
        Event::assertDispatched(AccountingPeriodClosed::class);
    }

    public function test_close_is_transactional_and_preserves_journals(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $period = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();

        $doc = $this->documents()->post($this->documents()->create([
            'type' => 'sale',
            'date' => '2025-03-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 250),
        ]));

        $itemCount = $doc->items()->count();
        $this->periods()->close($period);

        $doc->refresh();
        $this->assertSame(DocumentStatus::POSTED, $doc->status);
        $this->assertSame($itemCount, $doc->items()->count());
        $this->assertEqualsWithDelta(250.0, (float) $doc->items->first()->amount, 0.001);
        $this->assertTrue($period->fresh()->isClosed());
    }

    public function test_posting_vs_close_concurrency_consistency(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $period = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();

        // Simulate close winning the lock first inside a transaction.
        DB::transaction(function () use ($period) {
            AccountingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $this->periods()->close($period);
        });

        try {
            $this->documents()->create([
                'type' => 'sale',
                'date' => '2025-06-15',
                'fiscal_year_id' => $fy->id,
                'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
            ]);
            $this->fail('Must reject after concurrent close');
        } catch (ClosedAccountingPeriodException) {
            $this->assertSame(0, Document::query()->count());
        }
    }

    public function test_reporting_for_accounting_period_preserves_opening_semantics(): void
    {
        config(['accounting.period.auto_create_on_activate' => false]);

        $fy = $this->years()->activate($this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));

        $p1 = $this->periods()->open($this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'H1',
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-30',
        ]));
        $p2 = $this->periods()->open($this->periods()->create([
            'fiscal_year_id' => $fy->id,
            'name' => 'H2',
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ]));

        $chart = $this->createPostableChart();

        $this->documents()->post($this->documents()->create([
            'type' => 'sale',
            'date' => '2025-02-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 100),
        ]));
        $this->documents()->post($this->documents()->create([
            'type' => 'sale',
            'date' => '2025-08-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 40),
        ]));

        $h2Query = LedgerQuery::make()
            ->forFiscalYear($fy)
            ->forAccountingPeriod($p2)
            ->forAccount($chart['detail']);

        $opening = $h2Query->openingBalances();
        $periodTotals = $h2Query->periodTotals();

        $this->assertEqualsWithDelta(100.0, $opening[$chart['detail']->id] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(40.0, $periodTotals['debit'], 0.001);

        $tb = app(ReportService::class)->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($fy)->forAccountingPeriod($p2)
        );
        $row = $tb->find($chart['detail']->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(100.0, $row->openingDebit, 0.001);
        $this->assertEqualsWithDelta(40.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(140.0, $row->endingDebit, 0.001);

        // H1 activity is period for p1, not opening.
        $h1 = LedgerQuery::make()->forAccountingPeriod($p1)->forAccount($chart['detail'])->periodTotals();
        $this->assertEqualsWithDelta(100.0, $h1['debit'], 0.001);
    }

    public function test_branch_isolation_still_applies_with_periods(): void
    {
        config(['accounting.branch.enabled' => true]);

        $fy = $this->activateYear();
        $chart1 = $this->createPostableChart('1');
        $chart2 = $this->createPostableChart('2');

        $chart1['detail']->update(['branch_id' => 1]);
        $chart1['detail2']->update(['branch_id' => 1]);
        $chart2['detail']->update(['branch_id' => 2]);
        $chart2['detail2']->update(['branch_id' => 2]);

        $this->documents()->post($this->documents()->create([
            'type' => 'sale',
            'date' => '2025-05-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 1,
            'items' => $this->balancedItems($chart1['detail'], $chart1['detail2'], 70),
        ]));
        $this->documents()->post($this->documents()->create([
            'type' => 'sale',
            'date' => '2025-05-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 2,
            'items' => $this->balancedItems($chart2['detail'], $chart2['detail2'], 30),
        ]));

        $b1 = LedgerQuery::make()->forFiscalYear($fy)->branch(1)->forAccount($chart1['detail'])->periodTotals();
        $b2 = LedgerQuery::make()->forFiscalYear($fy)->branch(2)->forAccount($chart2['detail'])->periodTotals();

        $this->assertEqualsWithDelta(70.0, $b1['debit'], 0.001);
        $this->assertEqualsWithDelta(30.0, $b2['debit'], 0.001);
    }

    public function test_assert_allowed_returns_open_period(): void
    {
        $fy = $this->activateYear();
        $period = $this->posting()->assertAllowed('2025-04-01', $fy);

        $this->assertInstanceOf(AccountingPeriod::class, $period);
        $this->assertSame(AccountingPeriodStatus::OPEN, $period->status);
    }
}
