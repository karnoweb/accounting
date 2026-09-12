<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Support\Amount;

final class DailyJournalRow
{
    public function __construct(
        public readonly string $date,
        public readonly ?int $branchId,
        public readonly ?int $accountId,
        public readonly ?string $documentType,
        public readonly int $documentCount,
        public readonly int $lineCount,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        public readonly string $netDifference,
        public readonly ?int $firstDocumentNumber,
        public readonly ?int $lastDocumentNumber,
        public readonly ?string $firstPostedAt,
        public readonly ?string $lastPostedAt,
    ) {}

    public static function fromRow(object $row): self
    {
        $debit = Amount::of($row->total_debit ?? 0);
        $credit = Amount::of($row->total_credit ?? 0);

        return new self(
            date: substr((string) $row->report_date, 0, 10),
            branchId: isset($row->branch_id) && $row->branch_id !== null ? (int) $row->branch_id : null,
            accountId: isset($row->account_id) && $row->account_id !== null ? (int) $row->account_id : null,
            documentType: isset($row->document_type) && $row->document_type !== null ? (string) $row->document_type : null,
            documentCount: (int) ($row->document_count ?? 0),
            lineCount: (int) ($row->line_count ?? 0),
            totalDebit: $debit->toStorage(),
            totalCredit: $credit->toStorage(),
            netDifference: $debit->subtract($credit)->toStorage(),
            firstDocumentNumber: $row->first_document_number !== null ? (int) $row->first_document_number : null,
            lastDocumentNumber: $row->last_document_number !== null ? (int) $row->last_document_number : null,
            firstPostedAt: $row->first_posted_at !== null ? (string) $row->first_posted_at : null,
            lastPostedAt: $row->last_posted_at !== null ? (string) $row->last_posted_at : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'branch_id' => $this->branchId,
            'account_id' => $this->accountId,
            'document_type' => $this->documentType,
            'document_count' => $this->documentCount,
            'line_count' => $this->lineCount,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'net_difference' => $this->netDifference,
            'first_document_number' => $this->firstDocumentNumber,
            'last_document_number' => $this->lastDocumentNumber,
            'first_posted_at' => $this->firstPostedAt,
            'last_posted_at' => $this->lastPostedAt,
        ];
    }
}
