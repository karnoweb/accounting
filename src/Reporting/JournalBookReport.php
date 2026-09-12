<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Support\Amount;

/**
 * Chronological journal register. Default pagination unit is the document so
 * a journal is never split across pages. Lines for the current page are loaded
 * in one query (no per-document N+1).
 *
 * Search fields: document number (exact when numeric), description, reference,
 * account code, account name.
 */
final class JournalBookReport
{
    public function __construct(
        private readonly LedgerReportFilters $filters,
        private readonly ReportPagination $pagination,
    ) {}

    public function build(): JournalBookResult
    {
        $query = $this->filters->toLedgerQuery();
        $documents = (new Document)->getTable();
        $items = (new DocumentItem)->getTable();

        $headerQuery = $this->documentHeaderQuery($query, $documents, $items);
        $this->applyDocumentTotalFilter($headerQuery, $items);
        $this->applyDeterministicOrder($headerQuery, $documents);

        $financial = $this->filters->toLedgerQuery();
        $reportTotalsExact = $financial->periodTotalsExact();
        $reportTotals = [
            'total_debit' => $reportTotalsExact['debit'],
            'total_credit' => $reportTotalsExact['credit'],
        ];

        [$headers, $pagination] = $this->paginateHeaders($headerQuery, $documents);
        $entries = $this->hydrateEntries($headers, $items, $documents);

        $pageDebit = Amount::zero();
        $pageCredit = Amount::zero();
        foreach ($entries as $entry) {
            $pageDebit = $pageDebit->add($entry->totalDebit);
            $pageCredit = $pageCredit->add($entry->totalCredit);
        }

        $branches = $this->filters->branchScope->includeBreakdown
            ? array_values($financial->periodTotalsByBranch())
            : null;

        return new JournalBookResult(
            meta: ReportMeta::make(
                'journal_book',
                $this->filters->toArray(),
                $this->filters->branchScope->toArray(),
                $this->filters->mode->value,
            ),
            summary: [
                'filtered_document_count' => $reportTotalsExact['document_count'],
                'filtered_line_count' => $reportTotalsExact['line_count'],
                'total_debit' => $reportTotals['total_debit'],
                'total_credit' => $reportTotals['total_credit'],
            ],
            data: $entries,
            pagination: $pagination,
            pageTotals: [
                'total_debit' => $pageDebit->toStorage(),
                'total_credit' => $pageCredit->toStorage(),
                'document_count' => count($entries),
            ],
            reportTotals: $reportTotals,
            branches: $branches,
        );
    }

    private function documentHeaderQuery(LedgerQuery $query, string $documents, string $items): QueryBuilder
    {
        return $query->listingQuery()
            ->select([
                "{$documents}.id",
                "{$documents}.number",
                "{$documents}.date",
                "{$documents}.posted_at",
                "{$documents}.type",
                "{$documents}.description",
                "{$documents}.reference",
                "{$documents}.fiscal_year_id",
                "{$documents}.accounting_period_id",
                "{$documents}.branch_id",
                "{$documents}.status",
            ])
            ->selectRaw("COALESCE(SUM({$items}.debit), 0) as document_debit, COALESCE(SUM({$items}.credit), 0) as document_credit")
            ->groupBy(
                "{$documents}.id",
                "{$documents}.number",
                "{$documents}.date",
                "{$documents}.posted_at",
                "{$documents}.type",
                "{$documents}.description",
                "{$documents}.reference",
                "{$documents}.fiscal_year_id",
                "{$documents}.accounting_period_id",
                "{$documents}.branch_id",
                "{$documents}.status",
            );
    }

    private function applyDocumentTotalFilter(QueryBuilder $query, string $items): void
    {
        $min = $this->filters->minAmount;
        $max = $this->filters->maxAmount;
        if ($min === null && $max === null) {
            return;
        }

        if ($min !== null) {
            $query->havingRaw("COALESCE(SUM({$items}.debit), 0) >= ?", [$min]);
        }
        if ($max !== null) {
            $query->havingRaw("COALESCE(SUM({$items}.debit), 0) <= ?", [$max]);
        }
    }

