<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class PaginationMeta
{
    public function __construct(
        public readonly string $mode,
        public readonly int $perPage,
        public readonly bool $hasMore,
        public readonly ?string $nextCursor = null,
        public readonly ?string $previousCursor = null,
        public readonly ?int $page = null,
        public readonly ?int $total = null,
        public readonly ?int $lastPage = null,
    ) {}

    public static function cursor(
        int $perPage,
        bool $hasMore,
        ?string $nextCursor = null,
        ?string $previousCursor = null,
    ): self {
        return new self(
            mode: ReportPagination::MODE_CURSOR,
            perPage: $perPage,
            hasMore: $hasMore,
            nextCursor: $nextCursor,
            previousCursor: $previousCursor,
        );
    }

    public static function offset(
        int $page,
        int $perPage,
        int $total,
        bool $hasMore,
    ): self {
        $lastPage = max(1, (int) ceil($total / max($perPage, 1)));

        return new self(
            mode: ReportPagination::MODE_OFFSET,
            perPage: $perPage,
            hasMore: $hasMore,
            page: $page,
            total: $total,
            lastPage: $lastPage,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'mode' => $this->mode,
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore,
        ];

        if ($this->mode === ReportPagination::MODE_CURSOR) {
            $payload['next_cursor'] = $this->nextCursor;
            $payload['previous_cursor'] = $this->previousCursor;
        }

        if ($this->mode === ReportPagination::MODE_OFFSET) {
            $payload['page'] = $this->page;
            $payload['total'] = $this->total;
            $payload['last_page'] = $this->lastPage;
        }

        return $payload;
    }
}
