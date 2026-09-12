<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Support\Amount;

/**
 * One row per accounting date (or date + extra group). Not a journal register.
 */
final class DailyJournalReport
{
    public function __construct(
        private readonly LedgerReportFilters $filters,
        private readonly ReportPagination $pagination,
    ) {}

    public function build(): DailyJournalResult
    {
        $query = $this->filters->toLedgerQuery();
        $documents = (new Document)->getTable();
        $items = (new DocumentItem)->getTable();
        $groupBy = $this->filters->groupBy;

        $grouped = $this->groupedQuery($query, $documents, $items, $groupBy);
        $this->applyMinDailyTurnover($grouped, $items);
        $this->applyGroupOrder($grouped, $groupBy);

        $financial = $this->filters->toLedgerQuery();
        $totals = $financial->periodTotalsExact();
        $reportTotals = [
            'total_debit' => $totals['debit'],
            'total_credit' => $totals['credit'],
        ];

        [$rows, $pagination] = $this->paginate($grouped, $groupBy);
        $data = array_map(fn ($row) => DailyJournalRow::fromRow($row), $rows);

        $pageDebit = Amount::zero();
        $pageCredit = Amount::zero();
        foreach ($data as $row) {
            $pageDebit = $pageDebit->add($row->totalDebit);
            $pageCredit = $pageCredit->add($row->totalCredit);
        }

        $branches = $this->filters->branchScope->includeBreakdown
            ? array_values($financial->periodTotalsByBranch())
            : null;

        return new DailyJournalResult(
            meta: ReportMeta::make(
                'daily_journal',
                $this->filters->toArray(),
                $this->filters->branchScope->toArray(),
                $this->filters->mode->value,
            ),
            summary: [
                'group_by' => $groupBy,
                'total_debit' => $reportTotals['total_debit'],
                'total_credit' => $reportTotals['total_credit'],
                'document_count' => $totals['document_count'],
                'line_count' => $totals['line_count'],
            ],
            data: $data,
            pagination: $pagination,
            pageTotals: [
                'total_debit' => $pageDebit->toStorage(),
                'total_credit' => $pageCredit->toStorage(),
                'row_count' => count($data),
            ],
            reportTotals: $reportTotals,
            branches: $branches,
        );
    }

    private function groupedQuery(LedgerQuery $query, string $documents, string $items, string $groupBy): QueryBuilder
    {
        $builder = $query->listingQuery()
            ->selectRaw("DATE({$documents}.date) as report_date")
            ->selectRaw("COUNT(DISTINCT {$documents}.id) as document_count")
            ->selectRaw("COUNT({$items}.id) as line_count")
            ->selectRaw("COALESCE(SUM({$items}.debit), 0) as total_debit")
            ->selectRaw("COALESCE(SUM({$items}.credit), 0) as total_credit")
            ->selectRaw("MIN({$documents}.number) as first_document_number")
            ->selectRaw("MAX({$documents}.number) as last_document_number")
            ->selectRaw("MIN({$documents}.posted_at) as first_posted_at")
            ->selectRaw("MAX({$documents}.posted_at) as last_posted_at")
            ->groupByRaw("DATE({$documents}.date)");

        if ($groupBy === 'day_branch') {
            $builder->addSelect("{$documents}.branch_id")
                ->groupBy("{$documents}.branch_id");
        }

        if ($groupBy === 'day_account') {
            $builder->addSelect("{$items}.account_id")
                ->groupBy("{$items}.account_id");
        }

        if ($groupBy === 'day_document_type') {
            $builder->addSelect(DB::raw("{$documents}.type as document_type"))
                ->groupBy("{$documents}.type");
        }

        return $builder;
    }

    private function applyMinDailyTurnover(QueryBuilder $query, string $items): void
    {
        if ($this->filters->minAmount === null) {
            return;
        }

        $query->havingRaw("COALESCE(SUM({$items}.debit), 0) >= ?", [$this->filters->minAmount]);
    }

