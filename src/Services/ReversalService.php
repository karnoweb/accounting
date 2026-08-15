<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\DocumentNotReversibleException;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Same-FY full-document operational reversal.
 *
 * Creates a posted type=reversal journal that inverts persisted original items.
 * Does not mutate the original. Closed-FY correction is not implemented.
 */
class ReversalService
{
    public function __construct(
        private DocumentService $documentService
    ) {}

    /**
     * @param  array{reason?: ?string, date?: ?string}  $options
     */
    public function reverse(Document|int $document, array $options = []): Document
    {
        return DB::transaction(function () use ($document, $options) {
            $id = $document instanceof Document ? $document->id : $document;
            $original = Document::query()->findOrFail($id);

            $fiscalYear = FiscalYear::query()
                ->whereKey($original->fiscal_year_id)
                ->lockForUpdate()
                ->firstOrFail();

            $original = Document::query()
                ->whereKey($original->id)
                ->lockForUpdate()
                ->firstOrFail();

            $original->loadMissing('items');

            $key = $this->idempotencyKey($original);
            $existingByKey = Document::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existingByKey) {
                if ($this->isMatchingPostedReversal($existingByKey, $original)) {
                    return $existingByKey->load('items.account');
                }

                throw new DuplicateIdempotencyKeyException($key);
            }

            $postedReversal = $this->findPostedReversal($original);
            if ($postedReversal) {
                return $postedReversal->load('items.account');
            }

            $this->assertReversible($original, $fiscalYear);

            try {
                $created = $this->documentService->create($this->payload($original, $options, $key));

                return $this->documentService->post($created);
            } catch (DuplicateIdempotencyKeyException $e) {
                $retry = $this->findPostedReversal($original)
                    ?? Document::query()->where('idempotency_key', $key)->first();

                if ($retry && $this->isMatchingPostedReversal($retry, $original)) {
                    return $retry->load('items.account');
                }

                throw $e;
            }
        });
    }

    public function idempotencyKey(Document $original): string
    {
        return 'reversal:'.$original->id;
    }

    private function assertReversible(Document $original, FiscalYear $fiscalYear): void
    {
        if ($fiscalYear->isClosed()) {
            throw new ClosedFiscalYearException($fiscalYear);
        }

        if ( ! $fiscalYear->isActive()) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.fiscal_year_not_active')
            );
        }

        if ( ! $original->isPosted()) {
            throw new DocumentNotReversibleException($original);
        }

        if (in_array($original->type, ['opening', 'closing'], true)) {
            throw new DocumentNotReversibleException(
                $original,
                __('accounting::accounting.messages.document_type_not_reversible')
            );
        }

        $hasPostedClosing = Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->where('type', 'closing')
            ->lockForUpdate()
            ->exists();

        if ($hasPostedClosing) {
            throw new DocumentNotReversibleException(
                $original,
                __('accounting::accounting.messages.document_has_posted_closing')
            );
        }
    }

    /**
     * @param  array{reason?: ?string, date?: ?string}  $options
     * @return array<string, mixed>
     */
    private function payload(Document $original, array $options, string $key): array
    {
        $date = $original->date->toDateString();
        if (array_key_exists('date', $options) && $options['date'] !== null && $options['date'] !== '') {
            $date = (string) $options['date'];
        }

        $reason = isset($options['reason']) ? (string) $options['reason'] : '';

        return [
            'type' => 'reversal',
            'date' => $date,
            'fiscal_year_id' => $original->fiscal_year_id,
            'branch_id' => $original->branch_id,
            'source_type' => $original->source_type,
            'source_id' => $original->source_id,
            'idempotency_key' => $key,
            'reversed_document_id' => $original->id,
            'notes' => $reason !== '' ? $reason : null,
            'meta' => [
                'operation' => 'reversal',
                'original_document_id' => $original->id,
                'original_type' => $original->type,
                'reason' => $reason !== '' ? $reason : null,
            ],
            'items' => $this->invertedItems($original),
        ];
    }

    /**
     * @return list<array{account_id: int, amount: float, sign: int, cost_center_id: ?int, description: ?string, order: int, meta: array<string, mixed>}>
     */
    private function invertedItems(Document $original): array
    {
        return $original->items
            ->sortBy(fn ($item) => sprintf('%d:%d', (int) $item->order, (int) $item->id))
            ->values()
            ->map(function ($item) {
                $meta = is_array($item->meta) ? $item->meta : [];
                $meta['original_item_id'] = $item->id;

                return [
                    'account_id' => (int) $item->account_id,
                    'amount' => (float) $item->amount,
                    'sign' => -((int) $item->sign),
                    'cost_center_id' => $item->cost_center_id !== null ? (int) $item->cost_center_id : null,
                    'description' => $item->description,
                    'order' => (int) $item->order,
                    'meta' => $meta,
                ];
            })
            ->all();
    }

    private function findPostedReversal(Document $original): ?Document
    {
        return Document::query()
            ->where('reversed_document_id', $original->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    private function isMatchingPostedReversal(Document $candidate, Document $original): bool
    {
        return $candidate->isPosted()
            && $candidate->type === 'reversal'
            && (int) $candidate->reversed_document_id === (int) $original->id;
    }
}
