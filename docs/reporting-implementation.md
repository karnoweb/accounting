# Reporting Implementation Audit & Design — v13.2.0

Phase 0 deliverable for the **Accounting Reporting Foundation**. Written before any
reporting code was changed. Package: `karnoweb/laravel-accounting`, standalone
Composer package (no Karno Base dependency, none added).

---

## 1. Existing reporting APIs (before 13.2.0)

Only one reporting API exists: `Karnoweb\Accounting\Services\ReportService::trialBalance(?FiscalYear $fiscalYear = null)`.

```php
public function trialBalance(?FiscalYear $fiscalYear = null): array
```

Behavior:

- Defaults to `FiscalYear::current()`; returns `[]` if there is no active FY.
- Enumerates **posting-level** (`AccountHierarchy::postingLevel()`), **active** accounts via `AccountService::search()`.
- Reads each account's balance via `BalanceService::getBalance($account, $fiscalYear)` — this itself is journal-derived when a fiscal year is given (see §2), not `cached_balance`.
- Drops rows with `abs(balance) < 0.01`.
- Returns `[['account' => Account, 'debit' => float, 'credit' => float], ...]`, splitting a signed balance into debit/credit columns.

**This is not a real Trial Balance**: no opening/period split, no L0–L2 rollup, no ending debit/credit columns, zero-balance accounts are silently hidden, and it only covers a single fiscal year (no arbitrary date range, no branch filter). It does not reconcile against the accounting identities required by a proper TB.

## 2. Existing balance APIs

All in `Karnoweb\Accounting\Services\BalanceService`:

| Method | Cache behavior | Notes |
|---|---|---|
| `getBalance(Account\|int, ?FiscalYear, bool $forceRealtime = false)` | FY given → FY-scoped cache key (`Cache::remember`). No FY → lifetime `cached_balance` column if fresh (TTL), else realtime. | Journal-derived when realtime; never mixes FY caches. |
| `calculateRealtime(Account\|int, ?FiscalYear)` | None — always live `SUM(amount*sign)` over posted `document_items`. | Canonical realtime balance query. |
| `getBalanceAsOf(Account\|int, Carbon\|string, ?FiscalYear)` | Cache-key includes the date; TTL-based. | Never uses lifetime `cached_balance`. |
| `getDebitTotal` / `getCreditTotal` | None. | Sum of `amount` filtered by `sign`. |
| `getTurnover(Account\|int, from, to)` | None. | **Extended in 13.2.0** — see §8. |
| `refreshCache` / `updateAfterDocument` / `reverseDocument` | Writes `cached_balance` + FY cache. | Called by `DocumentObserver` on posted/voided transitions; also walks the parent chain when `accounting.balance.update_parents` is true. |

`Account::balance(?FiscalYear)` and `Account::getNaturalBalanceAttribute()` (nature-aware sign flip on `cached_balance`) also exist, plus `HasAccount::balance()/balanceAsOf()/transactions()` on entity-linked models.

**Important:** `cached_balance` is a lifetime, FY-agnostic column updated incrementally on post/void, plus (optionally) propagated up the parent chain. It is *never* a substitute for a real ledger scan and is explicitly excluded from all new reporting code per the architectural rule in this phase.

## 3. Existing database indexes

`acc_accounts`: `(entity_type, entity_id)`, `level`, `type`, `is_active`, unique `(code, branch_id)`.

`acc_documents`: unique `(fiscal_year_id, number)`, `date`, `type`, `status`, `(source_type, source_id)`, unique `idempotency_key` (nullable). **No composite `(status, date)`, `(status, fiscal_year_id, date)`, or `(branch_id, status, date)` index** — every reporting query filters on `status = 'posted'` plus a date range, so these were single-column only.

`acc_document_items`: `(document_id, order)`. **No index on `account_id` alone or `(account_id, document_id)`** — `BalanceService` and the trait's `transactions()` already filter by `account_id` without one.

`acc_document_number_sequences`: unique `(fiscal_year_id, branch_id)`.

These gaps are addressed by a new, justified migration — see §14 below and the changelog.

## 4. Existing account hierarchy behavior

`Karnoweb\Accounting\Support\AccountHierarchy` (static helpers only):

- `maxLevel()`: `config('accounting.account.max_level')` or `count(code_length) - 1` (default `[1,2,4,6]` → level 3 max).
- `postingLevel()`: `config('accounting.account.posting_level')` or `maxLevel()`.

`Account::isPostable()` enforces **detail-only posting**: active, `allow_direct_posting`, `level === postingLevel()`, and no children. `AccountService::create()` enforces the tree shape: `level = parent->level + 1`, rejects exceeding `maxLevel()`, rejects nesting under an already-postable parent, and auto-demotes a parent's `allow_direct_posting` to `false` once it gains a child. There is **no materialized path / nested-set** — traversal is plain `parent_id` self-reference, one row per account, walked via `parent()`/`children()` relations only where already used (e.g. `BalanceService::updateParentChain()` for cached-balance propagation).

