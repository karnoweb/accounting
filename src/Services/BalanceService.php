<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;

/**
 * Service for account balances: current balance, balance as-of date, debit/credit totals, turnover, and cache refresh.
 */
class BalanceService
{
    /**
     * Get account balance for a fiscal year.
     *
     * - No fiscal year: uses account.cached_balance when valid (lifetime posted total).
     * - With fiscal year: never returns another FY's cache; uses FY-scoped cache keys.
     */
    public function getBalance(Account|int $account, ?FiscalYear $fiscalYear = null, bool $forceRealtime = false): float
    {
        $account = $this->resolveAccount($account);

        if ($fiscalYear !== null) {
            if ($forceRealtime || ! config('accounting.balance.cache_enabled', true)) {
                return $this->calculateRealtime($account, $fiscalYear);
            }

            $key = $this->fiscalYearCacheKey($account, $fiscalYear);
            $ttl = (int) config('accounting.balance.cache_ttl', 3600);

            return (float) Cache::remember($key, $ttl, fn () => $this->calculateRealtime($account, $fiscalYear));
        }

        if ( ! $forceRealtime && $this->isLifetimeCacheValid($account)) {
            return (float) $account->cached_balance;
        }

        return $this->calculateRealtime($account, null);
    }

    /** Calculate balance from posted document items (ignores cache). */
    public function calculateRealtime(Account|int $account, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);

        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->selectRaw('COALESCE(SUM(amount * sign), 0) as balance')->value('balance');
    }

    /** Get account balance as of a given date (posted items with date <= date). Never uses lifetime cache. */
    public function getBalanceAsOf(Account|int $account, Carbon|string $date, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);
        $date = Carbon::parse($date);

        if ( ! config('accounting.balance.cache_enabled', true)) {
            return $this->calculateBalanceAsOf($account, $date, $fiscalYear);
        }

        $key = $this->asOfCacheKey($account, $date, $fiscalYear);
        $ttl = (int) config('accounting.balance.cache_ttl', 3600);

        return (float) Cache::remember(
            $key,
            $ttl,
            fn () => $this->calculateBalanceAsOf($account, $date, $fiscalYear)
        );
    }

    /** Sum of debit amounts (sign = 1) for the account in the fiscal year. */
    public function getDebitTotal(Account|int $account, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);

        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->where('sign', 1)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->sum('amount');
    }

    /** Sum of credit amounts (sign = -1) for the account in the fiscal year. */
    public function getCreditTotal(Account|int $account, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);

        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->where('sign', -1)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->sum('amount');
    }

    /**
     * Get debit, credit and balance (debit - credit) for the account in a date range.
     *
     * Backward compatible: the 3-argument call reproduces the exact 13.1.0 query.
     * Pass $options to additionally scope by fiscal year and/or branch:
     *
     * @param array{fiscal_year?: FiscalYear|int|null, branch_id?: int|null} $options
     *        'fiscal_year' restricts to that fiscal year's documents.
     *        'branch_id' restricts to that branch (explicit null = documents without a branch).
     * @return array{debit: float, credit: float, balance: float}
     */
    public function getTurnover(Account|int $account, Carbon|string $fromDate, Carbon|string $toDate, array $options = []): array
    {
        $account = $this->resolveAccount($account);

        $query = LedgerQuery::make()
            ->forAccount($account)
            ->from($fromDate)
            ->to($toDate);

        if (array_key_exists('fiscal_year', $options)) {
            $query->forFiscalYear($options['fiscal_year']);
        }

        if (array_key_exists('branch_id', $options)) {
            $query->branch($options['branch_id']);
        }

        return $query->periodTotals();
    }

    public function refreshCache(Account|int $account, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);
        $balance = $this->calculateRealtime($account, $fiscalYear);

        if ($fiscalYear === null) {
            $account->update([
                'cached_balance' => $balance,
                'balance_updated_at' => now(),
            ]);
        } else {
            Cache::put(
                $this->fiscalYearCacheKey($account, $fiscalYear),
                $balance,
                (int) config('accounting.balance.cache_ttl', 3600)
            );
        }

        return $balance;
    }

    /** Update cached balances for all accounts affected by a posted document (and optionally parent chain). */
    public function updateAfterDocument(Document $document): void
    {
        $document->loadMissing(['items', 'fiscalYear']);
        $affectedAccountIds = $document->items->pluck('account_id')->unique();

        foreach ($affectedAccountIds as $accountId) {
            $account = Account::find($accountId);
            if ( ! $account) {
                continue;
            }

            $delta = $document->items
                ->where('account_id', $accountId)
                ->sum(fn ($item) => $item->amount * $item->sign);

            Account::where('id', $accountId)->update([
                'cached_balance' => DB::raw("cached_balance + {$delta}"),
                'balance_updated_at' => now(),
            ]);

            $this->forgetAccountCaches($account, $document->fiscalYear);

            if (config('accounting.balance.update_parents', true)) {
                $this->updateParentChain($account, (float) $delta, $document->fiscalYear);
            }
        }
    }

    /** Reverse cached balance deltas for all accounts affected by a document (e.g. when voiding). */
    public function reverseDocument(Document $document): void
    {
        $document->loadMissing(['items', 'fiscalYear']);
        $affectedAccountIds = $document->items->pluck('account_id')->unique();

        foreach ($affectedAccountIds as $accountId) {
            $account = Account::find($accountId);
            if ( ! $account) {
                continue;
            }

            $delta = $document->items
                ->where('account_id', $accountId)
                ->sum(fn ($item) => $item->amount * $item->sign);

            Account::where('id', $accountId)->update([
                'cached_balance' => DB::raw("cached_balance - {$delta}"),
                'balance_updated_at' => now(),
            ]);

            $this->forgetAccountCaches($account, $document->fiscalYear);

            if (config('accounting.balance.update_parents', true)) {
                $this->updateParentChain($account, -(float) $delta, $document->fiscalYear);
            }
        }
    }

    private function calculateBalanceAsOf(Account $account, Carbon $date, ?FiscalYear $fiscalYear): float
    {
        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->whereHas('document', function ($q) use ($date, $fiscalYear) {
                $q->where('status', 'posted')
                    ->where('date', '<=', $date);
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->selectRaw('COALESCE(SUM(amount * sign), 0) as balance')->value('balance');
    }

    private function updateParentChain(Account $account, float $delta, ?FiscalYear $fiscalYear = null): void
    {
        $parent = $account->parent;

        while ($parent) {
            Account::where('id', $parent->id)->update([
                'cached_balance' => DB::raw("cached_balance + {$delta}"),
                'balance_updated_at' => now(),
            ]);

            $this->forgetAccountCaches($parent, $fiscalYear);
            $parent = $parent->parent;
        }
    }

    private function forgetAccountCaches(Account $account, ?FiscalYear $fiscalYear): void
    {
        if ($fiscalYear) {
            Cache::forget($this->fiscalYearCacheKey($account, $fiscalYear));
        }

        // As-of keys include dates; flush by tag when available, else rely on TTL.
        // ponytail: no cache tags required; FY keys + lifetime column cover posting paths
    }

    public function fiscalYearCacheKey(Account $account, FiscalYear $fiscalYear): string
    {
        return sprintf(
            'accounting:balance:account:%d:fy:%d:branch:%s',
            $account->id,
            $fiscalYear->id,
            $account->branch_id ?? 'none'
        );
    }

    private function asOfCacheKey(Account $account, Carbon $date, ?FiscalYear $fiscalYear): string
    {
        return sprintf(
            'accounting:balance:asof:account:%d:date:%s:fy:%s:branch:%s',
            $account->id,
            $date->toDateString(),
            $fiscalYear?->id ?? 'all',
            $account->branch_id ?? 'none'
        );
    }

    private function isLifetimeCacheValid(Account $account): bool
    {
        if ( ! config('accounting.balance.cache_enabled', true)) {
            return false;
        }

        if ( ! $account->balance_updated_at) {
            return false;
        }

        $ttl = config('accounting.balance.cache_ttl', 3600);

        return $account->balance_updated_at->diffInSeconds(now()) < $ttl;
    }

    private function resolveAccount(Account|int $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        return Account::findOrFail($account);
    }
}
