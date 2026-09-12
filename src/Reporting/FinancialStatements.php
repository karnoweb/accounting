<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;
use Karnoweb\Accounting\Enums\AccountNature;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Karnoweb\Accounting\Support\Amount;

/**
 * Builds statement DTOs from a Trial Balance. Presentation uses natural balances;
 * arithmetic stays on debit/credit Amounts.
 */
final class FinancialStatements
{
    public static function profitAndLoss(TrialBalanceReport $trialBalance): ProfitAndLossReport
    {
        $revenue = self::section($trialBalance, AccountType::INCOME, period: true);
        $expenses = self::section($trialBalance, AccountType::EXPENSE, period: true);
        $totalRevenue = self::detailTotal($revenue);
        $totalExpenses = self::detailTotal($expenses);

        return new ProfitAndLossReport(
            revenue: $revenue,
            expenses: $expenses,
            totalRevenue: $totalRevenue->toFloat(),
            totalExpenses: $totalExpenses->toFloat(),
            netProfit: $totalRevenue->subtract($totalExpenses)->toFloat(),
            from: $trialBalance->from,
            to: $trialBalance->to,
        );
    }

    public static function balanceSheet(TrialBalanceReport $trialBalance): BalanceSheetReport
    {
        $assets = self::section($trialBalance, AccountType::ASSET, period: false);
        $liabilities = self::section($trialBalance, AccountType::LIABILITY, period: false);
        $equity = self::section($trialBalance, AccountType::EQUITY, period: false);

        $totalAssets = self::detailTotal($assets);
        $totalLiabilities = self::detailTotal($liabilities);
        $totalEquity = self::detailTotal($equity);
        $currentEarnings = self::currentEarnings($trialBalance);
        $rightHand = $totalLiabilities->add($totalEquity)->add($currentEarnings);

        return new BalanceSheetReport(
            assets: $assets,
            liabilities: $liabilities,
            equity: $equity,
            totalAssets: $totalAssets->toFloat(),
            totalLiabilities: $totalLiabilities->toFloat(),
            totalEquity: $totalEquity->toFloat(),
            currentEarnings: $currentEarnings->toFloat(),
            balanced: $totalAssets->equals($rightHand),
            asOf: $trialBalance->to,
            from: $trialBalance->from,
        );
    }

    /**
     * @return Collection<int, StatementLine>
     */
    private static function section(TrialBalanceReport $trialBalance, AccountType $type, bool $period): Collection
    {
        return $trialBalance->rows
            ->filter(fn (TrialBalanceRow $row) => $row->type === $type->value)
            ->map(function (TrialBalanceRow $row) use ($period) {
                $debit = $period ? $row->periodDebit : $row->endingDebit;
                $credit = $period ? $row->periodCredit : $row->endingCredit;
                $nature = AccountNature::from($row->nature);

                return new StatementLine(
                    accountId: $row->accountId,
                    parentId: $row->parentId,
                    code: $row->code,
                    title: $row->title,
                    level: $row->level,
                    type: $row->type,
                    nature: $row->nature,
                    amount: $nature->naturalAmount($debit, $credit),
                    debit: $debit,
                    credit: $credit,
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, StatementLine>  $lines
     */
    private static function detailTotal(Collection $lines): Amount
    {
        $postingLevel = AccountHierarchy::postingLevel();

        return Amount::sum(
            $lines
                ->filter(fn (StatementLine $line) => $line->level === $postingLevel)
                ->map(fn (StatementLine $line) => $line->amount)
        );
    }

    private static function currentEarnings(TrialBalanceReport $trialBalance): Amount
    {
        $income = Amount::zero();
        $expense = Amount::zero();

        foreach ($trialBalance->detail() as $row) {
            $nature = AccountNature::from($row->nature);
            $natural = Amount::of($nature->naturalAmount($row->endingDebit, $row->endingCredit));

            if ($row->type === AccountType::INCOME->value) {
                $income = $income->add($natural);
            } elseif ($row->type === AccountType::EXPENSE->value) {
                $expense = $expense->add($natural);
            }
        }

        return $income->subtract($expense);
    }
}
