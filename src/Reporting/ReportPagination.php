<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;

/**
 * Requested pagination. Cursor/keyset is the default for large sequential reports.
 * Offset is available when a page number is required; it computes COUNT(*) only
 * when mode=offset or include_total is true.
 */
final class ReportPagination
{
    public const MODE_CURSOR = 'cursor';

    public const MODE_OFFSET = 'offset';

    private function __construct(
        public readonly string $mode,
        public readonly int $perPage,
        public readonly int $page,
        public readonly ?string $cursor,
        public readonly bool $includeTotal,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, string $defaultMode = self::MODE_CURSOR): self
    {
        $mode = (string) ($input['pagination_mode'] ?? $input['mode'] ?? $defaultMode);
        if (! in_array($mode, [self::MODE_CURSOR, self::MODE_OFFSET], true)) {
            throw new InvalidReportFilterException('Unsupported pagination mode.');
        }

        $min = max(1, (int) config('accounting.reports.per_page_min', 1));
        $max = max($min, (int) config('accounting.reports.per_page_max', 200));
        $default = (int) config('accounting.reports.per_page', 50);
        $perPage = (int) ($input['per_page'] ?? $default);

        if ($perPage < $min || $perPage > $max) {
            throw new InvalidReportFilterException(
                "per_page must be between {$min} and {$max}."
            );
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $cursor = isset($input['cursor']) && $input['cursor'] !== ''
            ? (string) $input['cursor']
            : null;

        return new self(
            mode: $mode,
            perPage: $perPage,
            page: $page,
            cursor: $cursor,
            includeTotal: filter_var($input['include_total'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public static function cursor(int $perPage = 50, ?string $cursor = null): self
    {
        return self::fromInput([
            'pagination_mode' => self::MODE_CURSOR,
            'per_page' => $perPage,
            'cursor' => $cursor,
        ]);
    }

    public static function offset(int $page = 1, int $perPage = 50, bool $includeTotal = true): self
    {
        return self::fromInput([
            'pagination_mode' => self::MODE_OFFSET,
            'page' => $page,
            'per_page' => $perPage,
            'include_total' => $includeTotal,
        ]);
    }

    public function offsetValue(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
