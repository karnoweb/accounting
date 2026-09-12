<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

/**
 * One financial-statement row. `$amount` is nature-aware presentation
 * (debit-normal accounts increase with debit; credit-normal with credit).
 * `$debit` / `$credit` remain the raw ledger columns for reconciliation.
 */
final class StatementLine
{
    public function __construct(
        public readonly int $accountId,
        public readonly ?int $parentId,
        public readonly string $code,
        public readonly string $title,
        public readonly int $level,
        public readonly string $type,
        public readonly string $nature,
        public readonly float $amount,
        public readonly float $debit,
        public readonly float $credit,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'parent_id' => $this->parentId,
            'code' => $this->code,
            'title' => $this->title,
            'level' => $this->level,
            'type' => $this->type,
            'nature' => $this->nature,
            'amount' => $this->amount,
            'debit' => $this->debit,
            'credit' => $this->credit,
        ];
    }
}
