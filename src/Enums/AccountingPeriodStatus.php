<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Enums;

enum AccountingPeriodStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('accounting::accounting.accounting_period_statuses.draft'),
            self::OPEN => __('accounting::accounting.accounting_period_statuses.open'),
            self::CLOSED => __('accounting::accounting.accounting_period_statuses.closed'),
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
