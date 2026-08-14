<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;
use Karnoweb\Accounting\Models\Account;

/**
 * Builds the L0-L3 account tree once and rolls up L3 journal metrics upward in a
 * single in-memory pass — never touches Account::cached_balance and never issues
 * a per-parent query.
 */
final class HierarchyRollup
{
    /**
     * @param array<int, array{opening_debit: float, opening_credit: float, period_debit: float, period_credit: float}> $leafMetrics keyed by account_id
     * @return Collection<int, TrialBalanceRow>
     */
    public static function build(array $leafMetrics): Collection
    {
        $accounts = Account::query()
            ->select(['id', 'parent_id', 'code', 'title', 'level', 'type', 'nature'])
            ->orderBy('level')
            ->orderBy('code')
            ->get();

        $zero = ['opening_debit' => 0.0, 'opening_credit' => 0.0, 'period_debit' => 0.0, 'period_credit' => 0.0];

        /** @var array<int, array{opening_debit: float, opening_credit: float, period_debit: float, period_credit: float}> $metrics */
        $metrics = [];
        foreach ($accounts as $account) {
            $metrics[$account->id] = $leafMetrics[$account->id] ?? $zero;
        }

        // Deepest level first: by the time a node is folded into its parent, its own
        // metrics already include everything folded into it from its own children.
        foreach ($accounts->sortByDesc('level') as $account) {
            if ($account->parent_id === null || ! isset($metrics[$account->parent_id])) {
                continue;
            }

            $metrics[$account->parent_id]['opening_debit'] += $metrics[$account->id]['opening_debit'];
            $metrics[$account->parent_id]['opening_credit'] += $metrics[$account->id]['opening_credit'];
            $metrics[$account->parent_id]['period_debit'] += $metrics[$account->id]['period_debit'];
            $metrics[$account->parent_id]['period_credit'] += $metrics[$account->id]['period_credit'];
        }

        return $accounts->map(function (Account $account) use ($metrics) {
            $m = $metrics[$account->id];

            return new TrialBalanceRow(
                accountId: $account->id,
                parentId: $account->parent_id,
                code: $account->code,
                title: $account->title,
                level: $account->level,
                type: $account->type->value,
                nature: $account->nature->value,
                openingDebit: $m['opening_debit'],
                openingCredit: $m['opening_credit'],
                periodDebit: $m['period_debit'],
                periodCredit: $m['period_credit'],
                endingDebit: $m['opening_debit'] + $m['period_debit'],
                endingCredit: $m['opening_credit'] + $m['period_credit'],
            );
        })->values();
    }
}
