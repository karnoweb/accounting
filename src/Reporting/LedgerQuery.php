<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;

/**
 * Reusable, deterministic query foundation for accounting reports.
 *
 * Always reads from the posted journal (acc_document_items JOIN acc_documents) —
 * never from Account::cached_balance, parent cached balances, or any operational
 * model. Trial Balance, General Ledger, Account Statement and turnover all build
 * on this same object so they agree on filters and ordering.
 *
 * Ordering is always: documents.date, documents.number, documents.id,
 * document_items.order, document_items.id — never created_at.
 *
 * @example
 * LedgerQuery::make()
 *     ->forAccount($account)
 *     ->forFiscalYear($fiscalYear)
 *     ->from($from)
 *     ->to($to)
 *     ->branch($branchId)
 *     ->get();
 */
final class LedgerQuery
{
    /** @var list<int> */
    private array $accountIds = [];

    private ?int $fiscalYearId = null;

    private ?FiscalYear $fiscalYear = null;

    private ?string $from = null;

    private ?string $to = null;

    private ?int $branchId = null;

    private bool $branchFilterApplied = false;

    public static function make(): self
    {
        return new self;
    }

    public function forAccount(Account|int $account): self
    {
        $this->accountIds = [$account instanceof Account ? $account->id : (int) $account];

        return $this;
    }

    /** @param iterable<Account|int> $accounts */
    public function forAccounts(iterable $accounts): self
    {
        $this->accountIds = collect($accounts)
            ->map(fn ($account) => $account instanceof Account ? $account->id : (int) $account)
            ->values()
            ->all();

        return $this;
    }

    public function forFiscalYear(FiscalYear|int|null $fiscalYear): self
    {
        if ($fiscalYear === null) {
            $this->fiscalYear = null;
            $this->fiscalYearId = null;

            return $this;
        }

        if ($fiscalYear instanceof FiscalYear) {
            $this->fiscalYear = $fiscalYear;
            $this->fiscalYearId = $fiscalYear->id;
        } else {
            $this->fiscalYear = null;
            $this->fiscalYearId = $fiscalYear;
        }

        return $this;
    }

    public function from(Carbon|string|null $date): self
    {
        $this->from = $date !== null ? Carbon::parse($date)->toDateString() : null;

        return $this;
    }

    public function to(Carbon|string|null $date): self
    {
        $this->to = $date !== null ? Carbon::parse($date)->toDateString() : null;

        return $this;
    }

    /** Filter by branch. Pass null to explicitly scope to documents without a branch. */
    public function branch(Model|int|null $branch): self
    {
        $this->branchId = $branch instanceof Model ? (int) $branch->getKey() : $branch;
        $this->branchFilterApplied = true;

        return $this;
    }

    /**
     * Documented no-op: the ledger is always posted-only. Voiding a document moves
     * its status away from 'posted', so a single status filter already excludes it.
     */
    public function postedOnly(): self
    {
        return $this;
    }

    /** @see postedOnly() */
    public function excludeVoided(): self
    {
        return $this;
    }

    /** @return list<int> */
    public function accountIds(): array
    {
        return $this->accountIds;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function isBranchFilterApplied(): bool
    {
        return $this->branchFilterApplied;
    }

    public function fiscalYearId(): ?int
    {
        return $this->fiscalYearId;
    }

    public function resolvedFiscalYear(): ?FiscalYear
    {
        if ($this->fiscalYear) {
            return $this->fiscalYear;
        }

        if ($this->fiscalYearId !== null) {
            return $this->fiscalYear = FiscalYear::find($this->fiscalYearId);
        }

        return null;
    }

    /** Explicit `from`, or the scoped fiscal year's start date when omitted. */
    public function resolvedFrom(): ?string
    {
        if ($this->from !== null) {
            return $this->from;
        }

        $fiscalYear = $this->resolvedFiscalYear();

        return $fiscalYear ? Carbon::parse($fiscalYear->start_date)->toDateString() : null;
    }

    /** Explicit `to`, or the scoped fiscal year's end date when omitted. */
    public function resolvedTo(): ?string
    {
        if ($this->to !== null) {
            return $this->to;
        }

        $fiscalYear = $this->resolvedFiscalYear();

        return $fiscalYear ? Carbon::parse($fiscalYear->end_date)->toDateString() : null;
    }

    /**
     * Journal-line query for the requested period: posted items joined to their
     * documents, scoped by account(s), branch, fiscal year and [from, to].
     */
    public function baseQuery(): QueryBuilder
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $query = DB::table($items)
            ->join($documents, "{$documents}.id", '=', "{$items}.document_id")
            ->where("{$documents}.status", DocumentStatus::POSTED->value);

        $this->applyAccountFilter($query, $items);
        $this->applyBranchFilter($query, $documents);

        $from = $this->resolvedFrom();
        $to = $this->resolvedTo();

        // documents.date is a Carbon 'date' cast, which Eloquent still persists with a
        // "00:00:00" time suffix — compare by DATE() rather than raw string/lexical order.
        if ($from !== null) {
            $query->whereDate("{$documents}.date", '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate("{$documents}.date", '<=', $to);
        }

        if ($this->fiscalYearId !== null) {
            $query->where("{$documents}.fiscal_year_id", $this->fiscalYearId);
        }

        return $query;
    }

    /**
     * Balance accumulated strictly before `from`, across ALL fiscal years — a
     * previous, even closed, fiscal year must still contribute to opening.
     */
    private function openingQuery(): QueryBuilder
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        $query = DB::table($items)
            ->join($documents, "{$documents}.id", '=', "{$items}.document_id")
            ->where("{$documents}.status", DocumentStatus::POSTED->value)
            ->whereDate("{$documents}.date", '<', $this->resolvedFrom() ?? '0001-01-01');

        $this->applyAccountFilter($query, $items);
        $this->applyBranchFilter($query, $documents);

        return $query;
    }