    private function applyDeterministicOrder(QueryBuilder $query, string $documents): void
    {
        $query->orderBy("{$documents}.date")
            ->orderBy("{$documents}.number")
            ->orderBy("{$documents}.id");
    }

    /**
     * @return array{0: list<object>, 1: PaginationMeta}
     */
    private function paginateHeaders(QueryBuilder $headerQuery, string $documents): array
    {
        $pagination = $this->pagination;

        if ($pagination->mode === ReportPagination::MODE_OFFSET) {
            $countQuery = clone $headerQuery;
            $total = (int) DB::query()->fromSub($countQuery, 'journal_headers')->count();
            $rows = $headerQuery->offset($pagination->offsetValue())->limit($pagination->perPage)->get()->all();

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
            $date = (string) ($keys['document_date'] ?? '');
            $number = (int) ($keys['document_number'] ?? 0);
            $id = (int) ($keys['document_id'] ?? 0);
            $headerQuery->where(function (QueryBuilder $nested) use ($documents, $date, $number, $id) {
                $nested->whereDate("{$documents}.date", '>', $date)
                    ->orWhere(function (QueryBuilder $sameDate) use ($documents, $date, $number) {
                        $sameDate->whereDate("{$documents}.date", $date)
                            ->where("{$documents}.number", '>', $number);
                    })
                    ->orWhere(function (QueryBuilder $sameNumber) use ($documents, $date, $number, $id) {
                        $sameNumber->whereDate("{$documents}.date", $date)
                            ->where("{$documents}.number", $number)
                            ->where("{$documents}.id", '>', $id);
                    });
            });
        }

        $rows = $headerQuery->limit($pagination->perPage + 1)->get()->all();
        $hasMore = count($rows) > $pagination->perPage;
        $rows = array_slice($rows, 0, $pagination->perPage);
        $last = $rows !== [] ? $rows[array_key_last($rows)] : null;
        $next = $hasMore && $last
            ? CursorCodec::encode([
                'document_date' => substr((string) $last->date, 0, 10),
                'document_number' => (int) $last->number,
                'document_id' => (int) $last->id,
            ])
            : null;

        return [$rows, PaginationMeta::cursor($pagination->perPage, $hasMore, $next)];
    }

    /**
     * @param  list<object>  $headers
     * @return list<JournalBookEntry>
     */
    private function hydrateEntries(array $headers, string $items, string $documents): array
    {
        if ($headers === []) {
            return [];
        }

        $ids = array_map(fn ($header) => (int) $header->id, $headers);
        $accounts = (new Account)->getTable();

        $lineRows = DB::table($items)
            ->join($documents, "{$documents}.id", '=', "{$items}.document_id")
            ->leftJoin($accounts, "{$accounts}.id", '=', "{$items}.account_id")
            ->whereIn("{$items}.document_id", $ids)
            ->select([
                "{$items}.id as item_id",
                "{$items}.document_id",
                "{$items}.account_id",
                "{$items}.cost_center_id",
                "{$items}.debit",
                "{$items}.credit",
                "{$items}.order as item_order",
                "{$items}.description as item_description",
                "{$accounts}.code as account_code",
                "{$accounts}.title as account_title",
                "{$documents}.branch_id",
            ])
            ->orderBy("{$items}.document_id")
            ->orderBy("{$items}.order")
            ->orderBy("{$items}.id")
            ->get()
            ->groupBy('document_id');

        $entries = [];
        foreach ($headers as $header) {
            $lines = collect($lineRows->get($header->id, collect()))
                ->map(fn ($row) => JournalBookLine::fromRow($row))
                ->values()
                ->all();
            $entries[] = JournalBookEntry::fromHeader($header, $lines);
        }

        return $entries;
    }
}
