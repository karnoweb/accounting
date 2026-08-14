<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;

/**
 * Result of ReportService::generalLedger() — one AccountLedger per requested account.
 */
final class GeneralLedgerReport
{
    /** @param Collection<int, AccountLedger> $accounts keyed by account_id */
    public function __construct(
        public readonly Collection $accounts
    ) {}

    public function forAccount(int $accountId): ?AccountLedger
    {
        return $this->accounts->get($accountId);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->accounts->map(fn (AccountLedger $ledger) => $ledger->toArray())->values()->all();
    }
}
