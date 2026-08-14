<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;

/**
 * One account's General Ledger / Account Statement projection: opening balance,
 * ordered journal lines (each carrying its own running balance), and closing balance.
 */
final class AccountLedger
{
    /** @param Collection<int, LedgerLine> $lines */
    public function __construct(
        public readonly int $accountId,
        public readonly float $openingBalance,
        public readonly Collection $lines,
        public readonly float $closingBalance,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'opening_balance' => $this->openingBalance,
            'lines' => $this->lines->map(fn (LedgerLine $line) => $line->toArray())->values()->all(),
            'closing_balance' => $this->closingBalance,
        ];
    }
}
