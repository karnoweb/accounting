<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;

/**
 * Cash / bank movement foundation — not a full operating/investing/financing statement.
 *
 * Inflow/outflow are ledger debit/credit on configured cash accounts.
 * Transfers are posted documents whose every line is a cash account.
 */
final class CashMovementReport
{
    /**
     * @param  Collection<int, StatementLine>  $accounts
     * @param  list<int>  $cashAccountIds
     */
    public function __construct(
        public readonly Collection $accounts,
        public readonly float $inflow,
        public readonly float $outflow,
        public readonly float $transfers,
        public readonly float $netChange,
        public readonly array $cashAccountIds,
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'inflow' => $this->inflow,
            'outflow' => $this->outflow,
            'transfers' => $this->transfers,
            'net_change' => $this->netChange,
            'cash_account_ids' => $this->cashAccountIds,
            'accounts' => $this->accounts->map(fn (StatementLine $line) => $line->toArray())->values()->all(),
        ];
    }
}
