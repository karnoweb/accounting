<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;

/**
 * P&L close: zero temporary accounts into retained earnings while the year is still active.
 *
 * Canonical path is DocumentService::create() / post(). Does not call FiscalYearService::close().
 */
class ClosingService
{
    private const TOLERANCE = 0.01;

    public function __construct(
        private DocumentService $documentService,
        private FiscalYearService $fiscalYearService,
        private AccountService $accountService
    ) {}

    public function isProfitAndLossClosed(FiscalYear|int $fiscalYear): bool
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        foreach ($this->postedBranchIds($fiscalYear) as $branchId) {
            if (abs($this->temporaryResidualForBranch($fiscalYear, $branchId)) >= self::TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Post one type=closing document per branch bucket that still has material temporaries.
     *
     * @return list<Document>
     */
    public function closeProfitAndLoss(FiscalYear|int $fiscalYear): array
    {
        return DB::transaction(function () use ($fiscalYear) {
            $this->lockAllFiscalYears();
            $fiscalYear = $this->lockFiscalYear($fiscalYear);
            $this->lockPostedDocuments($fiscalYear);
            $this->assertActive($fiscalYear);

            $retainedEarnings = $this->resolveRetainedEarnings($fiscalYear);
            $existingClosings = $this->postedClosingDocuments($fiscalYear);
            $plans = $this->plansFromSource($fiscalYear, $retainedEarnings, $existingClosings);
            $expected = array_values(array_filter($plans, fn (array $plan) => $plan['items'] !== []));

            $existing = $this->matchingPostedClosings($fiscalYear, $expected);
            if ($existing !== null) {
                return $existing;
            }

            if ($this->postedClosingDocuments($fiscalYear)->isNotEmpty()) {
                throw new FiscalYearStateException(
                    $fiscalYear,
                    __('accounting::accounting.messages.closing_inconsistent_state')
                );
            }

            $posted = [];
            foreach ($expected as $plan) {
                $posted[] = $this->postClosingDocument($fiscalYear, $plan);
            }

            return $posted;
        });
    }

    private function assertActive(FiscalYear $fiscalYear): void
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

    private function resolveRetainedEarnings(FiscalYear $fiscalYear): Account
    {
        $code = config('accounting.account.system_accounts.retained_earnings');
        if ( ! is_string($code) || $code === '') {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.closing_retained_earnings_missing')
            );
        }

        $account = $this->accountService->findByCode($code);
        if ( ! $account) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.closing_retained_earnings_missing')
            );
        }

