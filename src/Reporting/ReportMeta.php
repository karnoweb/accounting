<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Carbon\Carbon;

final class ReportMeta
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $branchScope
     */
    public function __construct(
        public readonly string $report,
        public readonly string $generatedAt,
        public readonly array $filters,
        public readonly array $branchScope,
        public readonly string $mode = 'financial',
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $branchScope
     */
    public static function make(
        string $report,
        array $filters,
        array $branchScope,
        string $mode = 'financial',
    ): self {
        return new self(
            report: $report,
            generatedAt: Carbon::now()->toIso8601String(),
            filters: $filters,
            branchScope: $branchScope,
            mode: $mode,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'report' => $this->report,
            'generated_at' => $this->generatedAt,
            'filters' => $this->filters,
            'branch_scope' => $this->branchScope,
            'mode' => $this->mode,
        ];
    }
}
