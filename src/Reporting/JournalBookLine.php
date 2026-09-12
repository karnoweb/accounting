<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Support\Amount;

final class JournalBookLine
{
    public function __construct(
        public readonly int $documentItemId,
        public readonly int $lineNumber,
        public readonly int $accountId,
        public readonly string $accountCode,
        public readonly string $accountName,
        public readonly ?string $description,
        public readonly string $debit,
        public readonly string $credit,
        public readonly ?int $costCenterId,
        public readonly ?int $branchId,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            documentItemId: (int) $row->item_id,
            lineNumber: (int) $row->item_order,
            accountId: (int) $row->account_id,
            accountCode: (string) ($row->account_code ?? ''),
            accountName: (string) ($row->account_title ?? ''),
            description: $row->item_description !== null ? (string) $row->item_description : null,
            debit: Amount::of($row->debit ?? 0)->toStorage(),
            credit: Amount::of($row->credit ?? 0)->toStorage(),
            costCenterId: $row->cost_center_id !== null ? (int) $row->cost_center_id : null,
            branchId: $row->branch_id !== null ? (int) $row->branch_id : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_item_id' => $this->documentItemId,
            'line_number' => $this->lineNumber,
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'description' => $this->description,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'cost_center_id' => $this->costCenterId,
            'branch_id' => $this->branchId,
        ];
    }
}
