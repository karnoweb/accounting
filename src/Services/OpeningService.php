<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;

/**
 * Opening journals: draft → confirm (or one-shot post()), and carryForward() from a
 * closed source year.
 *
 * The opening for a (fiscal year, branch bucket) starts as `type=opening, status=draft`
 * via saveDraft(). A draft MAY be unbalanced — balance is enforced only when confirm()
 * posts it. `opening_done` is never set by saveDraft(); confirm() sets it once no
 * draft opening remains for the fiscal year (see maybeCompleteOpening()).
 *
 * post() remains a one-shot convenience for existing callers: saveDraft() + confirm()
 * inside one transaction, with the same idempotent-replay behavior it always had.
 *
 * Canonical path is DocumentService::create() / post(), then FiscalYearService::completeOpening().
 */
class OpeningService
{
    private const TOLERANCE = 0.01;

    public function __construct(
        private DocumentService $documentService,
        private FiscalYearService $fiscalYearService,
        private AccountService $accountService
    ) {}

    public function isComplete(FiscalYear|int $fiscalYear): bool
    {
        return (bool) $this->resolveFiscalYear($fiscalYear)->opening_done;
    }

    /**
     * Create or replace the DRAFT opening for one (fiscal year, branch) bucket.
     *
     * - `type=opening`, `status=draft`.
     * - Idempotency key: `opening:{fyId}:branch:{id|none}` (same key confirm() posts in place).
     * - Lines must be permanent (asset/liability/equity) accounts; income/expense are refused.
     * - MAY be unbalanced — balance is enforced by confirm(), not here.
     * - Does not set `opening_done` and does not check operational activity (confirm() does).
     * - A posted opening already existing for this bucket is rejected — void it first.
     * - If a draft already exists for this bucket, its items are replaced in place
     *   (same document id, same idempotency key) and returned.
     *
     * @param  list<array{account_id: int, amount: float, sign: int, description?: ?string}>  $items
     */
    public function saveDraft(FiscalYear|int $target, array $items, ?int $branchId = null): Document
    {
        return DB::transaction(function () use ($target, $items, $branchId) {
            $this->lockAllFiscalYears();
            $target = $this->lockFiscalYear($target);
            $this->assertTargetAcceptsOpening($target);
            $this->lockPostedDocuments($target);

            $normalizedItems = $this->normalizeItems($items);
            $this->assertMinItems($normalizedItems);

            $key = $this->idempotencyKey($target, $branchId);
            $existing = Document::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->isPosted()) {
                    throw new FiscalYearStateException(
                        $target,
                        __('accounting::accounting.messages.opening_bucket_already_posted')
                    );
                }

                if ($existing->type !== 'opening' || $existing->status !== DocumentStatus::DRAFT) {
                    throw new DuplicateIdempotencyKeyException((string) $existing->idempotency_key);
                }

                $this->replaceItems($existing, $normalizedItems);

                return $existing->fresh()->load('items.account');
            }

            return $this->documentService->create([
                'type' => 'opening',
                'date' => $target->start_date->toDateString(),
                'fiscal_year_id' => $target->id,
                'branch_id' => $branchId,
                'status' => DocumentStatus::DRAFT,
                'idempotency_key' => $key,
                'items' => $normalizedItems,
                'balance_required' => false,
            ]);
        });
    }

    /**
     * Post the draft opening for one (fiscal year, branch) bucket in place
     * (`status: draft → posted`, same document id and idempotency key).
     *
     * - Rejects if no draft (or posted-but-mismatched) document exists for the bucket.
     * - Requires balance (`UnbalancedDocumentException` if not, same as any other post()).
     * - Requires no posted operational (non-opening) document in the target year yet.
     * - Already-posted for this bucket: idempotent replay, returns the posted document.
     * - After posting, sets `opening_done` once no draft opening remains for the year
     *   (see maybeCompleteOpening()) — the year need not have only one bucket.
     */
    public function confirm(FiscalYear|int $target, ?int $branchId = null): Document
    {
        return DB::transaction(function () use ($target, $branchId) {
            $this->lockAllFiscalYears();
            $target = $this->lockFiscalYear($target);
            $this->assertTargetAcceptsOpening($target);
            $this->lockPostedDocuments($target);
            $this->lockDraftOpeningDocuments($target);

            $key = $this->idempotencyKey($target, $branchId);
            $document = Document::query()
                ->where('idempotency_key', $key)
                ->where('type', 'opening')
                ->lockForUpdate()
                ->first();

            if ( ! $document) {
                throw new FiscalYearStateException(
                    $target,
                    __('accounting::accounting.messages.opening_no_draft')
                );
            }

            if ($document->isPosted()) {
                $this->maybeCompleteOpening($target);

                return $document->load('items.account');
            }

            if ($document->status !== DocumentStatus::DRAFT) {
                throw new FiscalYearStateException(
                    $target,
                    __('accounting::accounting.messages.opening_no_draft')
                );
            }

            $this->assertNoPostedOperationalDocuments($target);

            $posted = $this->documentService->post($document);

            $this->maybeCompleteOpening($target);

            return $posted;
        });
    }

    /**
     * Draft OR posted opening for one (fiscal year, branch) bucket, or null when neither exists.
     */
    public function find(FiscalYear|int $target, ?int $branchId = null): ?Document
    {
        $target = $this->resolveFiscalYear($target);
        $key = $this->idempotencyKey($target, $branchId);

        return Document::query()
            ->with('items.account')
            ->where('idempotency_key', $key)
            ->where('type', 'opening')
            ->whereIn('status', [DocumentStatus::DRAFT->value, DocumentStatus::POSTED->value])
            ->first();
    }

    /**
     * One-shot convenience: saveDraft($items) + confirm(), inside one transaction.
     * Kept for existing callers (e.g. Matrix's PostOpeningBalanceAction). New callers
     * should prefer saveDraft() + confirm() so the user can review before posting.
     *
     * Idempotent replay is preserved: if a posted opening already matches this bucket,
     * it is returned unchanged (no new document, no re-validation of items).
     *
     * @param  list<array{account_id: int, amount: float, sign: int, description?: ?string}>  $items
     */
    public function post(FiscalYear|int $target, array $items, ?int $branchId = null): Document
    {
        return DB::transaction(function () use ($target, $items, $branchId) {
            $this->lockAllFiscalYears();
            $target = $this->lockFiscalYear($target);
            $this->assertTargetAcceptsOpening($target);
            $this->lockPostedDocuments($target);

            $existing = $this->existingPostedOpening($target, $branchId);
            if ($existing) {
                if ( ! $target->opening_done) {
                    $this->maybeCompleteOpening($target);
                }

                return $existing->load('items.account');
            }

            if ($target->opening_done) {
                throw new FiscalYearStateException(
                    $target,
                    __('accounting::accounting.messages.opening_already_complete')
                );
            }

            $this->assertNoPostedOperationalDocuments($target);

            $this->saveDraft($target, $items, $branchId);

            return $this->confirm($target, $branchId);
        });
    }

    /**
     * Create/refresh DRAFT permanent-balance openings for the next active year, carried
     * forward from a closed source year. Does not post and does not set `opening_done`
     * by itself — call confirm() per bucket (or use post()) to finalize each one.
     *
     * A bucket that already has a *posted* opening matching the recomputed plan is left
     * untouched and returned as-is (repeat-safe / crash-recovery-safe). A mismatched
     * posted opening for a bucket still fails the whole call.
     *
     * @return list<Document> Draft (or already-posted, matching) documents, one per
     *                         non-empty source `documents.branch_id` bucket.
     */
    public function carryForward(FiscalYear|int $source, FiscalYear|int $target): array
    {
        return DB::transaction(function () use ($source, $target) {
            $this->lockAllFiscalYears();
            $source = $this->lockFiscalYear($source);
            $target = $this->lockFiscalYear($target);

            $this->assertSourceClosed($source);
            $this->assertTargetAcceptsOpening($target);
            $this->assertConsecutive($source, $target);

            $this->lockPostedDocuments($source);
            $this->lockPostedDocuments($target);
            $this->lockDraftOpeningDocuments($target);

            $this->assertNoPostedOperationalDocuments($target);

            $plans = $this->plansFromSource($source);
            $expected = array_values(array_filter($plans, fn (array $plan) => $plan['items'] !== []));

            if ($expected === []) {
                $this->assertNoExistingOpenings($target);
                $this->fiscalYearService->completeOpening($target);

                return [];
            }

            $documents = [];
            foreach ($expected as $plan) {
                $documents[] = $this->carryForwardBucket($source, $target, $plan);
            }

            $this->assertNoPostedOperationalDocuments($target);

            if (collect($documents)->every(fn (Document $document) => $document->isPosted())) {
                $this->fiscalYearService->completeOpening($target);
            }

            return $documents;
        });
    }

    /**
     * @param  array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>}  $plan
     */
    private function carryForwardBucket(FiscalYear $source, FiscalYear $target, array $plan): Document
    {
        $key = $this->idempotencyKey($target, $plan['branch_id']);
        $existing = Document::query()
            ->with('items.account')
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing && $existing->isPosted()) {
            if ($existing->type !== 'opening' || ! $this->itemsMatch($plan['items'], $existing)) {
                throw new FiscalYearStateException(
                    $target,
                    __('accounting::accounting.messages.opening_inconsistent_state')
                );
            }

            return $existing;
        }

        $document = $this->saveDraft($target, $plan['items'], $plan['branch_id']);

        $document->forceFill([
            'meta' => [
                'source_fiscal_year_id' => $source->id,
                'operation' => 'carry_forward',
            ],
        ])->save();

        return $document->fresh()->load('items.account');
    }

    private function assertNoExistingOpenings(FiscalYear $fiscalYear): void
    {
        $exists = Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('type', 'opening')
            ->exists();

        if ($exists) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.opening_inconsistent_state')
            );
        }
    }

    /**
     * Set `opening_done` once no `type=opening, status=draft` document remains for the
     * year. Idempotent — safe to call whether or not the flag is already true.
     */
    private function maybeCompleteOpening(FiscalYear $fiscalYear): void
    {
        $hasDraftOpening = Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('type', 'opening')
            ->where('status', DocumentStatus::DRAFT->value)
            ->exists();

        if ( ! $hasDraftOpening) {
            $this->fiscalYearService->completeOpening($fiscalYear);
        }
    }

    private function assertMinItems(array $items): void
    {
        $minItems = (int) config('accounting.document.min_items', 2);

        if (count($items) < $minItems) {
            throw new InvalidArgumentException(__('accounting::accounting.validation.items_required', ['min' => $minItems]));
        }
    }

    /**
     * Replace a draft document's items in place (delete + recreate). Only ever called
     * on a `status=draft` document — DocumentItem's own guards refuse this on posted/voided rows.
     *
     * @param  list<array{account_id: int, amount: float, sign: int, description?: ?string}>  $items
     */
    private function replaceItems(Document $document, array $items): void
    {
        $document->items()->delete();

        foreach ($items as $index => $item) {
            DocumentItem::create([
                'document_id' => $document->id,
                'account_id' => $item['account_id'],
                'amount' => $item['amount'],
                'sign' => $item['sign'],
                'description' => $item['description'] ?? null,
                'order' => $index,
            ]);
        }
    }

    private function assertSourceClosed(FiscalYear $source): void
    {
        if ( ! $source->isClosed()) {
            throw new FiscalYearStateException(
                $source,
                __('accounting::accounting.messages.opening_source_not_closed')
            );
        }
    }

    private function assertTargetAcceptsOpening(FiscalYear $fiscalYear): void
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
    }

    private function assertConsecutive(FiscalYear $source, FiscalYear $target): void
    {
        $expectedStart = Carbon::parse($source->end_date)->addDay()->toDateString();
        $actualStart = Carbon::parse($target->start_date)->toDateString();

        if ($expectedStart !== $actualStart) {
            throw new FiscalYearStateException(
                $target,
                __('accounting::accounting.messages.opening_fiscal_years_not_consecutive')
            );
        }
    }

    private function assertNoPostedOperationalDocuments(FiscalYear $fiscalYear): void
    {
        if ($this->hasPostedOperationalDocuments($fiscalYear)) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.opening_has_posted_activity')
            );
        }
    }

    /**
     * @return list<array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>}>
     */
    private function plansFromSource(FiscalYear $source): array
    {
        $branchIds = Document::query()
            ->where('fiscal_year_id', $source->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->distinct()
            ->pluck('branch_id');

        $plans = [];
        foreach ($branchIds as $rawBranchId) {
            $plans[] = $this->planForBranch($source, $this->normalizeBranchId($rawBranchId));
        }

        return $plans;
    }

    /**
     * @return array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>}
     */
    private function planForBranch(FiscalYear $source, ?int $branchId): array
    {
        $totals = LedgerQuery::make()
            ->forFiscalYear($source)
            ->branch($branchId)
            ->periodTotalsByAccount();

        $accountIds = array_map('intval', array_keys($totals));
        $accounts = $accountIds === []
            ? collect()
            : Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $items = [];
        $residual = 0.0;

        foreach ($totals as $accountId => $row) {
            $signed = round((float) $row['debit'] - (float) $row['credit'], 2);
            if (abs($signed) < self::TOLERANCE) {
                continue;
            }

            $account = $accounts->get((int) $accountId);
            if ( ! $account) {
                throw new FiscalYearStateException(
                    $source,
                    __('accounting::accounting.messages.opening_inconsistent_state')
                );
            }

            if ($account->type->isTemporary()) {
                $residual += $signed;

                continue;
            }

            if ( ! $account->type->isPermanent()) {
                throw new FiscalYearStateException(
                    $source,
                    __('accounting::accounting.messages.opening_permanent_accounts_only')
                );
            }

            $this->accountService->assertPostable($account);

            $items[] = [
                'account_id' => $account->id,
                'amount' => abs($signed),
                'sign' => $signed > 0 ? 1 : -1,
            ];
        }

        if (abs($residual) >= self::TOLERANCE) {
            throw new FiscalYearStateException(
                $source,
                __('accounting::accounting.messages.opening_pnl_residual')
            );
        }

        $net = 0.0;
        foreach ($items as $item) {
            $net += $item['amount'] * $item['sign'];
        }

        if (abs($net) >= self::TOLERANCE) {
            throw new FiscalYearStateException(
                $source,
                __('accounting::accounting.messages.opening_pnl_residual')
            );
        }

        return [
            'branch_id' => $branchId,
            'items' => $items,
        ];
    }

    /**
     * @param  list<array{account_id: int, amount: float, sign: int}>  $expected
     */
    private function itemsMatch(array $expected, Document $document): bool
    {
        if ($document->items->count() !== count($expected)) {
            return false;
        }

        $actual = $document->items
            ->map(fn ($item) => [
                'account_id' => (int) $item->account_id,
                'amount' => round((float) $item->amount, 2),
                'sign' => (int) $item->sign,
            ])
            ->sortBy(fn (array $row) => $row['account_id'].':'.$row['sign'])
            ->values();

        $wanted = collect($expected)
            ->map(fn (array $item) => [
                'account_id' => (int) $item['account_id'],
                'amount' => round((float) $item['amount'], 2),
                'sign' => (int) $item['sign'],
            ])
            ->sortBy(fn (array $row) => $row['account_id'].':'.$row['sign'])
            ->values();

        foreach ($wanted as $index => $item) {
            $row = $actual->get($index);
            if ($row === null
                || $row['account_id'] !== $item['account_id']
                || $row['sign'] !== $item['sign']
                || abs($row['amount'] - $item['amount']) >= self::TOLERANCE
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{account_id: int, amount: float, sign: int, description?: ?string}>  $items
     * @return list<array{account_id: int, amount: float, sign: int, description: ?string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $amount = round((float) ($item['amount'] ?? 0), 2);

            if (abs($amount) < self::TOLERANCE) {
                continue;
            }

            if ($amount <= 0) {
                throw new InvalidArgumentException(__('accounting::accounting.validation.amount_positive'));
            }

            $account = $this->accountService->assertPostable((int) ($item['account_id'] ?? 0));

            if ($account->type->isTemporary()) {
                throw new FiscalYearStateException(
                    null,
                    __('accounting::accounting.messages.opening_permanent_accounts_only')
                );
            }

            $normalized[] = [
                'account_id' => $account->id,
                'amount' => $amount,
                'sign' => (int) ($item['sign'] ?? 1),
                'description' => $item['description'] ?? null,
            ];
        }

        return $normalized;
    }

    private function existingPostedOpening(FiscalYear $fiscalYear, ?int $branchId): ?Document
    {
        $byKey = Document::query()
            ->where('idempotency_key', $this->idempotencyKey($fiscalYear, $branchId))
            ->lockForUpdate()
            ->first();

        if ($byKey) {
            if ($byKey->isPosted() && $byKey->type === 'opening') {
                return $byKey;
            }

            return null;
        }

        $query = Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->where('type', 'opening')
            ->orderBy('id')
            ->lockForUpdate();

        if ($branchId === null) {
            $query->whereNull('branch_id');
        } else {
            $query->where('branch_id', $branchId);
        }

        return $query->first();
    }

    private function hasPostedOperationalDocuments(FiscalYear $fiscalYear): bool
    {
        return Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->where('type', '!=', 'opening')
            ->exists();
    }

    private function idempotencyKey(FiscalYear $fiscalYear, ?int $branchId): string
    {
        return 'opening:'.$fiscalYear->id.':branch:'.($branchId ?? 'none');
    }

    private function normalizeBranchId(mixed $branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        return (int) $branchId;
    }

    private function resolveFiscalYear(FiscalYear|int $fiscalYear): FiscalYear
    {
        return $fiscalYear instanceof FiscalYear
            ? $fiscalYear
            : FiscalYear::query()->findOrFail($fiscalYear);
    }

    private function lockFiscalYear(FiscalYear|int $fiscalYear): FiscalYear
    {
        $id = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;

        return FiscalYear::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockAllFiscalYears(): void
    {
        FiscalYear::query()->orderBy('id')->lockForUpdate()->get();
    }

    private function lockPostedDocuments(FiscalYear $fiscalYear): void
    {
        Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lockDraftOpeningDocuments(FiscalYear $fiscalYear): void
    {
        Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('type', 'opening')
            ->where('status', DocumentStatus::DRAFT->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
