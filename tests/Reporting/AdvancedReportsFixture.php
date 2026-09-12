<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerReportFilters;
use Karnoweb\Accounting\Reporting\ReportPagination;
use Karnoweb\Accounting\Support\Amount;

trait AdvancedReportsFixture
{
    use PostsJournals;

    /**
     * @return array{
     *     fy2025: FiscalYear,
     *     fy2026: FiscalYear,
     *     jan: AccountingPeriod,
     *     cash: Account,
     *     revenue: Account,
     *     expense: Account,
     *     equity: Account,
     *     center: CostCenter
     * }
     */
    private function turnoverWorld(): array
    {
        $fy2025 = $this->createActiveFiscalYear('FY 2025', '2025-01-01', '2025-12-31', current: false);
        $fy2026 = $this->createActiveFiscalYear('FY 2026', '2026-01-01', '2026-12-31', current: true);
        $jan = AccountingPeriod::query()
            ->forFiscalYear($fy2026)
            ->orderBy('id')
            ->first();

        $asset = $this->createTypedChart(AccountType::ASSET, '1');
        $income = $this->createTypedChart(AccountType::INCOME, '4');
        $expense = $this->createTypedChart(AccountType::EXPENSE, '5');
        $equity = $this->createTypedChart(AccountType::EQUITY, '3');
        $center = CostCenter::create(['code' => 'CC1', 'title' => 'Ops', 'is_active' => true]);

        $cash = $asset['detail'];
        $revenue = $income['detail'];
        $exp = $expense['detail'];
        $eq = $equity['detail'];

        $this->postJournal($fy2025, $cash, $eq, 1000, '2025-12-31', branchId: 1);
        $this->postJournal($fy2026, $cash, $revenue, 500, '2026-01-10', branchId: 1, costCenterId: $center->id);
        $this->postJournal($fy2026, $exp, $cash, 200, '2026-01-20', branchId: 1, costCenterId: $center->id);

        $this->postJournal($fy2025, $cash, $eq, 400, '2025-12-31', branchId: 2);
        $this->postJournal($fy2026, $cash, $revenue, 100, '2026-01-11', branchId: 2);
        $this->postJournal($fy2026, $exp, $cash, 50, '2026-01-21', branchId: 2);

        return compact('fy2025', 'fy2026', 'jan', 'cash', 'revenue', 'center') + [
            'expense' => $exp,
            'equity' => $eq,
            'asset' => $asset,
            'income' => $income,
            'expenseChart' => $expense,
        ];
    }

    private function filters(array $input): LedgerReportFilters
    {
        return LedgerReportFilters::from($input);
    }

    private function cursorPage(int $perPage = 50, ?string $cursor = null): ReportPagination
    {
        return ReportPagination::cursor($perPage, $cursor);
    }

    private function offsetPage(int $page = 1, int $perPage = 50): ReportPagination
    {
        return ReportPagination::offset($page, $perPage, true);
    }

    private function assertAmountEq(int|float|string $expected, int|float|string $actual): void
    {
        $this->assertTrue(
            Amount::of($expected)->equals($actual),
            sprintf('Failed asserting that %s equals %s.', Amount::of($actual), Amount::of($expected))
        );
    }

    /** @return list<\Karnoweb\Accounting\Reporting\AccountTurnoverRow> */
    private function turnoverRows(array $input): array
    {
        return (new \Karnoweb\Accounting\Reporting\AccountTurnoverReport(
            $this->filters($input),
            $this->offsetPage(1, 200),
        ))->compiled()[0];
    }

    private function turnoverRow(array $input, Account $account): ?\Karnoweb\Accounting\Reporting\AccountTurnoverRow
    {
        foreach ($this->turnoverRows($input) as $row) {
            if ($row->accountId === $account->id) {
                return $row;
            }
        }

        return null;
    }

    private function createDraft(FiscalYear $fy, Account $debit, Account $credit, int|float|string $amount, string $date, ?int $branchId = null): Document
    {
        return $this->documents()->create([
            'type' => 'adjustment',
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'items' => $this->balancedItems($debit, $credit, $amount),
        ]);
    }

    private function report()
    {
        return Accounting::report();
    }
}
