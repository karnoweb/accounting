<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\DocumentNumberSequence;
use Karnoweb\Accounting\Models\FiscalYear;
use RuntimeException;

class DocumentService
{
    public function __construct(
        private BalanceService $balanceService,
        private AccountService $accountService,
        private PostingService $postingService
    ) {}

    public function create(array $data): Document
    {
        $maxAttempts = max(1, (int) config('accounting.document.number_allocation_retries', 5));
        $attempt = 0;
        $manualNumber = array_key_exists('number', $data);

        while (true) {
            $attempt++;

            try {
                return DB::transaction(function () use ($data, $manualNumber) {
                    $fiscalYear = $this->resolveFiscalYear($data);
                    $this->validateFiscalYear(
                        $fiscalYear,
                        $data['date'],
                        isset($data['type']) ? (string) $data['type'] : null,
                        array_key_exists('branch_id', $data)
                            ? ($data['branch_id'] !== null ? (int) $data['branch_id'] : null)
                            : null
                    );
                    $this->validateItems($data['items'] ?? []);
                    $this->assertIdempotencyKeyAvailable($data['idempotency_key'] ?? null);

                    $branchId = array_key_exists('branch_id', $data)
                        ? $data['branch_id']
                        : $this->getDefaultBranchId();
                    $number = $manualNumber
                        ? (int) $data['number']
                        : $this->allocateNextNumber($fiscalYear, $branchId);

                    $document = Document::create([
                        'fiscal_year_id' => $fiscalYear->id,
                        'branch_id' => $branchId,
                        'number' => $number,
                        'reference' => $data['reference'] ?? null,
                        'date' => $data['date'],
                        'type' => $data['type'],
                        'status' => $data['status'] ?? DocumentStatus::DRAFT,
                        'description' => $data['description'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'source_type' => $data['source_type'] ?? null,
                        'source_id' => $data['source_id'] ?? null,
                        'idempotency_key' => $data['idempotency_key'] ?? null,
                        'created_by' => $this->currentUserId(),
                        'meta' => $data['meta'] ?? null,
                    ]);

                    $this->createItems($document, $data['items']);

                    return $document->load('items.account');
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($this->isIdempotencyConflict($e)) {
                    throw new DuplicateIdempotencyKeyException((string) ($data['idempotency_key'] ?? ''), previous: $e);
                }

                if ($manualNumber || $attempt >= $maxAttempts || ! $this->isDocumentNumberConflict($e)) {
                    throw $e;
                }
            } catch (QueryException $e) {
                // SQLite / older drivers may not throw UniqueConstraintViolationException.
                if ($this->isIdempotencyConflict($e)) {
                    throw new DuplicateIdempotencyKeyException((string) ($data['idempotency_key'] ?? ''), previous: $e);
                }

                if ($manualNumber || $attempt >= $maxAttempts || ! $this->isDocumentNumberConflict($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Canonical posting path for the package. Document::post() delegates here.
     */
    public function post(Document|int $document): Document
    {
        $document = $document instanceof Document
            ? $document->loadMissing(['items.account', 'fiscalYear'])
            : Document::with(['items.account', 'fiscalYear'])->findOrFail($document);

        if ( ! $document->status->canPost()) {
            throw new Exception(__('accounting::accounting.messages.document_cannot_post'));
        }

        $minItems = (int) config('accounting.document.min_items', 2);
        if ($document->items->count() < $minItems) {
            throw new InvalidArgumentException(__('accounting::accounting.validation.items_required', ['min' => $minItems]));
        }

        if ( ! $this->isBalanced($document)) {
            throw new UnbalancedDocumentException(
                $document->debit_total,
                $document->credit_total
            );
        }

        $this->validateFiscalYear(
            $document->fiscalYear,
            $document->date->format('Y-m-d'),
            $document->type,
            $document->branch_id
        );

        foreach ($document->items as $item) {
            $account = $item->account ?? Account::find($item->account_id);
            if ( ! $account) {
                throw new InvalidArgumentException(__('accounting::accounting.validation.account_invalid'));
            }
            $this->accountService->assertPostable($account);
        }

        return DB::transaction(function () use ($document) {
            return $document->markAsPosted($this->currentUserId());
        });
    }

    /**
     * Allocate the next document number under a row lock (concurrency-safe).
     */
    public function getNextNumber(?FiscalYear $fiscalYear = null, ?int $branchId = null): int
    {
        $fiscalYear ??= FiscalYear::current();

        if ( ! $fiscalYear) {
            throw new RuntimeException(__('accounting::accounting.messages.no_active_fiscal_year'));
        }

        return DB::transaction(function () use ($fiscalYear, $branchId) {
            return $this->allocateNextNumber($fiscalYear, $branchId);
        });
    }

    public function isBalanced(Document $document): bool
    {
        $balance = $document->items->sum(fn ($item) => $item->amount * $item->sign);

        return abs($balance) < 0.01;
    }

    /**
     * Persist posted status without re-entering Document::post() (avoids recursion).
     *
     * @internal Prefer DocumentService::post() / Document::post().
     */
    // markAsPosted lives on Document

    private function allocateNextNumber(FiscalYear $fiscalYear, ?int $branchId): int
    {
        $sequenceBranchId = $this->sequenceBranchId($branchId);

        $sequence = DocumentNumberSequence::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('branch_id', $sequenceBranchId)
            ->lockForUpdate()
            ->first();

        if ( ! $sequence) {
            // Seed from existing max so upgrades keep sequential behavior.
            $seedQuery = Document::withTrashed()->where('fiscal_year_id', $fiscalYear->id);
            if (config('accounting.branch.separate_numbering', false) && $branchId) {
                $seedQuery->where('branch_id', $branchId);
            }
            $seed = (int) ($seedQuery->max('number') ?? 0);

            try {
                $sequence = DocumentNumberSequence::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'branch_id' => $sequenceBranchId,
                    'last_number' => $seed,
                ]);
            } catch (UniqueConstraintViolationException|QueryException) {
                $sequence = DocumentNumberSequence::query()
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->where('branch_id', $sequenceBranchId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        $sequence->last_number = (int) $sequence->last_number + 1;
        $sequence->save();

        return (int) $sequence->last_number;
    }

    private function sequenceBranchId(?int $branchId): int
    {
        if (config('accounting.branch.separate_numbering', false) && $branchId) {
            return $branchId;
        }

        return 0;
    }

    private function validateItems(array $items): void
    {
        $minItems = config('accounting.document.min_items', 2);

        if (count($items) < $minItems) {
            throw new InvalidArgumentException(__('accounting::accounting.validation.items_required', ['min' => $minItems]));
        }

        foreach ($items as $item) {
            $account = Account::find($item['account_id'] ?? null);

            if ( ! $account) {
                throw new InvalidArgumentException(__('accounting::accounting.validation.account_invalid'));
            }

            $this->accountService->assertPostable($account);
        }

        $balance = 0;
        foreach ($items as $item) {
            $balance += ($item['amount'] ?? 0) * ($item['sign'] ?? 1);
        }

        if (config('accounting.validation.strict_balance', true) && abs($balance) >= 0.01) {
            $debit = collect($items)->where('sign', 1)->sum('amount');
            $credit = collect($items)->where('sign', -1)->sum('amount');

            throw new UnbalancedDocumentException($debit, $credit);
        }
    }

    private function createItems(Document $document, array $items): void
    {
        foreach ($items as $index => $item) {
            DocumentItem::create([
                'document_id' => $document->id,
                'account_id' => $item['account_id'],
                'cost_center_id' => $item['cost_center_id'] ?? null,
                'amount' => $item['amount'],
                'sign' => $item['sign'],
                'description' => $item['description'] ?? null,
                'order' => $item['order'] ?? $index,
                'meta' => $item['meta'] ?? null,
            ]);
        }
    }

    private function resolveFiscalYear(array $data): FiscalYear
    {
        if ( ! empty($data['fiscal_year_id'])) {
            return FiscalYear::findOrFail($data['fiscal_year_id']);
        }

        if (config('accounting.fiscal_year.auto_detect', true) && ! empty($data['date'])) {
            $fiscalYear = FiscalYear::findByDate($data['date']);
            if ($fiscalYear) {
                return $fiscalYear;
            }
        }

        $current = FiscalYear::current();
        if ($current) {
            return $current;
        }

        throw new RuntimeException(__('accounting::accounting.messages.no_active_fiscal_year'));
    }

    private function validateFiscalYear(
        FiscalYear $fiscalYear,
        string|\DateTimeInterface $date,
        ?string $type = null,
        ?int $branchId = null
    ): void {
        $this->postingService->assertAllowed($date, $fiscalYear, $type, $branchId);
    }

    private function getDefaultBranchId(): ?int
    {
        if ( ! config('accounting.branch.enabled', true)) {
            return null;
        }

        $resolver = config('accounting.branch.resolver');
        if ($resolver && is_callable($resolver)) {
            return $resolver();
        }

        return config('accounting.branch.default_id');
    }

    private function currentUserId(): ?int
    {
        // ponytail: optional auth; package stays HTTP-agnostic when no guard is bound
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertIdempotencyKeyAvailable(?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }

        if (Document::withTrashed()->where('idempotency_key', $key)->exists()) {
            throw new DuplicateIdempotencyKeyException($key);
        }
    }

    private function isDocumentNumberConflict(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'documents_fiscal_year_id_number_unique')
            || str_contains($message, 'acc_documents_fiscal_year_id_number_unique')
            || (bool) preg_match('/unique constraint failed:\s*[`"\']?[\w.]*fiscal_year_id[`"\']?\s*,\s*[`"\']?[\w.]*number/i', $message);
    }

    private function isIdempotencyConflict(QueryException $e): bool
    {
        $message = $e->getMessage();

        // Do not match bare "idempotency_key" — SQL insert payloads always include the column name.
        return str_contains($message, 'acc_documents_idempotency_key_unique')
            || (bool) preg_match('/unique constraint failed:\s*[`"\']?[\w.]*idempotency_key/i', $message);
    }
}
