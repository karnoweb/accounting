<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;
use Karnoweb\Accounting\Reporting\BranchScope;
use Karnoweb\Accounting\Reporting\LedgerReportFilters;
use Karnoweb\Accounting\Reporting\ReportPagination;
use Karnoweb\Accounting\Tests\TestCase;

class LedgerReportFiltersTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_rejects_from_after_to(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from(['from_date' => '2026-02-01', 'to_date' => '2026-01-01']);
    }

    public function test_rejects_unknown_fiscal_year(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from(['fiscal_year_id' => 99999]);
    }

    public function test_rejects_period_from_another_fiscal_year(): void
    {
        $world = $this->turnoverWorld();

        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from([
            'fiscal_year_id' => $world['fy2025']->id,
            'accounting_period_id' => $world['jan']->id,
        ]);
    }

    public function test_rejects_dates_outside_period(): void
    {
        $world = $this->turnoverWorld();

        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from([
            'accounting_period_id' => $world['jan']->id,
            'from_date' => '2026-01-01',
            'to_date' => '2027-02-15',
        ]);
    }

    public function test_period_without_dates_uses_period_bounds(): void
    {
        $world = $this->turnoverWorld();
        $filters = LedgerReportFilters::from(['accounting_period_id' => $world['jan']->id]);

        $this->assertSame('2026-01-01', $filters->fromDate);
        $this->assertSame('2026-12-31', $filters->toDate);
        $this->assertSame($world['fy2026']->id, $filters->fiscalYearId);
    }

    public function test_rejects_ambiguous_branch_modes(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from(['branch_id' => 1, 'all_branches' => true]);
    }

    public function test_rejects_branch_id_with_branch_ids(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from(['branch_id' => 1, 'branch_ids' => [2]]);
    }

    public function test_null_branch_id_is_not_all_branches(): void
    {
        $filters = LedgerReportFilters::from(['branch_id' => null]);
        $this->assertSame(BranchScope::MODE_DEFAULT, $filters->branchScope->mode);
        $this->assertFalse($filters->branchScope->toArray()['all_branches']);
    }

    public function test_rejects_per_page_above_maximum(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        ReportPagination::fromInput(['per_page' => 1000000]);
    }

    public function test_rejects_unsupported_sort(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from(['sort_by' => 'injected;drop'])->assertSort(LedgerReportFilters::TURNOVER_SORTS);
    }

    public function test_rejects_unknown_account(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from(['account_id' => 99999]);
    }

    public function test_rejects_unknown_cost_center(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from(['cost_center_id' => 99999]);
    }

    public function test_dates_outside_fiscal_year_are_rejected(): void
    {
        $world = $this->turnoverWorld();

        $this->expectException(InvalidReportFilterException::class);
        LedgerReportFilters::from([
            'fiscal_year_id' => $world['fy2026']->id,
            'from_date' => '2025-12-01',
            'to_date' => '2026-01-31',
        ]);
    }
}