        if ( ! $account->type->isPermanent() || $account->type->value !== 'equity') {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.closing_retained_earnings_invalid')
            );
        }

        try {
            return $this->accountService->assertPostable($account);
        } catch (\Throwable $e) {
            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.closing_retained_earnings_invalid'),
                previous: $e
            );
        }
    }

    /**
     * @param  Collection<int, Document>  $existingClosings
     * @return list<array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>, residual: float}>
     */
    private function plansFromSource(FiscalYear $fiscalYear, Account $retainedEarnings, Collection $existingClosings): array
    {
        $plans = [];
        foreach ($this->postedBranchIds($fiscalYear) as $branchId) {
            $plans[] = $this->planForBranch($fiscalYear, $branchId, $retainedEarnings, $existingClosings);
        }

        return $plans;
    }

    /**
     * @return list<?int>
     */
    private function postedBranchIds(FiscalYear $fiscalYear): array
    {
        return Document::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->distinct()
            ->pluck('branch_id')
            ->map(fn (mixed $branchId) => $this->normalizeBranchId($branchId))
            ->all();
    }

    /**
     * @param  Collection<int, Document>|null  $existingClosings
     * @return array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>, residual: float}
     */
    private function planForBranch(
        FiscalYear $fiscalYear,
        ?int $branchId,
        ?Account $retainedEarnings,
        ?Collection $existingClosings = null
    ): array {
        $totals = LedgerQuery::make()
            ->forFiscalYear($fiscalYear)
            ->branch($branchId)
            ->periodTotalsByAccount();

        $this->subtractClosingEffects($totals, $existingClosings, $branchId);

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
                    $fiscalYear,
                    __('accounting::accounting.messages.closing_inconsistent_state')
                );
            }

            if ($account->type->isTemporary()) {
                if ( ! $account->isPostable() || ( ! $account->is_active && config('accounting.validation.check_account_active', true))) {
                    throw new FiscalYearStateException(
                        $fiscalYear,
                        __('accounting::accounting.messages.closing_non_postable_temporary')
                    );
                }

                $items[] = [
                    'account_id' => $account->id,
                    'amount' => abs($signed),
                    'sign' => $signed > 0 ? -1 : 1,
                ];
                $residual += $signed;

                continue;
            }

            if ($account->type->isPermanent()) {
                continue;
            }

            throw new FiscalYearStateException(
                $fiscalYear,
                __('accounting::accounting.messages.closing_inconsistent_state')
            );
        }

        $residual = round($residual, 2);
        if ($retainedEarnings && abs($residual) >= self::TOLERANCE) {
            $items[] = [
                'account_id' => $retainedEarnings->id,
                'amount' => abs($residual),
                'sign' => $residual > 0 ? 1 : -1,
            ];
        }

        return [
            'branch_id' => $branchId,
            'items' => $items,
            'residual' => $residual,
        ];
    }

    /**
     * Remove posted closing lines so a repeat close sees the pre-close temporary balances.
     *
     * @param  array<int, array{debit: float, credit: float}>  $totals
     * @param  Collection<int, Document>|null  $existingClosings
     */
    private function subtractClosingEffects(array &$totals, ?Collection $existingClosings, ?int $branchId): void
    {
        if ($existingClosings === null) {
            return;
        }

        foreach ($existingClosings as $document) {
            if ($this->normalizeBranchId($document->branch_id) !== $branchId) {
                continue;
            }

            foreach ($document->items as $item) {
                $accountId = (int) $item->account_id;
                $totals[$accountId] ??= ['debit' => 0.0, 'credit' => 0.0];
                if ((int) $item->sign === 1) {
                    $totals[$accountId]['debit'] -= (float) $item->amount;
                } else {
                    $totals[$accountId]['credit'] -= (float) $item->amount;
                }
            }
        }
    }

    /**
     * @param  array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>, residual?: float}  $plan
     */
    private function postClosingDocument(FiscalYear $fiscalYear, array $plan): Document
    {
        $this->assertKeyAvailableForCreate($fiscalYear, $plan['branch_id']);

        $document = $this->documentService->create([
            'type' => 'closing',
            'date' => $fiscalYear->end_date->toDateString(),
            'fiscal_year_id' => $fiscalYear->id,
            'branch_id' => $plan['branch_id'],
            'idempotency_key' => $this->idempotencyKey($fiscalYear, $plan['branch_id']),
            'meta' => [
                'operation' => 'close_pnl',
                'fiscal_year_id' => $fiscalYear->id,
            ],
            'items' => $plan['items'],
        ]);

        return $this->documentService->post($document);
    }

    /**
     * @param  list<array{branch_id: ?int, items: list<array{account_id: int, amount: float, sign: int}>, residual?: float}>  $expected
     * @return list<Document>|null
     */
    private function matchingPostedClosings(FiscalYear $fiscalYear, array $expected): ?array
    {
        $posted = $this->postedClosingDocuments($fiscalYear);

        if ($expected === [] && $posted->isEmpty()) {
            return [];
        }

        if ($posted->count() !== count($expected)) {
            return null;
        }

        $byKey = $posted->keyBy('idempotency_key');

        foreach ($expected as $plan) {
            $key = $this->idempotencyKey($fiscalYear, $plan['branch_id']);
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

    private function assertKeyAvailableForCreate(FiscalYear $fiscalYear, ?int $branchId): void
    {
        $existing = Document::query()
            ->where('idempotency_key', $this->idempotencyKey($fiscalYear, $branchId))
            ->lockForUpdate()
            ->first();

        if ($existing) {
            throw new DuplicateIdempotencyKeyException((string) $existing->idempotency_key);
        }
    }

    /**
     * @return Collection<int, Document>
     */
    private function postedClosingDocuments(FiscalYear $fiscalYear): Collection
    {
        return Document::query()
            ->with('items.account')
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->where('type', 'closing')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function temporaryResidualForBranch(FiscalYear $fiscalYear, ?int $branchId): float
    {
        $totals = LedgerQuery::make()
            ->forFiscalYear($fiscalYear)
            ->branch($branchId)
            ->periodTotalsByAccount();

        $accountIds = array_map('intval', array_keys($totals));
        $accounts = $accountIds === []
            ? collect()
            : Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $residual = 0.0;
        foreach ($totals as $accountId => $row) {
            $signed = round((float) $row['debit'] - (float) $row['credit'], 2);
            if (abs($signed) < self::TOLERANCE) {
                continue;
            }

            $account = $accounts->get((int) $accountId);
            if ($account && $account->type->isTemporary()) {
                $residual += $signed;
            }
        }

        return round($residual, 2);
    }

    private function idempotencyKey(FiscalYear $fiscalYear, ?int $branchId): string
    {
        return 'closing:'.$fiscalYear->id.':branch:'.($branchId ?? 'none');
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
