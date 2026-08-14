<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\BalanceService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Tests\TestCase;

class TurnoverTest extends TestCase
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

    // 1. date range filtering
    public function test_date_range_filtering(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-06-05', $chart['detail'], $chart['detail2'], 90);

        $turnover = app(BalanceService::class)->getTurnover($chart['detail'], '2025-01-01', '2025-03-31');

        $this->assertEqualsWithDelta(10.0, $turnover['debit'], 0.001);
    }

    // 2. FY filtering
    public function test_fiscal_year_filtering(): void
    {
        $fy2025 = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31', true);
        $fy2026 = FiscalYear::create([
            'title' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::ACTIVE, 'is_current' => false,
        ]);
        $chart = $this->createPostableChart();
        $this->postDocument($fy2025, '2025-06-01', $chart['detail'], $chart['detail2'], 7);
        $this->postDocument($fy2026, '2026-06-01', $chart['detail'], $chart['detail2'], 13);

        $turnover = app(BalanceService::class)->getTurnover(
            $chart['detail'], '2025-01-01', '2026-12-31', ['fiscal_year' => $fy2025]
        );

        $this->assertEqualsWithDelta(7.0, $turnover['debit'], 0.001);
    }

    // 3. branch filtering
    public function test_branch_filtering(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 4, 1);
        $this->postDocument($fy, '2025-01-06', $chart['detail'], $chart['detail2'], 9, 2);

        $turnover = app(BalanceService::class)->getTurnover(
            $chart['detail'], '2025-01-01', '2025-12-31', ['branch_id' => 1]
        );

        $this->assertEqualsWithDelta(4.0, $turnover['debit'], 0.001);
    }

    // 4. debit
    public function test_debit_total(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 15);
        $this->postDocument($fy, '2025-01-06', $chart['detail2'], $chart['detail'], 5);

        $turnover = app(BalanceService::class)->getTurnover($chart['detail'], '2025-01-01', '2025-12-31');

        $this->assertEqualsWithDelta(15.0, $turnover['debit'], 0.001);
    }

    // 5. credit
    public function test_credit_total(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 15);
        $this->postDocument($fy, '2025-01-06', $chart['detail2'], $chart['detail'], 5);

        $turnover = app(BalanceService::class)->getTurnover($chart['detail'], '2025-01-01', '2025-12-31');

        $this->assertEqualsWithDelta(5.0, $turnover['credit'], 0.001);
    }

    // 6. signed balance
    public function test_signed_balance(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 15);
        $this->postDocument($fy, '2025-01-06', $chart['detail2'], $chart['detail'], 5);

        $turnover = app(BalanceService::class)->getTurnover($chart['detail'], '2025-01-01', '2025-12-31');

        $this->assertEqualsWithDelta(10.0, $turnover['balance'], 0.001);
    }

    public function test_backward_compatible_three_argument_call_unaffected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 42);

        $turnover = app(BalanceService::class)->getTurnover($chart['detail'], '2025-01-01', '2025-12-31');

        $this->assertSame(['debit', 'credit', 'balance'], array_keys($turnover));
        $this->assertEqualsWithDelta(42.0, $turnover['debit'], 0.001);
    }
}
