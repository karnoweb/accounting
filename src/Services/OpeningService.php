<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;

/**
 * Opening journals: manual post() or carryForward() from a closed source year.
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
     * Post a balanced permanent-account opening for one branch (null is its own bucket).
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
                    $this->fiscalYearService->completeOpening($target);
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

            $document = $this->documentService->create([
                'type' => 'opening',
                'date' => $target->start_date->toDateString(),
                'fiscal_year_id' => $target->id,
                'branch_id' => $branchId,
                'idempotency_key' => $this->idempotencyKey($target, $branchId),
                'items' => $this->normalizeItems($items),
            ]);

            $posted = $this->documentService->post($document);
            $this->fiscalYearService->completeOpening($target);

            return $posted;
        });
    }

    /**
     * Carry posted permanent balances from a closed source year into the next active year.
     *
     * @return list<Document>
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

            $plans = $this->plansFromSource($source);
            $expected = array_values(array_filter($plans, fn (array $plan) => $plan['items'] !== []));

            $this->assertNoPostedOperationalDocuments($target);

            $existing = $this->matchingPostedOpenings($target, $expected);
            if ($existing !== null) {
                if ( ! $target->opening_done) {
                    $this->fiscalYearService->completeOpening($target);
                }

                return $existing;
            }

            if ($target->opening_done) {
                throw new FiscalYearStateException(
                    $target,
                    __('accounting::accounting.messages.opening_inconsistent_state')
                );
            }

            if ($this->postedOpeningDocuments($target)->isNotEmpty()) {
                throw new FiscalYearStateException(
                    $target,
                    __('accounting::accounting.messages.opening_inconsistent_state')
                );
            }

            $posted = [];
            foreach ($expected as $plan) {
                $posted[] = $this->postCarryForwardDocument($source, $target, $plan);
            }

            $this->assertNoPostedOperationalDocuments($target);
            $this->fiscalYearService->completeOpening($target);

            return $posted;
        });
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
     * @param  array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>}  $plan
     */
    private function postCarryForwardDocument(FiscalYear $source, FiscalYear $target, array $plan): Document
    {
        $this->assertKeyAvailableForCreate($target, $plan['branch_id']);

        $document = $this->documentService->create([
            'type' => 'opening',
            'date' => $target->start_date->toDateString(),
            'fiscal_year_id' => $target->id,
            'branch_id' => $plan['branch_id'],
            'idempotency_key' => $this->idempotencyKey($target, $plan['branch_id']),
            'meta' => [
                'source_fiscal_year_id' => $source->id,
                'operation' => 'carry_forward',
            ],
            'items' => $plan['items'],
        ]);

        $posted = $this->documentService->post($document);
        $this->assertPostedOpeningMatchesPlan($posted, $plan);

        return $posted;
    }

    /**
     * @param  array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>}  $plan
     */
    private function assertPostedOpeningMatchesPlan(Document $document, array $plan): void
    {
        if ( ! $document->isPosted() || $document->type !== 'opening') {
            throw new FiscalYearStateException(
                null,
                __('accounting::accounting.messages.opening_inconsistent_state')
            );
        }

        $signed = (float) $document->items->sum(fn ($item) => (float) $item->amount * (int) $item->sign);
        if (abs($signed) >= self::TOLERANCE) {
            throw new UnbalancedDocumentException(
                (float) $document->items->where('sign', 1)->sum('amount'),
                (float) $document->items->where('sign', -1)->sum('amount')
            );
        }

        if ( ! $this->itemsMatch($plan['items'], $document)) {
            throw new FiscalYearStateException(
                null,
                __('accounting::accounting.messages.opening_inconsistent_state')
            );
        }
    }

    /**
     * @param  list<array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>}>  $expected
     * @return list<Document>|null
     */
    private function matchingPostedOpenings(FiscalYear $target, array $expected): ?array
    {
        $posted = $this->postedOpeningDocuments($target);

        if ($expected === [] && $posted->isEmpty()) {
            return [];
        }

        if ($posted->count() !== count($expected)) {
            return null;
        }

        $byKey = $posted->keyBy('idempotency_key');

        foreach ($expected as $plan) {
            $key = $this->idempotencyKey($target, $plan['branch_id']);
            $document = $byKey->get($key);
            if ( ! $document instanceof Document) {
                return null;
            }

            $document->loadMissing('items.account');

            if ($this->normalizeBranchId($document->branch_id) !== $plan['branch_id']) {
                return null;
            }

            if ( ! $this->itemsMatch($plan['items'], $document)) {
                return null;
            }
        }

        return $posted->values()->all();
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

    private function assertKeyAvailableForCreate(FiscalYear $target, ?int $branchId): void
    {
        $existing = Document::query()
            ->where('idempotency_key', $this->idempotencyKey($target, $branchId))
            ->lockForUpdate()
            ->first();

        if ($existing) {
            throw new DuplicateIdempotencyKeyException((string) $existing->idempotency_key);
        }
    }

    /**
     * @return Collection<int, Document>
     */
    private function postedOpeningDocuments(FiscalYear $fiscalYear): Collection
    {
        return Document::query()
            ->with('items.account')
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->where('type', 'opening')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
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

            throw new DuplicateIdempotencyKeyException((string) $byKey->idempotency_key);
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
}
