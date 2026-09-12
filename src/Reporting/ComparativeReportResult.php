<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class ComparativeReportResult
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  list<ComparisonRow>  $data
     * @param  array<string, string>  $pageTotals
     * @param  array<string, string>  $reportTotals
     */
    public function __construct(
        public readonly ReportMeta $meta,
        public readonly array $summary,
        public readonly array $data,
        public readonly PaginationMeta $pagination,
        public readonly array $pageTotals,
        public readonly array $reportTotals,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta->toArray(),
            'summary' => $this->summary,
            'page_totals' => $this->pageTotals,
            'report_totals' => $this->reportTotals,
            'data' => array_map(fn (ComparisonRow $row) => $row->toArray(), $this->data),
            'pagination' => $this->pagination->toArray(),
        ];
    }
}
