<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;
use Karnoweb\Accounting\Support\AccountHierarchy;

final class ProfitAndLossReport
{
    /**
     * @param  Collection<int, StatementLine>  $revenue
     * @param  Collection<int, StatementLine>  $expenses
     */
    public function __construct(
        public readonly Collection $revenue,
        public readonly Collection $expenses,
        public readonly float $totalRevenue,
        public readonly float $totalExpenses,
        public readonly float $netProfit,
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    /** @return Collection<int, StatementLine> */
    public function revenueDetail(): Collection
    {
        return $this->revenue
            ->filter(fn (StatementLine $line) => $line->level === AccountHierarchy::postingLevel())
            ->values();
    }

    /** @return Collection<int, StatementLine> */
    public function expenseDetail(): Collection
    {
        return $this->expenses
            ->filter(fn (StatementLine $line) => $line->level === AccountHierarchy::postingLevel())
            ->values();
    }

    public function find(int $accountId): ?StatementLine
    {
        return $this->revenue->first(fn (StatementLine $line) => $line->accountId === $accountId)
            ?? $this->expenses->first(fn (StatementLine $line) => $line->accountId === $accountId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'total_revenue' => $this->totalRevenue,
            'total_expenses' => $this->totalExpenses,
            'net_profit' => $this->netProfit,
            'revenue' => $this->revenue->map(fn (StatementLine $line) => $line->toArray())->values()->all(),
            'expenses' => $this->expenses->map(fn (StatementLine $line) => $line->toArray())->values()->all(),
        ];
    }
}
