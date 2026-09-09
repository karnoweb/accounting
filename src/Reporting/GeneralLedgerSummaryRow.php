<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

/** One account row in a paginated general-ledger summary (no journal lines). */
final class GeneralLedgerSummaryRow
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $code,
        public readonly string $title,
        public readonly float $openingBalance,
        public readonly float $periodDebit,
        public readonly float $periodCredit,
        public readonly float $closingBalance,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'code' => $this->code,
            'title' => $this->title,
            'opening_balance' => $this->openingBalance,
            'period_debit' => $this->periodDebit,
            'period_credit' => $this->periodCredit,
            'closing_balance' => $this->closingBalance,
        ];
    }
}