This phase adds a **read-only, in-memory rollup** (`HierarchyRollup`) that loads the whole chart once and aggregates bottom-up — it does not change the hierarchy model, constraints, or `AccountHierarchy` itself.

## 5. Existing fiscal-year behavior

`FiscalYear::current()`: `is_current = true` row, else first `active` row. `FiscalYear::findByDate()`: containing range, preferring `active` over `draft`. Overlap is rejected at create/update time (`FiscalYearService::assertNoOverlap`, `config('accounting.fiscal_year.allow_overlap')`, default `false`). Closed fiscal years (`status = closed`) reject new postings (`DocumentService::validateFiscalYear` → `ClosedFiscalYearException`) but remain fully queryable — nothing in the schema or services blocks reading a closed FY's documents. This phase relies on that: **a closed FY's posted documents must remain a valid opening-balance source for the next FY**, which the ledger queries already support by not filtering on FY status.

## 6. Existing cache behavior

Only `BalanceService` caches, and only two kinds: (a) the lifetime `cached_balance` column + `balance_updated_at` TTL check, and (b) FY-scoped `Cache::remember()` entries keyed by `accounting:balance:account:{id}:fy:{id}:branch:{branch}`. Both are **write-through on post/void** via `DocumentObserver`. There is no report-level or snapshot cache anywhere in the package.

## 7. Backward compatibility constraints honored in this phase

- `ReportService::trialBalance()` signature and return shape are **unchanged** (kept, marked `@deprecated` in docblock only — no runtime warning, no behavior change).
- `BalanceService::getTurnover(Account|int, Carbon|string, Carbon|string)` keeps its exact 3-argument call signature and return shape (`['debit','credit','balance']`); FY/branch filtering is additive via a trailing optional `array $options = []`.
- No existing migration, model, config key, or exception class is modified or removed.
- No new dependency is added; no Base-specific code is introduced.
- All 13.1.0 posting-kernel guarantees (detail-only posting, posted/voided immutability, canonical `Document::post()`, FY-aware balances, concurrency-safe numbering, idempotency, builder isolation, hierarchy constraints) are untouched — the reporting layer is strictly additive and read-only.

## 8. Public APIs added in 13.2.0

New namespace `Karnoweb\Accounting\Reporting`:

- `LedgerQuery` — fluent, reusable query foundation (`make()`, `forAccount()`, `forAccounts()`, `forFiscalYear()`, `from()`, `to()`, `branch()`, `postedOnly()`, `excludeVoided()`, `get()`, `cursor()`, `openingBalances()`, `periodTotals()`, `periodTotalsByAccount()`, `trialBalanceAggregates()`).
- `LedgerLine` — one journal line DTO (date, document number/type/id/description, reference, source type/id, account id, debit, credit, signed amount, running balance, fiscal year, branch, order).
- `TrialBalanceRow` / `TrialBalanceReport` — L0–L3 real Trial Balance rows + reconciliation totals.
- `AccountLedger` / `GeneralLedgerReport` — opening → lines → closing, per account.
- `HierarchyRollup` — builds the account tree once, rolls up L3 metrics to L0–L2 without N+1 parent queries.

`Karnoweb\Accounting\Services\ReportService` additions:

- `trialBalanceDetailed(LedgerQuery|FiscalYear|null $criteria = null): TrialBalanceReport`
- `generalLedger(LedgerQuery $query): GeneralLedgerReport`
- `accountStatement(LedgerQuery $query): AccountLedger`

`Karnoweb\Accounting\Services\BalanceService`:

- `getTurnover(Account|int $account, Carbon|string $fromDate, Carbon|string $toDate, array $options = [])` — `$options['fiscal_year']` and `$options['branch_id']` are additive filters; omitting `$options` reproduces the exact 13.1.0 query.

`Karnoweb\Accounting\Enums\AccountNature`:

- `naturalAmount(float $debit, float $credit): float` — nature-aware signed movement (credit increases income/liability/equity, debit increases asset/expense). No `abs()` involved.

New migration `2024_01_01_000009_add_reporting_indexes.php` (see §14).

## 9. APIs that will NOT be changed

- `ReportService::trialBalance()` — signature, defaults, and return shape untouched.
- `BalanceService::getBalance()`, `calculateRealtime()`, `getBalanceAsOf()`, `getDebitTotal()`, `getCreditTotal()`, `refreshCache()`, `updateAfterDocument()`, `reverseDocument()`, `fiscalYearCacheKey()` — untouched.
- `Account`, `Document`, `DocumentItem`, `FiscalYear`, `Branch`, `CostCenter`, `DocumentNumberSequence` models — no new columns, casts, or relations.
- `AccountHierarchy`, `AccountService`, `DocumentService`, `DocumentBuilder`, `FiscalYearService`, `HasAccount`, `DocumentObserver` — untouched.
- No FY dashboard caching changes (`accounting.balance.cache_enabled` behavior is unaffected).
- No Balance Sheet, Opening Balance, Fiscal Year Close, Customer/Supplier subledger, Cash Flow Statement, or counter-account resolution is introduced (explicitly out of scope for this phase).

