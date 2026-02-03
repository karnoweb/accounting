<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Service for account balances: current balance, balance as-of date, debit/credit totals, turnover, and cache refresh.
 */
class BalanceService
{
    /** Get account balance for a fiscal year. Uses cache when valid unless forceRealtime is true. */
    public function getBalance(Account|int $account, ?FiscalYear $fiscalYear = null, bool $forceRealtime = false): float
    {
        $account = $this->resolveAccount($account);

        if ( ! $forceRealtime && $this->isCacheValid($account)) {
            return (float) $account->cached_balance;
        }

        return $this->calculateRealtime($account, $fiscalYear);
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

    /** Get account balance as of a given date (posted items with date <= date). */
    public function getBalanceAsOf(Account|int $account, Carbon|string $date, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);
        $date = Carbon::parse($date);

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
     * @return array{debit: float, credit: float, balance: float}
     */
    public function getTurnover(Account|int $account, Carbon|string $fromDate, Carbon|string $toDate): array
    {
        $account = $this->resolveAccount($account);
        $fromDate = Carbon::parse($fromDate);
        $toDate = Carbon::parse($toDate);

        $result = DocumentItem::query()
            ->where('account_id', $account->id)
            ->whereHas('document', function ($q) use ($fromDate, $toDate) {
                $q->where('status', 'posted')
                    ->whereBetween('date', [$fromDate, $toDate]);
            })
            ->selectRaw('
                COALESCE(SUM(CASE WHEN sign = 1 THEN amount ELSE 0 END), 0) as debit,
                COALESCE(SUM(CASE WHEN sign = -1 THEN amount ELSE 0 END), 0) as credit
            ')
            ->first();

        return [
            'debit' => (float) ($result->debit ?? 0),
            'credit' => (float) ($result->credit ?? 0),
            'balance' => (float) (($result->debit ?? 0) - ($result->credit ?? 0)),
        ];
    }

    public function refreshCache(Account|int $account): float
    {
        $account = $this->resolveAccount($account);
        $balance = $this->calculateRealtime($account);

        $account->update([
            'cached_balance' => $balance,
            'balance_updated_at' => now(),
        ]);

        return $balance;
    }

    /** Update cached balances for all accounts affected by a posted document (and optionally parent chain). */
    public function updateAfterDocument(Document $document): void
    {
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

            if (config('accounting.balance.update_parents', true)) {
                $this->updateParentChain($account, (float) $delta);
            }
        }
    }

    /** Reverse cached balance deltas for all accounts affected by a document (e.g. when voiding). */
    public function reverseDocument(Document $document): void
    {
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

            if (config('accounting.balance.update_parents', true)) {
                $this->updateParentChain($account, -(float) $delta);
            }
        }
    }

    private function updateParentChain(Account $account, float $delta): void
    {
        $parent = $account->parent;

        while ($parent) {
            Account::where('id', $parent->id)->update([
                'cached_balance' => DB::raw("cached_balance + {$delta}"),
                'balance_updated_at' => now(),
            ]);

            $parent = $parent->parent;
        }
    }

    private function isCacheValid(Account $account): bool
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
