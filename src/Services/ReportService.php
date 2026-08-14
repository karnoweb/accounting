<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\AccountLedger;
use Karnoweb\Accounting\Reporting\GeneralLedgerReport;
use Karnoweb\Accounting\Reporting\HierarchyRollup;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Reporting\TrialBalanceReport;
use Karnoweb\Accounting\Support\AccountHierarchy;

/**
 * Service for accounting reports (trial balance, general ledger, account statement).
 *
 * Every report reads posted journal lines (acc_document_items JOIN acc_documents),
 * never Account::cached_balance — see Karnoweb\Accounting\Reporting\LedgerQuery.
 */
class ReportService
{
    public function __construct(
        private BalanceService $balanceService,
        private AccountService $accountService
    ) {}

    /**
     * Trial balance: list of posting-level accounts with non-zero FY balance, debit and credit columns.
     *
     * @deprecated 13.2.0 Not a real Trial Balance: no opening/period split, no L0-L2
     *             rollup, no ending columns, and zero-balance accounts are dropped.
     *             Use trialBalanceDetailed() instead. Kept unchanged for backward compatibility.
     * @return array<int, array{account: \Karnoweb\Accounting\Models\Account, debit: float, credit: float}>
     */
    public function trialBalance(?FiscalYear $fiscalYear = null): array
    {
        $fiscalYear ??= FiscalYear::current();

        if (! $fiscalYear) {
            return [];
        }

        $accounts = $this->accountService->search([
            'is_active' => true,
            'level' => AccountHierarchy::postingLevel(),
        ]);

        $rows = [];
        foreach ($accounts as $account) {
            $balance = $this->balanceService->getBalance($account, $fiscalYear);
            if (abs($balance) >= 0.01) {
                $rows[] = [
                    'account' => $account,
                    'debit' => $balance > 0 ? $balance : 0,
                    'credit' => $balance < 0 ? abs($balance) : 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * Real Trial Balance built from posted journal lines — opening/period/ending
     * debit & credit for every account, L0 (Group) through L3 (Detail). L0-L2 rows
     * are rolled up from their L3 descendants; nothing is read from cached_balance.
     *
     * @example
     * Accounting::report()->trialBalanceDetailed($fiscalYear);
     * Accounting::report()->trialBalanceDetailed(
     *     LedgerQuery::make()->from('2025-01-01')->to('2025-06-30')->branch($branchId)
     * );
     */
    public function trialBalanceDetailed(LedgerQuery|FiscalYear|null $criteria = null): TrialBalanceReport
    {
        $query = match (true) {
            $criteria instanceof LedgerQuery => $criteria,
            $criteria instanceof FiscalYear => LedgerQuery::make()->forFiscalYear($criteria),
            default => LedgerQuery::make()->forFiscalYear(FiscalYear::current()),
        };

        $rows = HierarchyRollup::build($query->trialBalanceAggregates());

        return new TrialBalanceReport($rows, $query->resolvedFrom(), $query->resolvedTo());
    }

    /**
     * General Ledger: opening -> journal lines -> running balance -> closing, for
     * every account matched by $query (or every posting-level account when the
     * query has no account filter).
     *
     * @example
     * Accounting::report()->generalLedger(
     *     LedgerQuery::make()->forFiscalYear($fiscalYear)->branch($branchId)
     * );
     */
    public function generalLedger(LedgerQuery $query): GeneralLedgerReport
    {
        return new GeneralLedgerReport($this->buildAccountLedgers($query));
    }

    /**
     * Single-account projection over the same ledger foundation as generalLedger().
     * $query must be scoped to exactly one account via LedgerQuery::forAccount().
     *
     * @example
     * Accounting::report()->accountStatement(
     *     LedgerQuery::make()->forAccount($account)->from($from)->to($to)
     * );
     */
    public function accountStatement(LedgerQuery $query): AccountLedger
    {
        $accountIds = $query->accountIds();

        if (count($accountIds) !== 1) {
            throw new InvalidArgumentException(
                'accountStatement() requires a LedgerQuery scoped to exactly one account via forAccount().'
            );
        }

        return $this->buildAccountLedgers($query)->get($accountIds[0])
            ?? new AccountLedger($accountIds[0], 0.0, collect(), 0.0);
    }

    /** @return Collection<int, AccountLedger> keyed by account_id */
    private function buildAccountLedgers(LedgerQuery $query): Collection
    {
        $accountIds = $query->accountIds();

        if ($accountIds === []) {
            $accountIds = $this->accountService->search(['level' => AccountHierarchy::postingLevel()])
                ->pluck('id')
                ->all();
            $query = (clone $query)->forAccounts($accountIds);
        }

        $runningBalances = $openings = $query->openingBalances();
        $lines = collect($accountIds)->mapWithKeys(fn (int $id) => [$id => collect()]);

        foreach ($query->cursor() as $line) {
            $line->runningBalance = ($runningBalances[$line->accountId] ?? 0.0) + $line->signedAmount();
            $runningBalances[$line->accountId] = $line->runningBalance;
            $lines[$line->accountId]->push($line);
        }

        return $lines->map(fn (Collection $accountLines, int $accountId) => new AccountLedger(
            accountId: $accountId,
            openingBalance: $openings[$accountId] ?? 0.0,
            lines: $accountLines,
            closingBalance: $runningBalances[$accountId] ?? ($openings[$accountId] ?? 0.0),
        ));
    }
}
