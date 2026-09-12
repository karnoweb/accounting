<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting\Aging;

use Karnoweb\Accounting\Reporting\PaginationMeta;
use Karnoweb\Accounting\Reporting\ReportMeta;

final class AgingReportResult
{
    /**
     * @param  list<AgingPartyRow>  $data
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly ReportMeta $meta,
        public readonly array $summary,
        public readonly array $data,
        public readonly PaginationMeta $pagination,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta->toArray(),
            'summary' => $this->summary,
            'data' => array_map(fn (AgingPartyRow $row) => $row->toArray(), $this->data),
            'pagination' => $this->pagination->toArray(),
        ];
    }
}
