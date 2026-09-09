<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Level-1 general ledger: paginated posting accounts with opening / period / closing,
 * without materializing journal lines (drill into accountStatementPaginated for detail).
 */
final class PaginatedGeneralLedgerSummary
{
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly LengthAwarePaginator $accounts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'accounts' => collect($this->accounts->items())
                ->map(fn (GeneralLedgerSummaryRow $row) => $row->toArray())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $this->accounts->currentPage(),
                'per_page' => $this->accounts->perPage(),
                'total' => $this->accounts->total(),
                'last_page' => $this->accounts->lastPage(),
            ],
        ];
    }
}
