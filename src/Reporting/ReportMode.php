<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

enum ReportMode: string
{
    case Financial = 'financial';
    case Audit = 'audit';
}