    private function applyAccountFilter(QueryBuilder $query, string $items): void
    {
        if ($this->accountIds !== []) {
            $query->whereIn("{$items}.account_id", $this->accountIds);
        }
    }

    private function applyBranchFilter(QueryBuilder $query, string $documents): void
    {
        if (! $this->branchFilterApplied) {
            return;
        }

        if ($this->branchId === null) {
            $query->whereNull("{$documents}.branch_id");
        } else {
            $query->where("{$documents}.branch_id", $this->branchId);
        }
    }

    /**
     * Net opening balance (debit - credit) per account, before `from`.
     *
     * @return array<int, float>
     */
    public function openingBalances(): array
    {
        $balances = array_fill_keys($this->accountIds, 0.0);

        if ($this->resolvedFrom() === null) {
            return $balances;
        }

        $items = (new DocumentItem)->getTable();

        $rows = $this->openingQuery()
            ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit - {$items}.credit), 0) as balance")
            ->groupBy("{$items}.account_id")
            ->get();

        foreach ($rows as $row) {
            $balances[(int) $row->account_id] = (float) $row->balance;
        }

        return $balances;
    }

    /**
     * Debit/credit totals for the [from, to] period, per account.
     *
     * @return array<int, array{debit: float, credit: float}>
     */
    public function periodTotalsByAccount(): array
    {
        $items = (new DocumentItem)->getTable();

        $totals = array_fill_keys($this->accountIds, ['debit' => 0.0, 'credit' => 0.0]);

        $rows = $this->baseQuery()
            ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit")
            ->groupBy("{$items}.account_id")
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->account_id] = ['debit' => (float) $row->debit, 'credit' => (float) $row->credit];
        }

        return $totals;
    }

    /**
     * Debit/credit/balance for the whole scoped period (every matching account combined).
     *
     * @return array{debit: float, credit: float, balance: float}
     */
    public function periodTotals(): array
    {
        $items = (new DocumentItem)->getTable();

        $row = $this->baseQuery()
            ->selectRaw("COALESCE(SUM({$items}.debit), 0) as debit, COALESCE(SUM({$items}.credit), 0) as credit")
            ->first();

        $debit = (float) ($row->debit ?? 0);
        $credit = (float) ($row->credit ?? 0);

        return ['debit' => $debit, 'credit' => $credit, 'balance' => $debit - $credit];
    }

    /**
     * Per-account opening/period debit & credit — the Trial Balance building block.
     * Only L3 (posting-level) accounts ever appear here, because only they can
     * carry document_items under the package's detail-only posting invariant.
     *
     * @return array<int, array{opening_debit: float, opening_credit: float, period_debit: float, period_credit: float}>
     */
    public function trialBalanceAggregates(): array
    {
        $result = [];

        if ($this->resolvedFrom() !== null) {
            $items = (new DocumentItem)->getTable();

            $openingRows = $this->openingQuery()
                ->selectRaw("{$items}.account_id as account_id, COALESCE(SUM({$items}.debit), 0) as opening_debit, COALESCE(SUM({$items}.credit), 0) as opening_credit")
                ->groupBy("{$items}.account_id")
                ->get();

            foreach ($openingRows as $row) {
                $result[(int) $row->account_id]['opening_debit'] = (float) $row->opening_debit;
                $result[(int) $row->account_id]['opening_credit'] = (float) $row->opening_credit;
            }
        }

        foreach ($this->periodTotalsByAccount() as $accountId => $totals) {
            $result[$accountId]['period_debit'] = $totals['debit'];
            $result[$accountId]['period_credit'] = $totals['credit'];
        }

        foreach ($result as $accountId => $row) {
            $result[$accountId] = [
                'opening_debit' => $row['opening_debit'] ?? 0.0,
                'opening_credit' => $row['opening_credit'] ?? 0.0,
                'period_debit' => $row['period_debit'] ?? 0.0,
                'period_credit' => $row['period_credit'] ?? 0.0,
            ];
        }

        return $result;
    }

    /**
     * Deterministically ordered journal lines for the scoped period.
     *
     * @return Collection<int, LedgerLine>
     */
    public function get(): Collection
    {
        return collect($this->orderedDetailQuery()->get())
            ->map(fn ($row) => LedgerLine::fromRow($row));
    }

    /**
     * Lazily stream journal lines without materializing the whole result set —
     * use for wide date ranges / whole-fiscal-year General Ledger scans.
     *
     * @return LazyCollection<int, LedgerLine>
     */
    public function cursor(): LazyCollection
    {
        return LazyCollection::make(function () {
            foreach ($this->orderedDetailQuery()->cursor() as $row) {
                yield LedgerLine::fromRow($row);
            }
        });
    }

    private function orderedDetailQuery(): QueryBuilder
    {
        $items = (new DocumentItem)->getTable();
        $documents = (new Document)->getTable();

        return $this->baseQuery()
            ->select([
                "{$items}.id as item_id",
                "{$items}.document_id",
                "{$items}.account_id",
                "{$items}.debit",
                "{$items}.credit",
                "{$items}.order as item_order",
                "{$documents}.number as document_number",
                "{$documents}.type as document_type",
                "{$documents}.description as document_description",
                "{$documents}.reference",
                "{$documents}.source_type",
                "{$documents}.source_id",
                "{$documents}.date",
                "{$documents}.fiscal_year_id",
                "{$documents}.branch_id",
            ])
            ->orderBy("{$documents}.date")
            ->orderBy("{$documents}.number")
            ->orderBy("{$documents}.id")
            ->orderBy("{$items}.order")
            ->orderBy("{$items}.id");
    }
}
