<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting\Aging;

/**
 * One open receivable/payable item supplied by an external module.
 */
final class AgingOpenItem
{
    public function __construct(
        public readonly string $sourceType,
        public readonly int|string $sourceId,
        public readonly ?string $documentNumber,
        public readonly string $documentDate,
        public readonly string $dueDate,
        public readonly string $originalAmount,
        public readonly string $settledAmount,
        public readonly string $outstandingAmount,
        public readonly int $daysOverdue,
        public readonly string $agingBucket,
        public readonly ?int $branchId,
        public readonly ?int $costCenterId,
        public readonly int|string|null $partyId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'document_number' => $this->documentNumber,
            'document_date' => $this->documentDate,
            'due_date' => $this->dueDate,
            'original_amount' => $this->originalAmount,
            'settled_amount' => $this->settledAmount,
            'outstanding_amount' => $this->outstandingAmount,
            'days_overdue' => $this->daysOverdue,
            'aging_bucket' => $this->agingBucket,
            'branch_id' => $this->branchId,
            'cost_center_id' => $this->costCenterId,
            'party_id' => $this->partyId,
        ];
    }
}
