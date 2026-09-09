<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of an account statement with report-wide meta that does not depend on the page.
 */
final class PaginatedAccountStatement
{
    /**
     * @param  array{debit: float, credit: float, balance: float}  $periodTotals
     */
    public function __construct(
        public readonly int $accountId,
        public readonly float $openingBalance,
        public readonly float $closingBalance,
        public readonly array $periodTotals,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly LengthAwarePaginator $lines,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'opening_balance' => $this->openingBalance,
            'closing_balance' => $this->closingBalance,
            'period_totals' => $this->periodTotals,
            'from' => $this->from,
            'to' => $this->to,
            'lines' => collect($this->lines->items())
                ->map(fn (LedgerLine $line) => $line->toArray())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $this->lines->currentPage(),
                'per_page' => $this->lines->perPage(),
                'total' => $this->lines->total(),
                'last_page' => $this->lines->lastPage(),
            ],
        ];
    }
}
