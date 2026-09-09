<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use InvalidArgumentException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Tests\TestCase;

class ReportPaginationTest extends TestCase
{
    private function postDocument(
        FiscalYear $fy,
        string $date,
        Account $debitAccount,
        Account $creditAccount,
        float $amount,
        ?int $branchId = null,
        ?int $costCenterId = null
    ): void {
        $items = $this->balancedItems($debitAccount, $creditAccount, $amount);
        if ($costCenterId !== null) {
            $items[0]['cost_center_id'] = $costCenterId;
            $items[1]['cost_center_id'] = $costCenterId;
        }

        app(DocumentService::class)->post(app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'items' => $items,
        ]));
    }

    public function test_account_statement_paginated_matches_full_statement_meta(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-01-10', $chart['detail'], $chart['detail2'], 5);
        $this->postDocument($fy, '2025-01-15', $chart['detail'], $chart['detail2'], 7);

        $query = LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy);
        $full = Accounting::report()->accountStatement($query);
        $page = Accounting::report()->accountStatementPaginated(clone $query, page: 1, perPage: 2);

        $this->assertEqualsWithDelta($full->openingBalance, $page->openingBalance, 0.001);
        $this->assertEqualsWithDelta($full->closingBalance, $page->closingBalance, 0.001);
        $this->assertSame(3, $page->lines->total());
        $this->assertCount(2, $page->lines->items());
        $this->assertEquals([10.0, 15.0], collect($page->lines->items())->pluck('runningBalance')->all());
    }

    public function test_account_statement_page_two_continues_running_balance(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-01-10', $chart['detail'], $chart['detail2'], 5);
        $this->postDocument($fy, '2025-01-15', $chart['detail'], $chart['detail2'], 7);

        $query = LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy);
        $page2 = Accounting::report()->accountStatementPaginated($query, page: 2, perPage: 2);

        $this->assertCount(1, $page2->lines->items());
        $this->assertEqualsWithDelta(22.0, $page2->lines->items()[0]->runningBalance, 0.001);
        $this->assertEqualsWithDelta(22.0, $page2->closingBalance, 0.001);
    }

    public function test_account_statement_per_page_minus_one_returns_all(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-01-10', $chart['detail'], $chart['detail2'], 5);

        $page = Accounting::report()->accountStatementPaginated(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy),
            page: 1,
            perPage: -1
        );

        $this->assertSame(2, $page->lines->total());
        $this->assertCount(2, $page->lines->items());
    }

    public function test_account_statement_paginated_requires_one_account(): void
    {
        $chart = $this->createPostableChart();

        $this->expectException(InvalidArgumentException::class);
        Accounting::report()->accountStatementPaginated(
            LedgerQuery::make()->forAccounts([$chart['detail'], $chart['detail2']])
        );
    }

    public function test_cost_center_statement_paginated(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $center = CostCenter::create(['code' => 'CC1', 'title' => 'Ops']);
        $other = CostCenter::create(['code' => 'CC2', 'title' => 'Other']);

        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10, costCenterId: $center->id);
        $this->postDocument($fy, '2025-01-06', $chart['detail'], $chart['detail2'], 20, costCenterId: $center->id);
        $this->postDocument($fy, '2025-01-07', $chart['detail'], $chart['detail2'], 99, costCenterId: $other->id);

        $page = Accounting::report()->costCenterStatementPaginated(
            LedgerQuery::make()->costCenter($center)->forFiscalYear($fy),
            page: 1,
            perPage: 2
        );

        $this->assertSame($center->id, $page->costCenterId);
        $this->assertSame(4, $page->lines->total()); // 2 docs × 2 lines
        $this->assertCount(2, $page->lines->items());
        $this->assertEqualsWithDelta(30.0, $page->periodTotals['debit'], 0.001);
        $this->assertEqualsWithDelta(30.0, $page->periodTotals['credit'], 0.001);
    }

    public function test_cost_center_statement_requires_cost_center(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Accounting::report()->costCenterStatementPaginated(LedgerQuery::make());
    }

    public function test_general_ledger_summary_paginates_accounts(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chartA = $this->createPostableChart('1');
        $chartB = $this->createPostableChart('2');
        $this->postDocument($fy, '2025-01-05', $chartA['detail'], $chartA['detail2'], 10);
        $this->postDocument($fy, '2025-01-06', $chartB['detail'], $chartB['detail2'], 25);

        $summary = Accounting::report()->generalLedgerSummary(
            LedgerQuery::make()->forAccounts([
                $chartA['detail']->id,
                $chartA['detail2']->id,
                $chartB['detail']->id,
                $chartB['detail2']->id,
            ])->forFiscalYear($fy),
            page: 1,
            perPage: 2
        );

        $this->assertSame(4, $summary->accounts->total());
        $this->assertCount(2, $summary->accounts->items());

        $first = $summary->accounts->items()[0];
        $this->assertSame($chartA['detail']->code, $first->code);
        $this->assertEqualsWithDelta(10.0, $first->periodDebit, 0.001);
        $this->assertEqualsWithDelta(10.0, $first->closingBalance, 0.001);
    }
}
