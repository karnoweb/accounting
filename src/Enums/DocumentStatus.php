<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Enums;

enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case VOIDED = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('accounting::accounting.document_statuses.draft'),
            self::PENDING => __('accounting::accounting.document_statuses.pending'),
            self::APPROVED => __('accounting::accounting.document_statuses.approved'),
            self::POSTED => __('accounting::accounting.document_statuses.posted'),
            self::VOIDED => __('accounting::accounting.document_statuses.voided'),
        };
    }

    public function canPost(): bool
    {
        return in_array($this, [self::DRAFT, self::APPROVED], true);
    }

    public function isVoidable(): bool
    {
        return $this === self::POSTED;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
