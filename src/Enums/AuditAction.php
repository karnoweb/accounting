<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Enums;

enum AuditAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case POSTED = 'posted';
    case VOIDED = 'voided';
    case RESTORED = 'restored';
}
