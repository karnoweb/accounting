# Changelog

## [13.4.0] - Unreleased

### Added

- **Operational reversal (E8)** — `ReversalService` / `Accounting::reversal()->reverse($document, $options = [])` and `Document::reverse($reason = null)`:
  - Posts a new `type=reversal` journal in the **same active fiscal year**. Original stays `posted` and is not mutated.
  - Full-document inverse from persisted `document_items` (`amount` unchanged, `sign` flipped). Not `cached_balance` / `BalanceService` / ledger totals.
  - `reversed_document_id` on the new row (nullable FK, indexed, not unique). Idempotency key `reversal:{originalId}`.
  - Reversal-of-reversal allowed. At most one posted reversal per document.
  - Opening/closing documents refused. Posted closing in the FY blocks operational reverse (void closing first, then reverse, then re-close).
  - Closed FY → `ClosedFiscalYearException`. Cross-FY / prior-period correction is not implemented.
- Void guards: lock FY then document; cannot void a document that has a posted reversal; `type=reversal` releases `idempotency_key` on void (same write as opening/closing).
- Exception: `DocumentNotReversibleException`.
- Migration `2024_01_01_000010_add_document_reversal.php`.

### Notes

- **VOID ≠ REVERSAL.** Void hides the original from the posted ledger. Reversal keeps both journals.
- Version remains **13.3.0** until release approval.

## [13.3.0] - 2026-08-15

### Added

- **Fiscal Year lifecycle (E1–E7)** — `FiscalYearService` is the canonical API for configuration and state transitions:
  - `create()` — draft only; required title; normalized dates; `start_date <= end_date`; overlapping and exact-duplicate ranges rejected; lifecycle fields (`status`, `is_current`, `opening_done`, `opened_at`, `closed_at`) cannot be set on create.
  - `update()` — draft years may change title and dates (overlap re-checked); active years may change title only; closed years are not editable.
  - `activate()` — `draft → active`, records `opened_at`, sets the sole `is_current` row, refuses a second active year. Does **not** create opening journal entries; `opening_done` stays false.
  - `validateCanClose()` / `close()` — transactional `active → closed`; requires no draft/pending/approved documents; sets `closed_at` and clears `is_current`. Does **not** create closing entries, next-year rows, or mutate posted ledger history.
  - `assertAcceptsPosting()` — fiscal-year + date primitive used by `PostingService` (draft rejected, active allowed, closed → `ClosedFiscalYearException`).
- **Reusable posting control** — `PostingService` / `Accounting::posting()->assertAllowed($date, $fy, $type, $branchId)` is the canonical ERP posting gate. `DocumentService::create()` / `post()` call it. No `AccountingPeriod` table; FY remains the only persisted period. `type` and `branch_id` are part of the stable call shape and do not change the decision.
- **Manual opening & carry-forward** — `OpeningService` / `Accounting::opening()`:
  - `post()` — one `type=opening` journal on FY `start_date` for permanent accounts; deterministic key `opening:{fyId}:branch:{id|none}`.
  - `carryForward()` — copies posted permanent balances from a closed source year into the immediately following active target. The caller creates the next year.
  - `completeOpening()` / `revertOpening()` own `opening_done`.
- **P&L close & retained earnings** — `ClosingService` / `Accounting::closing()->closeProfitAndLoss()` zeros temporary accounts into the configured retained-earnings equity account (`accounting.account.system_accounts.retained_earnings`, seeded default `310101`). Deterministic key `closing:{fyId}:branch:{id|none}`. Does not call `FiscalYearService::close()`, does not set `closing_done`, and does not create the next year.
- **Opening/closing void + key reuse** — voiding a posted opening or closing clears `idempotency_key` in the same `POSTED → VOIDED` write so the deterministic key can be reused. Operational voids keep their key.
- Exceptions: `InvalidFiscalYearException`, `FiscalYearStateException`.
- `FiscalYear::activate()` / `FiscalYear::close()` delegate to the service (same rules; they cannot bypass validation).
- Lookup: `current()` never returns draft or closed; `findByDate()` returns the unique containing year (including closed, for history) and throws on ambiguous overlap.
- Focused tests: `FiscalYearLifecycleTest`, `FiscalYearOpeningLifecycleTest`, `OpeningServiceTest`, `OpeningCarryForwardTest`, `OpeningVoidLifecycleTest`, `ClosingServiceTest`, `PostingControlTest`.

### Notes

- Closed fiscal years remain fully readable in Trial Balance, General Ledger, and Account Statement.
- Closed years **cannot be reopened** (no `reopen()` method). Next-year generation is **not** implemented.
- No `AccountingPeriod`, monthly/quarterly locks, Balance Sheet, or subledgers.
- No new migration. Overlap of non-exact ranges and single-current-FY are enforced in the service (portable DB constraints are not available for those invariants). See [docs/fiscal-year-lifecycle.md](docs/fiscal-year-lifecycle.md).
- Minor release: additive service API; existing reporting and posting invariants are unchanged. `create()` no longer accepts `status` / `is_current` — use `activate()`.

