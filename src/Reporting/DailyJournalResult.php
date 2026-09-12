<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class DailyJournalResult
{
    /**
     * @param  list<DailyJournalRow>  $data
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $pageTotals
     * @param  array<string, string>  $reportTotals
     * @param  list<array<string, mixed>>|null  $branches
     */
    public function __construct(
        public readonly ReportMeta $meta,
        public readonly array $summary,
        public readonly array $data,
        public readonly PaginationMeta $pagination,
        public readonly array $pageTotals,
        public readonly array $reportTotals,
        public readonly ?array $branches = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta->toArray(),
            'summary' => $this->summary,
            'page_totals' => $this->pageTotals,
            'report_totals' => $this->reportTotals,
            'branches' => $this->branches,
            'data' => array_map(fn (DailyJournalRow $row) => $row->toArray(), $this->data),
            'pagination' => $this->pagination->toArray(),
        ];
    }
}
