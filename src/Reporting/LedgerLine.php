<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Carbon\Carbon;

/**
 * One journal line as read from the posted ledger (acc_document_items JOIN acc_documents).
 *
 * Carries everything a consumer needs to render a General Ledger / Account Statement row
 * without further queries.
 */
final class LedgerLine
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $documentId,
        public readonly int $accountId,
        public readonly Carbon $date,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly ?string $documentDescription,
        public readonly ?string $reference,
        public readonly ?string $sourceType,
        public readonly ?int $sourceId,
        public readonly float $debit,
        public readonly float $credit,
        public readonly ?int $fiscalYearId,
        public readonly ?int $branchId,
        public readonly int $order,
        public float $runningBalance = 0.0,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            itemId: (int) $row->item_id,
            documentId: (int) $row->document_id,
            accountId: (int) $row->account_id,
            date: Carbon::parse($row->date),
            documentNumber: (string) $row->document_number,
            documentType: (string) $row->document_type,
            documentDescription: $row->document_description,
            reference: $row->reference,
            sourceType: $row->source_type,
            sourceId: $row->source_id !== null ? (int) $row->source_id : null,
            debit: (float) $row->debit,
            credit: (float) $row->credit,
            fiscalYearId: $row->fiscal_year_id !== null ? (int) $row->fiscal_year_id : null,
            branchId: $row->branch_id !== null ? (int) $row->branch_id : null,
            order: (int) $row->item_order,
        );
    }

    public function signedAmount(): float
    {
        return $this->debit - $this->credit;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'document_id' => $this->documentId,
            'account_id' => $this->accountId,
            'date' => $this->date->toDateString(),
            'document_number' => $this->documentNumber,
            'document_type' => $this->documentType,
            'document_description' => $this->documentDescription,
            'reference' => $this->reference,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'signed_amount' => $this->signedAmount(),
            'running_balance' => $this->runningBalance,
            'fiscal_year_id' => $this->fiscalYearId,
            'branch_id' => $this->branchId,
            'order' => $this->order,
        ];
    }
}
