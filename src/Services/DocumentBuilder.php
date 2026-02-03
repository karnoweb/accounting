<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Branch;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;

class DocumentBuilder
{
    private string $type = 'adjustment';

    private string $date;

    private ?string $description = null;

    private ?string $notes = null;

    private ?string $reference = null;

    private ?int $branchId = null;

    private ?int $fiscalYearId = null;

    private ?string $sourceType = null;

    private ?int $sourceId = null;

    private ?array $meta = null;

    /** @var array<int, array{account_id: int, amount: float, sign: int, description: ?string, cost_center_id: ?int}> */
    private array $items = [];

    private ?int $lastItemCostCenterId = null;

    public function __construct(
        private DocumentService $documentService
    ) {
        $this->date = now()->toDateString();
    }

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function date(Carbon|string $date): self
    {
        $this->date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function notes(string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function reference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function branch(Branch|Model|int $branch): self
    {
        $this->branchId = $branch instanceof Model ? $branch->getKey() : (int) $branch;

        return $this;
    }

    public function fiscalYear(FiscalYear|int $fiscalYear): self
    {
        $this->fiscalYearId = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : (int) $fiscalYear;

        return $this;
    }

    public function source(Model $model): self
    {
        $this->sourceType = $model->getMorphClass();
        $this->sourceId = (int) $model->getKey();

        return $this;
    }

    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function debit(Account|int $account, float $amount, ?string $description = null): self
    {
        $this->addItem($account, $amount, 1, $description);

        return $this;
    }

    public function credit(Account|int $account, float $amount, ?string $description = null): self
    {
        $this->addItem($account, $amount, -1, $description);

        return $this;
    }

    public function costCenter(CostCenter|int|null $center): self
    {
        $this->lastItemCostCenterId = $center instanceof CostCenter ? $center->id : ($center !== null ? (int) $center : null);

        if (count($this->items) > 0) {
            $lastIndex = array_key_last($this->items);
            $this->items[$lastIndex]['cost_center_id'] = $this->lastItemCostCenterId;
        }

        return $this;
    }

    public function save(): Document
    {
        return $this->documentService->create($this->toArray());
    }

    public function post(): Document
    {
        $document = $this->documentService->create($this->toArray());

        return $this->documentService->post($document);
    }

    /** @return array{type: string, date: string, description: ?string, notes: ?string, reference: ?string, branch_id: ?int, fiscal_year_id: ?int, source_type: ?string, source_id: ?int, meta: ?array, items: array} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'date' => $this->date,
            'description' => $this->description,
            'notes' => $this->notes,
            'reference' => $this->reference,
            'branch_id' => $this->branchId,
            'fiscal_year_id' => $this->fiscalYearId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'meta' => $this->meta,
            'items' => array_values($this->items),
        ];
    }

    private function addItem(Account|int $account, float $amount, int $sign, ?string $description): void
    {
        $accountId = $account instanceof Account ? $account->id : (int) $account;

        $this->items[] = [
            'account_id' => $accountId,
            'amount' => round($amount, 2),
            'sign' => $sign,
            'description' => $description,
            'cost_center_id' => $this->lastItemCostCenterId,
        ];

        $this->lastItemCostCenterId = null;
    }
}
