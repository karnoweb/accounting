<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Tests\TestCase;
use InvalidArgumentException;

class AccountStatementTest extends TestCase
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

    // 1. same ledger results as GL for one account
    public function test_matches_general_ledger_for_single_account(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-02-05', $chart['detail'], $chart['detail2'], 20);

        $query = LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy);
        $statement = Accounting::report()->accountStatement($query);
        $glLedger = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)
        )->forAccount($chart['detail']->id);

        $this->assertEquals($glLedger->openingBalance, $statement->openingBalance);
        $this->assertEquals($glLedger->closingBalance, $statement->closingBalance);
        $this->assertCount($glLedger->lines->count(), $statement->lines);
    }

    // 2. opening
    public function test_opening_balance(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 77);

        $statement = Accounting::report()->accountStatement(
            LedgerQuery::make()->forAccount($chart['detail'])->from('2025-06-01')->to('2025-12-31')
        );

        $this->assertEqualsWithDelta(77.0, $statement->openingBalance, 0.001);
    }

    // 3. running balance
    public function test_running_balance_per_line(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-01-10', $chart['detail'], $chart['detail2'], 5);

        $statement = Accounting::report()->accountStatement(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)
        );

        $this->assertEquals([10.0, 15.0], $statement->lines->pluck('runningBalance')->all());
    }

    // 4. closing balance
    public function test_closing_balance(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-01-10', $chart['detail'], $chart['detail2'], 5);

        $statement = Accounting::report()->accountStatement(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)
        );

        $this->assertEqualsWithDelta(15.0, $statement->closingBalance, 0.001);
    }

    // 5. filters (branch)
    public function test_branch_filter(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10, 1);
        $this->postDocument($fy, '2025-01-10', $chart['detail'], $chart['detail2'], 25, 2);

        $statement = Accounting::report()->accountStatement(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)->branch(2)
        );

        $this->assertCount(1, $statement->lines);
        $this->assertEqualsWithDelta(25.0, $statement->closingBalance, 0.001);
    }

    public function test_requires_exactly_one_account(): void
    {
        $chart = $this->createPostableChart();

        $this->expectException(InvalidArgumentException::class);
        Accounting::report()->accountStatement(
            LedgerQuery::make()->forAccounts([$chart['detail'], $chart['detail2']])
        );
    }
}
