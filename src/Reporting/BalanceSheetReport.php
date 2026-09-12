<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;

final class BalanceSheetReport
{
    /**
     * @param  Collection<int, StatementLine>  $assets
     * @param  Collection<int, StatementLine>  $liabilities
     * @param  Collection<int, StatementLine>  $equity
     */
    public function __construct(
        public readonly Collection $assets,
        public readonly Collection $liabilities,
        public readonly Collection $equity,
        public readonly float $totalAssets,
        public readonly float $totalLiabilities,
        public readonly float $totalEquity,
        public readonly float $currentEarnings,
        public readonly bool $balanced,
        public readonly ?string $asOf,
        public readonly ?string $from,
    ) {}

    public function totalLiabilitiesAndEquity(): float
    {
        return $this->totalLiabilities + $this->totalEquity + $this->currentEarnings;
    }

    public function find(int $accountId): ?StatementLine
    {
        return $this->assets->first(fn (StatementLine $line) => $line->accountId === $accountId)
            ?? $this->liabilities->first(fn (StatementLine $line) => $line->accountId === $accountId)
            ?? $this->equity->first(fn (StatementLine $line) => $line->accountId === $accountId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf,
            'from' => $this->from,
            'total_assets' => $this->totalAssets,
            'total_liabilities' => $this->totalLiabilities,
            'total_equity' => $this->totalEquity,
            'current_earnings' => $this->currentEarnings,
            'total_liabilities_and_equity' => $this->totalLiabilitiesAndEquity(),
            'balanced' => $this->balanced,
            'assets' => $this->assets->map(fn (StatementLine $line) => $line->toArray())->values()->all(),
            'liabilities' => $this->liabilities->map(fn (StatementLine $line) => $line->toArray())->values()->all(),
            'equity' => $this->equity->map(fn (StatementLine $line) => $line->toArray())->values()->all(),
        ];
    }
}
