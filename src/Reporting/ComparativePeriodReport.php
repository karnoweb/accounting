<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Support\Amount;

/**
 * Aligns two Account Turnover datasets by account_id, then paginates.
 * Accounts present in only one scope still appear (zero-filled counterpart).
 *
 * Do not paginate each period separately and then try to match pages.
 */
final class ComparativePeriodReport
{
    public function __construct(
        private readonly LedgerReportFilters $current,
        private readonly LedgerReportFilters $comparison,
        private readonly ReportPagination $pagination,
    ) {}

    public function build(): ComparativeReportResult
    {
        $currentFilters = $this->current->assertSort(LedgerReportFilters::TURNOVER_SORTS);
        $comparisonFilters = $this->comparison->withSort($currentFilters->sortBy, $currentFilters->sortDirection);

        $currentRows = $this->turnoverIndex($currentFilters);
        $comparisonRows = $this->turnoverIndex($comparisonFilters);
        $accountIds = array_values(array_unique(array_merge(array_keys($currentRows), array_keys($comparisonRows))));

        $accounts = $accountIds === []
            ? collect()
            : Account::query()->whereIn('id', $accountIds)->orderBy('code')->orderBy('id')->get()->keyBy('id');

        $aligned = [];
        foreach ($accountIds as $accountId) {
            $currentRow = $currentRows[$accountId] ?? $this->emptyRow($accounts->get($accountId), $accountId);
            $comparisonRow = $comparisonRows[$accountId] ?? $this->emptyRow($accounts->get($accountId), $accountId);
            $aligned[] = ComparisonRow::fromTurnover($currentRow, $comparisonRow);
        }

        usort($aligned, function (ComparisonRow $a, ComparisonRow $b) use ($currentFilters) {
            $cmp = $a->accountCode <=> $b->accountCode;
            if ($currentFilters->sortBy === 'account_name') {
                $cmp = $a->accountName <=> $b->accountName;
            }
            if ($cmp !== 0) {
                return $currentFilters->sortDirection === 'desc' ? -$cmp : $cmp;
            }

            return $a->accountId <=> $b->accountId;
        });

        $reportTotals = $this->sumAligned($aligned);
        [$page, $pagination] = $this->paginate($aligned);

        return new ComparativeReportResult(
            meta: ReportMeta::make(
                'comparative_period',
                [
                    'current' => $currentFilters->toArray(),
                    'comparison' => $comparisonFilters->toArray(),
                ],
                $currentFilters->branchScope->toArray(),
                $currentFilters->mode->value,
            ),
            summary: [
                'current_scope' => $currentFilters->toArray(),
                'comparison_scope' => $comparisonFilters->toArray(),
                'current_total_debit' => $reportTotals['current_debit'],
                'comparison_total_debit' => $reportTotals['comparison_debit'],
                'current_total_credit' => $reportTotals['current_credit'],
                'comparison_total_credit' => $reportTotals['comparison_credit'],
                'variance_debit' => $reportTotals['debit_variance'],
                'variance_credit' => $reportTotals['credit_variance'],
                'row_count' => count($aligned),
            ],
            data: $page,
            pagination: $pagination,
            pageTotals: $this->sumAligned($page),
            reportTotals: $reportTotals,
        );
    }

    /**
     * @return array<int, AccountTurnoverRow>
     */
    private function turnoverIndex(LedgerReportFilters $filters): array
    {
        [$rows] = (new AccountTurnoverReport(
            $filters,
            ReportPagination::offset(1, (int) config('accounting.reports.per_page_max', 200), true),
        ))->compiled();

        $index = [];
        foreach ($rows as $row) {
            $index[$row->accountId] = $row;
        }

        return $index;
    }

    private function emptyRow(?Account $account, int $accountId): AccountTurnoverRow
    {
        $zero = Amount::zero()->toStorage();

        return new AccountTurnoverRow(
            accountId: $accountId,
            accountCode: (string) ($account?->code ?? ''),
            accountName: (string) ($account?->title ?? ''),
            accountType: $account?->type->value ?? '',
            normalBalance: $account?->nature->value ?? '',
            level: (int) ($account?->level ?? 0),
            parentId: $account?->parent_id !== null ? (int) $account->parent_id : null,
            openingDebit: $zero,
            openingCredit: $zero,
            openingBalance: $zero,
            periodDebitTurnover: $zero,
            periodCreditTurnover: $zero,
            netMovement: $zero,
            closingDebit: $zero,
            closingCredit: $zero,
            closingBalance: $zero,
            transactionCount: 0,
            documentCount: 0,
        );
    }

    /**
     * @param  list<ComparisonRow>  $rows
     * @return array<string, string>
     */
    private function sumAligned(array $rows): array
    {
        $currentDebit = Amount::zero();
        $comparisonDebit = Amount::zero();
        $currentCredit = Amount::zero();
        $comparisonCredit = Amount::zero();

        foreach ($rows as $row) {
            $currentDebit = $currentDebit->add($row->currentDebit);
            $comparisonDebit = $comparisonDebit->add($row->comparisonDebit);
            $currentCredit = $currentCredit->add($row->currentCredit);
            $comparisonCredit = $comparisonCredit->add($row->comparisonCredit);
        }

        return [
            'current_debit' => $currentDebit->toStorage(),
            'comparison_debit' => $comparisonDebit->toStorage(),
            'current_credit' => $currentCredit->toStorage(),
            'comparison_credit' => $comparisonCredit->toStorage(),
            'debit_variance' => $currentDebit->subtract($comparisonDebit)->toStorage(),
            'credit_variance' => $currentCredit->subtract($comparisonCredit)->toStorage(),
        ];
    }

    /**
     * @param  list<ComparisonRow>  $rows
     * @return array{0: list<ComparisonRow>, 1: PaginationMeta}
     */
    private function paginate(array $rows): array
    {
        $pagination = $this->pagination;

        if ($pagination->mode === ReportPagination::MODE_OFFSET) {
            $total = count($rows);
            $slice = array_slice($rows, $pagination->offsetValue(), $pagination->perPage);

            return [
                $slice,
                PaginationMeta::offset(
                    $pagination->page,
                    $pagination->perPage,
                    $total,
                    $pagination->offsetValue() + count($slice) < $total,
                ),
            ];
        }

        $start = 0;
        if ($pagination->cursor !== null) {
            $keys = CursorCodec::decode($pagination->cursor);
            $cursorId = (int) ($keys['account_id'] ?? 0);
            foreach ($rows as $index => $row) {
                if ($row->accountId === $cursorId) {
                    $start = $index + 1;
                    break;
                }
            }
        }

        $slice = array_slice($rows, $start, $pagination->perPage + 1);
        $hasMore = count($slice) > $pagination->perPage;
        $slice = array_slice($slice, 0, $pagination->perPage);
        $next = $hasMore && $slice !== []
            ? CursorCodec::encode(['account_id' => $slice[array_key_last($slice)]->accountId])
            : null;

        return [$slice, PaginationMeta::cursor($pagination->perPage, $hasMore, $next)];
    }
}
