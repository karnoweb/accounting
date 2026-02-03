<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Enums;

enum FiscalYearStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('accounting::accounting.fiscal_year_statuses.draft'),
            self::ACTIVE => __('accounting::accounting.fiscal_year_statuses.active'),
            self::CLOSED => __('accounting::accounting.fiscal_year_statuses.closed'),
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
