<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Support\Collection;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Support\Amount;

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

        $zero = [
            'opening_debit' => Amount::zero(),
            'opening_credit' => Amount::zero(),
            'period_debit' => Amount::zero(),
            'period_credit' => Amount::zero(),
        ];

        /** @var array<int, array{opening_debit: Amount, opening_credit: Amount, period_debit: Amount, period_credit: Amount}> $metrics */
        $metrics = [];
        foreach ($accounts as $account) {
            $leaf = $leafMetrics[$account->id] ?? null;
            $metrics[$account->id] = $leaf === null
                ? $zero
                : [
                    'opening_debit' => Amount::of($leaf['opening_debit'] ?? 0),
                    'opening_credit' => Amount::of($leaf['opening_credit'] ?? 0),
                    'period_debit' => Amount::of($leaf['period_debit'] ?? 0),
                    'period_credit' => Amount::of($leaf['period_credit'] ?? 0),
                ];
        }

        // Deepest level first: by the time a node is folded into its parent, its own
        // metrics already include everything folded into it from its own children.
        foreach ($accounts->sortByDesc('level') as $account) {
            if ($account->parent_id === null || ! isset($metrics[$account->parent_id])) {
                continue;
            }

            $metrics[$account->parent_id]['opening_debit'] = $metrics[$account->parent_id]['opening_debit']->add($metrics[$account->id]['opening_debit']);
            $metrics[$account->parent_id]['opening_credit'] = $metrics[$account->parent_id]['opening_credit']->add($metrics[$account->id]['opening_credit']);
            $metrics[$account->parent_id]['period_debit'] = $metrics[$account->parent_id]['period_debit']->add($metrics[$account->id]['period_debit']);
            $metrics[$account->parent_id]['period_credit'] = $metrics[$account->parent_id]['period_credit']->add($metrics[$account->id]['period_credit']);
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
                openingDebit: $m['opening_debit']->toFloat(),
                openingCredit: $m['opening_credit']->toFloat(),
                periodDebit: $m['period_debit']->toFloat(),
                periodCredit: $m['period_credit']->toFloat(),
                endingDebit: $m['opening_debit']->add($m['period_debit'])->toFloat(),
                endingCredit: $m['opening_credit']->add($m['period_credit'])->toFloat(),
            );
        })->values();
    }
}
