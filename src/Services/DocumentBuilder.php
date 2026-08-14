<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Fluent builder for creating and posting accounting documents.
 *
 * Each Accounting::document() call receives an isolated builder instance.
 * save()/post() reset local state so accidental reuse cannot leak lines.
 *
 * @example
 * Accounting::document()
 *     ->type('receipt')
 *     ->date(now())
 *     ->debit($cash, 1000)
 *     ->credit($receivable, 1000)
 *     ->save();
 */
class DocumentBuilder
{
    private string $type = 'adjustment';

    private string $date;

    private ?string $description = null;

    private ?string $notes = null;

    private ?string $reference = null;

    private ?int $branchId = null;

    private bool $branchSpecified = false;

    private ?int $fiscalYearId = null;

    private ?string $sourceType = null;

    private ?int $sourceId = null;

    private ?string $idempotencyKey = null;

    private ?array $meta = null;

    /** @var array<int, array{account_id: int, amount: float, sign: int, description: ?string, cost_center_id: ?int}> */
    private array $items = [];

    private ?int $lastItemCostCenterId = null;

    public function __construct(
        private DocumentService $documentService,
        private AccountService $accountService
    ) {
        $this->date = now()->toDateString();
    }

    /** Set document type (e.g. 'sale', 'purchase', 'receipt', 'payment', 'transfer', 'adjustment'). */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** Set document date. */
    public function date(Carbon|string $date): self
    {
        $this->date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $this;
    }

    /** Set document description. */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** Set internal notes. */
    public function notes(string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /** Set external reference (e.g. invoice number). */
    public function reference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /** Set branch (model or id). Omit to use config default or null. */
    public function branch(Model|int $branch): self
    {
        $this->branchId = $branch instanceof Model ? $branch->getKey() : (int) $branch;
        $this->branchSpecified = true;

        return $this;
    }

    /** Set fiscal year (model or id). Omit to use current fiscal year. */
    public function fiscalYear(FiscalYear|int $fiscalYear): self
    {
        $this->fiscalYearId = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : (int) $fiscalYear;

        return $this;
    }

    /** Set source model for polymorphic link (e.g. Order, Invoice). */
    public function source(Model $model): self
    {
        $this->sourceType = $model->getMorphClass();
        $this->sourceId = (int) $model->getKey();

        return $this;
    }

    /**
     * Optional DB-unique key for safe retries. Null/empty skips uniqueness.
     */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * Set arbitrary meta array for the document.
     *
     * @param array<string, mixed> $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /** Add a debit line (sign = 1). Optionally set description for this line. */
    public function debit(Account|int $account, float $amount, ?string $description = null): self
    {
        $this->addItem($account, $amount, 1, $description);

        return $this;
    }

    /** Add a credit line (sign = -1). Optionally set description for this line. */
    public function credit(Account|int $account, float $amount, ?string $description = null): self
    {
        $this->addItem($account, $amount, -1, $description);

        return $this;
    }

    /** Set cost center for the next debit/credit line (or the last one if already added). */
    public function costCenter(CostCenter|int|null $center): self
    {
        $this->lastItemCostCenterId = $center instanceof CostCenter ? $center->id : ($center !== null ? (int) $center : null);

        if (count($this->items) > 0) {
            $lastIndex = array_key_last($this->items);
            $this->items[$lastIndex]['cost_center_id'] = $this->lastItemCostCenterId;
        }

        return $this;
    }

    /** Create the document in draft status. Returns the created Document with items and account relations loaded. */
    public function save(): Document
    {
        $document = $this->documentService->create($this->toArray());
        $this->reset();

        return $document;
    }

    /** Create the document and post it in one step. Returns the posted Document. */
    public function post(): Document
    {
        $document = $this->documentService->create($this->toArray());
        $posted = $this->documentService->post($document);
        $this->reset();

        return $posted;
    }

    /** @return array{type: string, date: string, description: ?string, notes: ?string, reference: ?string, fiscal_year_id: ?int, source_type: ?string, source_id: ?int, idempotency_key: ?string, meta: ?array, items: array, branch_id?: ?int} */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'date' => $this->date,
            'description' => $this->description,
            'notes' => $this->notes,
            'reference' => $this->reference,
            'fiscal_year_id' => $this->fiscalYearId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'idempotency_key' => $this->idempotencyKey,
            'meta' => $this->meta,
            'items' => array_values($this->items),
        ];

        // ponytail: omit unspecified branch_id so DocumentService can distinguish omitted vs explicit null
        if ($this->branchSpecified) {
            $data['branch_id'] = $this->branchId;
        }

        return $data;
    }

    private function addItem(Account|int $account, float $amount, int $sign, ?string $description): void
    {
        $resolved = $this->accountService->assertPostable($account);

        $this->items[] = [
            'account_id' => $resolved->id,
            'amount' => round($amount, 2),
            'sign' => $sign,
            'description' => $description,
            'cost_center_id' => $this->lastItemCostCenterId,
        ];

        $this->lastItemCostCenterId = null;
    }

    private function reset(): void
    {
        $this->type = 'adjustment';
        $this->date = now()->toDateString();
        $this->description = null;
        $this->notes = null;
        $this->reference = null;
        $this->branchId = null;
        $this->branchSpecified = false;
        $this->fiscalYearId = null;
        $this->sourceType = null;
        $this->sourceId = null;
        $this->idempotencyKey = null;
        $this->meta = null;
        $this->items = [];
        $this->lastItemCostCenterId = null;
    }
}
