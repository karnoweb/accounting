<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paginated cost-center journal lines with global debit/credit totals for the scoped period.
 * Running balance is not computed across accounts.
 */
final class PaginatedCostCenterStatement
{
    /**
     * @param  array{debit: float, credit: float, balance: float}  $periodTotals
     */
    public function __construct(
        public readonly int $costCenterId,
        public readonly array $periodTotals,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly LengthAwarePaginator $lines,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cost_center_id' => $this->costCenterId,
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
