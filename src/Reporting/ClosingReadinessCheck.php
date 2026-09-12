<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class ClosingReadinessCheck
{
    public function __construct(
        public readonly string $code,
        public readonly bool $passed,
        public readonly string $severity,
        public readonly int $count,
        public readonly string $message,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'passed' => $this->passed,
            'severity' => $this->severity,
            'count' => $this->count,
            'message' => $this->message,
        ];
    }
}
