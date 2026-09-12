<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Support\Amount;

final class ComparisonRow
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountCode,
        public readonly string $accountName,
        public readonly string $currentDebit,
        public readonly string $comparisonDebit,
        public readonly string $debitVariance,
        public readonly ?string $debitVariancePercent,
        public readonly string $debitVarianceStatus,
        public readonly string $currentCredit,
        public readonly string $comparisonCredit,
        public readonly string $creditVariance,
        public readonly ?string $creditVariancePercent,
        public readonly string $creditVarianceStatus,
        public readonly string $currentClosingBalance,
        public readonly string $comparisonClosingBalance,
        public readonly string $closingVariance,
        public readonly ?string $closingVariancePercent,
        public readonly string $closingVarianceStatus,
    ) {}

    /**
     * Percentage rules:
     * - comparison != 0 → ((current - comparison) / comparison) * 100
     * - comparison = 0 and current = 0 → 0
     * - comparison = 0 and current != 0 → null (variance_status = new)
     */
    public static function fromTurnover(AccountTurnoverRow $current, AccountTurnoverRow $comparison): self
    {
        [$debitVar, $debitPct, $debitStatus] = self::variance($current->periodDebitTurnover, $comparison->periodDebitTurnover);
        [$creditVar, $creditPct, $creditStatus] = self::variance($current->periodCreditTurnover, $comparison->periodCreditTurnover);
        [$closeVar, $closePct, $closeStatus] = self::variance($current->closingBalance, $comparison->closingBalance);

        return new self(
            accountId: $current->accountId,
            accountCode: $current->accountCode !== '' ? $current->accountCode : $comparison->accountCode,
            accountName: $current->accountName !== '' ? $current->accountName : $comparison->accountName,
            currentDebit: $current->periodDebitTurnover,
            comparisonDebit: $comparison->periodDebitTurnover,
            debitVariance: $debitVar,
            debitVariancePercent: $debitPct,
            debitVarianceStatus: $debitStatus,
            currentCredit: $current->periodCreditTurnover,
            comparisonCredit: $comparison->periodCreditTurnover,
            creditVariance: $creditVar,
            creditVariancePercent: $creditPct,
            creditVarianceStatus: $creditStatus,
            currentClosingBalance: $current->closingBalance,
            comparisonClosingBalance: $comparison->closingBalance,
            closingVariance: $closeVar,
            closingVariancePercent: $closePct,
            closingVarianceStatus: $closeStatus,
        );
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}
     */
    public static function variance(string $current, string $comparison): array
    {
        $currentAmount = Amount::of($current);
        $comparisonAmount = Amount::of($comparison);
        $delta = $currentAmount->subtract($comparisonAmount);

        if ($comparisonAmount->isZero() && $currentAmount->isZero()) {
            return [$delta->toStorage(), Amount::zero()->toStorage(), 'unchanged'];
        }

        if ($comparisonAmount->isZero()) {
            return [$delta->toStorage(), null, 'new'];
        }

        if ($currentAmount->isZero()) {
            $percent = self::percent($delta, $comparisonAmount);

            return [$delta->toStorage(), $percent, 'cleared'];
        }

        $status = $delta->isZero() ? 'unchanged' : ($delta->isPositive() ? 'increased' : 'decreased');

        return [$delta->toStorage(), self::percent($delta, $comparisonAmount), $status];
    }

    private static function percent(Amount $delta, Amount $comparison): string
    {
        $ratio = bcdiv($delta->toStorage(), $comparison->toStorage(), 8);
        $percent = bcmul($ratio, '100', 8);

        return Amount::of($percent)->toStorage();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'current_debit' => $this->currentDebit,
            'comparison_debit' => $this->comparisonDebit,
            'debit_variance' => $this->debitVariance,
            'debit_variance_percent' => $this->debitVariancePercent,
            'debit_variance_status' => $this->debitVarianceStatus,
            'current_credit' => $this->currentCredit,
            'comparison_credit' => $this->comparisonCredit,
            'credit_variance' => $this->creditVariance,
            'credit_variance_percent' => $this->creditVariancePercent,
            'credit_variance_status' => $this->creditVarianceStatus,
            'current_closing_balance' => $this->currentClosingBalance,
            'comparison_closing_balance' => $this->comparisonClosingBalance,
            'closing_variance' => $this->closingVariance,
            'closing_variance_percent' => $this->closingVariancePercent,
            'closing_variance_status' => $this->closingVarianceStatus,
        ];
    }
}