---

## 10. Architecture of the new reporting layer

**Single authoritative source, one query shape:** every report reads
`acc_document_items JOIN acc_documents` (aliases via `getTable()`, so the configured
`accounting.general.prefix` is always respected), filtered to `documents.status = 'posted'`
(which — since voiding flips `status` from `posted` to `voided` rather than adding a
separate flag — already excludes voided documents with a single equality check; no
`cached_balance`, no operational models, no HTTP/auth context).

**Deterministic order**, shared by every transaction-level API:

```
documents.date ASC, documents.number ASC, documents.id ASC,
document_items.order ASC, document_items.id ASC
```

Never `created_at`.

**Opening vs. period, precisely:** `LedgerQuery::openingQuery()` sums everything with
`documents.date < from`, deliberately **ignoring** any `fiscal_year_id` filter (so a
previous, even closed, fiscal year correctly contributes to the next year's opening
balance) but still honoring account/branch scoping. `LedgerQuery::baseQuery()` (used for
period figures, GL/AS detail lines, and turnover) applies `from <= date <= to`, plus
`fiscal_year_id` **only if the caller explicitly scoped one**. Branch and account filters
apply to both — branch is a document-level partition, not an account-level one (per the
package boundary rule, `branch_id` is never assumed on `acc_accounts` as an authoritative
reporting scope).

All three date comparisons use `whereDate()`, not a plain `where()`. `Document::$casts['date']
=> 'date'` still persists a full `Y-m-d H:i:s` string (Eloquent's `date` cast only truncates
time on *retrieval*, not storage), so a raw string comparison against a bare `Y-m-d` bound
(e.g. a fiscal year's `end_date`) would be lexicographically wrong (`'2025-12-31 00:00:00' <=
'2025-12-31'` is false). `whereDate()` compiles to a driver-appropriate `DATE(...)` comparison,
which is correct regardless of the stored time component.

**No N+1:** Trial Balance is 2 aggregated SQL queries total (`openingQuery` + `baseQuery`,
both `GROUP BY account_id`) regardless of chart size, plus 1 query to load the whole
account chart once for `HierarchyRollup` (O(n) in-memory bottom-up aggregation, no
per-parent queries). General Ledger / Account Statement is 1 aggregated opening query +
1 streamed (`cursor()`) detail query, regardless of how many accounts are requested.

**Iteration/pagination:** `LedgerQuery::cursor()` streams rows via the query builder's
lazy cursor for wide scans. Building a multi-account `GeneralLedgerReport` with a running
balance is inherently sequential per account, so it still buffers the *requested* scope in
memory — true offset pagination with a correct mid-stream running balance is *not*
implemented in this phase.
`ponytail:` known ceiling — ordinary use (one FY, one branch, a bounded account list)
is fine; a full multi-year, all-accounts GL would need either a windowed "balance as of
page N" pre-query or a persisted running-balance snapshot, deferred to a later phase since
this phase explicitly forbids introducing report snapshot caching.

## 11. Database indexes added (§14 of the brief)

Justified by the exact predicates `LedgerQuery`, `ReportService`, and `BalanceService`
now issue on every report:

| Table | Index | Query it serves |
|---|---|---|
| `acc_documents` | `(status, fiscal_year_id, date)` | TB/GL/AS scoped to one fiscal year (`forFiscalYear()`) |
| `acc_documents` | `(status, date)` | TB/GL/AS/turnover scoped by an arbitrary date range only |
| `acc_documents` | `(branch_id, status, date)` | Any branch-filtered report |
| `acc_document_items` | `(account_id, document_id)` | `LedgerQuery` account filtering + the join back to `acc_documents` |

No speculative indexes were added (e.g. no index on `document_items.sign`, no covering
index including `debit`/`credit`, no index for `source_type/source_id` reporting — none
of those are on the hot path introduced by this phase).

## 12. Test plan

New suite under `tests/Reporting/`: `TrialBalanceTest`, `GeneralLedgerTest`,
`AccountStatementTest`, `TurnoverTest`, `HierarchyRollupTest` — covering every case listed
in the brief (empty ledger, FY boundary, previous/closed FY, branch isolation, voided
exclusion, debit-only/credit-only/zero accounts, all four reconciliation invariants,
deterministic same-date ordering, and hierarchy rollup at every level). All 13.1.0 tests
are re-run unmodified except the version-string assertion, which is bumped to `13.2.0`
because the package version itself changed.
