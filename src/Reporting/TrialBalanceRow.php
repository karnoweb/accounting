<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

/**
 * One row of a real Trial Balance — any level from L0 (Group) to L3 (Detail).
 * L0-L2 rows are rollups of their L3 descendants (see HierarchyRollup), never
 * a copy of Account::cached_balance.
 */
final class TrialBalanceRow
{
    public function __construct(
        public readonly int $accountId,
        public readonly ?int $parentId,
        public readonly string $code,
        public readonly string $title,
        public readonly int $level,
        public readonly string $type,
        public readonly string $nature,
        public readonly float $openingDebit,
        public readonly float $openingCredit,
        public readonly float $periodDebit,
        public readonly float $periodCredit,
        public readonly float $endingDebit,
        public readonly float $endingCredit,
    ) {}

    public function openingBalance(): float
    {
        return $this->openingDebit - $this->openingCredit;
    }

    public function periodNet(): float
    {
        return $this->periodDebit - $this->periodCredit;
    }

    public function endingBalance(): float
    {
        return $this->endingDebit - $this->endingCredit;
    }

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
            'opening_debit' => $this->openingDebit,
            'opening_credit' => $this->openingCredit,
            'period_debit' => $this->periodDebit,
            'period_credit' => $this->periodCredit,
            'ending_debit' => $this->endingDebit,
            'ending_credit' => $this->endingCredit,
            'opening_balance' => $this->openingBalance(),
            'period_net' => $this->periodNet(),
            'ending_balance' => $this->endingBalance(),
        ];
    }
}