## [13.2.0] - 2026-08-14

### Added

- **Accounting Reporting Foundation** — new `Karnoweb\Accounting\Reporting` namespace, built strictly on `acc_document_items JOIN acc_documents` (never `cached_balance`):
  - `LedgerQuery` — fluent, reusable query builder (`forAccount()`, `forAccounts()`, `forFiscalYear()`, `from()`, `to()`, `branch()`, `postedOnly()`, `excludeVoided()`, `get()`, `cursor()`, `openingBalances()`, `periodTotals()`, `periodTotalsByAccount()`, `trialBalanceAggregates()`). Always posted-only, always excludes voided, always deterministically ordered by `documents.date, documents.number, documents.id, document_items.order, document_items.id` — never `created_at`.
  - `ReportService::trialBalanceDetailed(LedgerQuery|FiscalYear|null $criteria = null): TrialBalanceReport` — a real Trial Balance with L0 (Group) → L3 (Detail) rollup, opening/period/ending debit and credit per row, and reconciling totals (`opening debit == opening credit`, `period debit == period credit`, `ending debit == ending credit`, `opening + period == ending`).
  - `ReportService::generalLedger(LedgerQuery $query): GeneralLedgerReport` — account → opening balance → ordered journal lines → running balance → closing balance, for one or more accounts.
  - `ReportService::accountStatement(LedgerQuery $query): AccountLedger` — single-account projection over the same `LedgerQuery` foundation (no duplicated SQL); throws `InvalidArgumentException` if the query isn't scoped to exactly one account.
  - `HierarchyRollup::build()` — aggregates L3 journal metrics up to L0–L2 in memory (one query to load the chart, no N+1 parent traversal); never reads `Account::cached_balance`.
  - DTOs: `LedgerLine`, `TrialBalanceRow`, `TrialBalanceReport`, `AccountLedger`, `GeneralLedgerReport`.
  - `BalanceService::getTurnover()` gained an additive, optional `array $options = []` fourth argument (`fiscal_year`, `branch_id`) for FY/branch-aware turnover; the original 3-argument call is unchanged.
  - `AccountNature::naturalAmount(float $debit, float $credit): float` — nature-aware signed movement (credit increases income/liability/equity, debit increases asset/expense); never `abs()`.
  - Migration `2024_01_01_000009_add_reporting_indexes.php` — adds `acc_documents(status, fiscal_year_id, date)`, `acc_documents(status, date)`, `acc_documents(branch_id, status, date)`, `acc_document_items(account_id, document_id)` to serve the new reporting query shapes.

### Deprecated

- `ReportService::trialBalance()` — kept for backward compatibility (same signature, same return shape), but it is **not a real Trial Balance** (no opening/period split, no hierarchy rollup, silently drops near-zero balances). Use `trialBalanceDetailed()` instead.

### Notes

- Reporting reads are always posted-only and exclude voided documents; they never use lifetime `cached_balance`, parent cached balances, or any operational/application model.
- No new dependency, no Karno Base coupling. No Balance Sheet, Opening Balance, Fiscal Year Close, Customer/Supplier subledger, Cash Flow Statement, or counter-account resolution introduced — deferred to later phases.
- Minor release: one new migration required; all existing public APIs remain compatible.

## [13.1.0] - 2026-08-14

### Added

- Package-level detail-only posting invariant (`Account::isPostable()` / `AccountService::assertPostable()`).
- Posted `DocumentItem` immutability (update/delete rejected).
- Canonical posting path: `Document::post()` delegates to `DocumentService::post()`.
- Fiscal-year-aware balance caching (FY-scoped cache keys; lifetime `cached_balance` only when FY omitted).
- `documents.idempotency_key` unique column for optional DB-safe idempotency.
- `document_number_sequences` table + row-locked numbering (concurrency-safe).
- Configurable account hierarchy: `account.max_level`, `account.posting_level`.
- Fiscal year overlap rejection (`fiscal_year.allow_overlap`, default `false`).
- Fresh `DocumentBuilder` per `Accounting::document()` call (no shared singleton state).
- PHPUnit package test suite (Orchestra Testbench).

### Fixed

- `AccountingManager::version()` now reads `composer.json` (was hardcoded `1.0.0`).
- `BalanceService::getBalance($account, $fiscalYear)` no longer returns another FY's cached lifetime balance.
- Document numbering under concurrency (replaced `max(number)+1`).

### Notes

- Source polymorphic uniqueness is **not** enforced: multiple documents per `(source_type, source_id)` remain allowed; use `idempotency_key` when retries must be unique.
- Minor release: new migrations required; public fluent APIs remain compatible.

## [13.0.0] - 2026-07-08

### Added

- Laravel 13 support (dedicated release line).

### Changed

- Minimum PHP version raised to 8.3.
- Illuminate packages now require `^13.0`.

### Notes

- For Laravel 11–12, continue using the `^1.0` release line.
