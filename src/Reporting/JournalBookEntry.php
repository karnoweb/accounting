<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Support\Amount;

final class JournalBookEntry
{
    /**
     * @param  list<JournalBookLine>  $lines
     */
    public function __construct(
        public readonly int $documentId,
        public readonly int $documentNumber,
        public readonly string $documentDate,
        public readonly ?string $postedAt,
        public readonly string $documentType,
        public readonly ?string $description,
        public readonly ?string $reference,
        public readonly ?int $fiscalYearId,
        public readonly ?int $accountingPeriodId,
        public readonly ?int $branchId,
        public readonly string $status,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        public readonly int $lineCount,
        public readonly array $lines,
    ) {}

    /**
     * @param  list<JournalBookLine>  $lines
     */
    public static function fromHeader(object $header, array $lines): self
    {
        $debit = Amount::sum(array_map(fn (JournalBookLine $line) => $line->debit, $lines));
        $credit = Amount::sum(array_map(fn (JournalBookLine $line) => $line->credit, $lines));

        return new self(
            documentId: (int) $header->id,
            documentNumber: (int) $header->number,
            documentDate: substr((string) $header->date, 0, 10),
            postedAt: $header->posted_at !== null ? (string) $header->posted_at : null,
            documentType: (string) $header->type,
            description: $header->description !== null ? (string) $header->description : null,
            reference: $header->reference !== null ? (string) $header->reference : null,
            fiscalYearId: $header->fiscal_year_id !== null ? (int) $header->fiscal_year_id : null,
            accountingPeriodId: $header->accounting_period_id !== null ? (int) $header->accounting_period_id : null,
            branchId: $header->branch_id !== null ? (int) $header->branch_id : null,
            status: (string) $header->status,
            totalDebit: $debit->toStorage(),
            totalCredit: $credit->toStorage(),
            lineCount: count($lines),
            lines: $lines,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'document_number' => $this->documentNumber,
            'document_date' => $this->documentDate,
            'posting_date' => $this->documentDate,
            'posted_at' => $this->postedAt,
            'document_type' => $this->documentType,
            'description' => $this->description,
            'reference' => $this->reference,
            'fiscal_year_id' => $this->fiscalYearId,
            'accounting_period_id' => $this->accountingPeriodId,
            'branch_id' => $this->branchId,
            'status' => $this->status,
            'effective_state' => $this->status,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'line_count' => $this->lineCount,
            'lines' => array_map(fn (JournalBookLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