    private function applyGroupOrder(QueryBuilder $query, string $groupBy): void
    {
        $query->orderByRaw('report_date DESC');

        if ($groupBy === 'day_branch') {
            $query->orderBy('branch_id');
        }
        if ($groupBy === 'day_account') {
            $query->orderBy('account_id');
        }
        if ($groupBy === 'day_document_type') {
            $query->orderBy('document_type');
        }
    }

    /**
     * @return array{0: list<object>, 1: PaginationMeta}
     */
    private function paginate(QueryBuilder $query, string $groupBy): array
    {
        $pagination = $this->pagination;

        if ($pagination->mode === ReportPagination::MODE_OFFSET) {
            $total = (int) DB::query()->fromSub(clone $query, 'daily_groups')->count();
            $rows = $query->offset($pagination->offsetValue())->limit($pagination->perPage)->get()->all();

            return [
                $rows,
                PaginationMeta::offset(
                    $pagination->page,
                    $pagination->perPage,
                    $total,
                    $pagination->offsetValue() + count($rows) < $total,
                ),
            ];
        }

        if ($pagination->cursor !== null) {
            $keys = CursorCodec::decode($pagination->cursor);
            $date = (string) ($keys['date'] ?? '');
            $query->where(function (QueryBuilder $nested) use ($keys, $date, $groupBy) {
                $nested->whereRaw('DATE('.((new Document)->getTable()).'.date) < ?', [$date]);
                $this->applySecondaryCursor($nested, $keys, $date, $groupBy);
            });
        }

        $rows = $query->limit($pagination->perPage + 1)->get()->all();
        $hasMore = count($rows) > $pagination->perPage;
        $rows = array_slice($rows, 0, $pagination->perPage);
        $last = $rows !== [] ? $rows[array_key_last($rows)] : null;
        $next = $hasMore && $last ? CursorCodec::encode($this->cursorKeys($last, $groupBy)) : null;

        return [$rows, PaginationMeta::cursor($pagination->perPage, $hasMore, $next)];
    }

    private function applySecondaryCursor(QueryBuilder $nested, array $keys, string $date, string $groupBy): void
    {
        $documents = (new Document)->getTable();
        $items = (new DocumentItem)->getTable();

        if ($groupBy === 'day_branch') {
            $nested->orWhere(function (QueryBuilder $same) use ($documents, $date, $keys) {
                $same->whereRaw("DATE({$documents}.date) = ?", [$date])
                    ->where("{$documents}.branch_id", '>', (int) ($keys['branch_id'] ?? 0));
            });
        }

        if ($groupBy === 'day_account') {
            $nested->orWhere(function (QueryBuilder $same) use ($documents, $items, $date, $keys) {
                $same->whereRaw("DATE({$documents}.date) = ?", [$date])
                    ->where("{$items}.account_id", '>', (int) ($keys['account_id'] ?? 0));
            });
        }

        if ($groupBy === 'day_document_type') {
            $nested->orWhere(function (QueryBuilder $same) use ($documents, $date, $keys) {
                $same->whereRaw("DATE({$documents}.date) = ?", [$date])
                    ->where("{$documents}.type", '>', (string) ($keys['document_type'] ?? ''));
            });
        }
    }

    /** @return array<string, int|string|null> */
    private function cursorKeys(object $row, string $groupBy): array
    {
        $keys = ['date' => substr((string) $row->report_date, 0, 10)];

        if ($groupBy === 'day_branch') {
            $keys['branch_id'] = $row->branch_id !== null ? (int) $row->branch_id : 0;
        }
        if ($groupBy === 'day_account') {
            $keys['account_id'] = (int) $row->account_id;
        }
        if ($groupBy === 'day_document_type') {
            $keys['document_type'] = (string) $row->document_type;
        }

        return $keys;
    }
}
