<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;
use Karnoweb\Accounting\Support\AccountHierarchy;

/**
 * Result of ReportService::trialBalanceDetailed() — every account (L0-L3), flat,
 * plus reconciliation totals computed from posting-level (L3) rows only.
 */
final class TrialBalanceReport
{
    /** @param Collection<int, TrialBalanceRow> $rows */
    public function __construct(
        public readonly Collection $rows,
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    /** @return Collection<int, TrialBalanceRow> */
    public function level(int $level): Collection
    {
        return $this->rows->filter(fn (TrialBalanceRow $row) => $row->level === $level)->values();
    }

    /** Posting-level (leaf) rows — the real, non-rolled-up movements. */
    public function detail(): Collection
    {
        return $this->level(AccountHierarchy::postingLevel());
    }

    public function find(int $accountId): ?TrialBalanceRow
    {
        return $this->rows->first(fn (TrialBalanceRow $row) => $row->accountId === $accountId);
    }

    /**
     * Reconciliation totals from posting-level rows only (summing every level would
     * double-count, since L0-L2 are rollups of their L3 descendants).
     *
     * @return array{opening_debit: float, opening_credit: float, period_debit: float, period_credit: float, ending_debit: float, ending_credit: float}
     */
    public function totals(): array
    {
        $detail = $this->detail();

        return [
            'opening_debit' => (float) $detail->sum(fn (TrialBalanceRow $r) => $r->openingDebit),
            'opening_credit' => (float) $detail->sum(fn (TrialBalanceRow $r) => $r->openingCredit),
            'period_debit' => (float) $detail->sum(fn (TrialBalanceRow $r) => $r->periodDebit),
            'period_credit' => (float) $detail->sum(fn (TrialBalanceRow $r) => $r->periodCredit),
            'ending_debit' => (float) $detail->sum(fn (TrialBalanceRow $r) => $r->endingDebit),
            'ending_credit' => (float) $detail->sum(fn (TrialBalanceRow $r) => $r->endingCredit),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->rows->map(fn (TrialBalanceRow $row) => $row->toArray())->values()->all();
    }
}
