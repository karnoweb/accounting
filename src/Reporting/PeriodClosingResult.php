<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class PeriodClosingResult
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $temporaryAccounts
     * @param  list<array<string, mixed>>  $temporaryAccountRows
     * @param  list<array<string, mixed>>|null  $branches
     */
    public function __construct(
        public readonly ReportMeta $meta,
        public readonly array $header,
        public readonly array $activity,
        public readonly array $readiness,
        public readonly array $temporaryAccounts,
        public readonly array $temporaryAccountRows,
        public readonly PaginationMeta $pagination,
        public readonly ?array $branches = null,
    ) {}

    public function isReadyToClose(): bool
    {
        return (bool) $this->readiness['ready_to_close'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta->toArray(),
            'summary' => [
                'header' => $this->header,
                'activity' => $this->activity,
                'readiness' => $this->readiness,
                'temporary_accounts' => $this->temporaryAccounts,
            ],
            'data' => $this->temporaryAccountRows,
            'branches' => $this->branches,
            'pagination' => $this->pagination->toArray(),
        ];
    }
}
