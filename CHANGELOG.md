# Changelog

## [13.5.0] - 2026-09-03

### Changed

- **`FiscalYearService::update()` date rules relaxed.** Replaces the old blanket lock
  ("any date change on an `active` year, or any year with documents, throws
  `fiscal_year_dates_locked`") with rules that let a seeded, decades-spanning fiscal
  year be split into real years without going through `close()` first:
  - `start_date` is now editable **only** while `status = draft` **and** the year has
    zero documents. `active` years, or any year that already has a document, reject a
    `start_date` change (new message `fiscal_year_start_date_locked`).
  - `end_date` is now editable while `status` is `draft` **or** `active` (closed years
    still reject any date mutation, unchanged). It must stay `>= start_date` and
    `>= latestDocumentDate($fiscalYear)` — the greatest `documents.date` for that
    fiscal year across all document statuses, not just posted (new message
    `fiscal_year_end_date_before_documents`, interpolated with the offending date).
    Overlap is still re-checked (`assertNoOverlap()`), unchanged.
  - There is intentionally **no** "`end_date >= today`" rule — shortening `end_date`
    below today without a consecutive next fiscal year is still safe:
    `PostingService` already refuses documents dated past `end_date`
    (`date_out_of_fiscal_year`).
  - New public helpers: `FiscalYearService::latestDocumentDate(FiscalYear): ?string`
    and `FiscalYearService::minAllowedEndDate(FiscalYear): string`
    (`max(start_date, latestDocumentDate())`), also exposed as read-only model
    accessors `$fiscalYear->latest_document_date` / `$fiscalYear->min_allowed_end_date`.

- **`OpeningService`: draft → confirm opening flow.** An opening for a (fiscal year,
  branch) bucket now goes through an explicit draft stage instead of being posted in
  one call, so a silently-seeded fiscal year's accounting UI can unlock later without
  forcing an immediate, balanced opening:
  - New `saveDraft(FiscalYear|int $target, array $items, ?int $branchId = null): Document`
    — creates (or, on a second call for the same bucket, **replaces in place**, same
    document id and idempotency key) a `type=opening, status=draft` document. Lines
    are still restricted to postable permanent accounts, but the draft **may be
    unbalanced** — balance is enforced only by `confirm()`. Does not set
    `opening_done` and does not check for posted operational documents (deferred to
    `confirm()`). Refuses (`opening_bucket_already_posted`) if a posted opening
    already exists for the bucket.
  - New `confirm(FiscalYear|int $target, ?int $branchId = null): Document` — posts the
    bucket's draft **in place** (`draft → posted`, same idempotency key, never a
    second document). Requires balance (`UnbalancedDocumentException` otherwise) and
    no posted operational document yet (`opening_has_posted_activity`). Rejects
    (`opening_no_draft`) if no draft/matching document exists for the bucket.
    Idempotent on an already-posted bucket. Sets `opening_done = true` once no
    `type=opening, status=draft` document remains for the fiscal year — i.e. once
    every bucket that needed a draft has been confirmed.
  - New `find(FiscalYear|int $target, ?int $branchId = null): ?Document` — the draft
    or posted opening for that bucket, or `null`.
  - `post(FiscalYear|int $target, array $items, ?int $branchId = null): Document` is
    kept as a one-shot convenience, now implemented as `saveDraft()` + `confirm()` in
    one transaction, for existing callers (e.g. Matrix's `PostOpeningBalanceAction`).
    Idempotent replay behavior is unchanged.
  - `carryForward(source, target)` now creates/refreshes **draft** openings (one
    `saveDraft()` per non-empty source branch bucket) instead of posting them
    directly, and no longer sets `opening_done` by itself — the caller must
    `confirm()` each bucket. Exceptions:
    - A bucket whose idempotency key already resolves to a **posted** document
      matching the recomputed plan is left untouched and returned as-is (keeps
      crash-recovery / idle-repeat calls safe).
    - If every returned document already happens to be posted (nothing needed a
      fresh draft), `completeOpening()` still runs immediately.
  - A **partially-confirmed** target (some branch buckets posted, others still draft)
    is now a normal, expected state; re-running `carryForward()` on it succeeds
    instead of throwing. Only a **mismatched** posted bucket (wrong items for that
    key) remains a hard error (`opening_inconsistent_state`).
  - `DocumentService::create()` / `validateItems()` gained an internal
    `balance_required` (data key) / `$requireBalance` parameter (default `true`,
    fully backward compatible) so a draft opening's items can be persisted without
    tripping the existing strict-balance check.

### Added

- Lang keys (`lang/en`, `lang/fa`): `fiscal_year_start_date_locked`,
  `fiscal_year_end_date_before_documents`, `opening_bucket_already_posted`,
  `opening_no_draft`.

### Docs

- `docs/fiscal-year-lifecycle.md`: documented the new `update()` date rules, the
  draft → confirm opening flow, and the updated `carryForward()` contract.
- `docs/02-concepts.md`: rewrote the "افتتاحیه" (opening) section for the
  draft → confirm flow.
- `docs/08-api-reference.md`: updated the `FiscalYearService` and `OpeningService`
  method tables.

## [13.4.3] - 2026-09-02

### Fixed

- **Multi-branch account/document isolation.** Several lookups silently ignored `branch_id`, letting one branch's data leak into or be posted against another branch's accounts:
  - `ClosingService::closeProfitAndLoss()` resolved a single retained-earnings account for the fiscal year regardless of branch; closing one branch could credit another branch's retained earnings. It now resolves retained earnings per posted branch bucket.
  - `AccountService::getSystemAccount()` ignored branch entirely (always the first account with a given code, effectively HQ's). It now accepts an optional `$branchId` and resolves the branch-specific account, falling back to a shared (`branch_id = null`) account when no dedicated one exists. `Accounting::systemAccount($key, $branchId = null)` exposes this.
  - `AccountService::create()` could attach a child under a parent from a *different* branch (via `parent_id` or the unscoped `parent_code` fallback), letting a branch's accounts nest under another branch's tree. Parent lookup by `parent_code` is now branch-scoped and throws `AccountNotFoundException` if not found for that branch (no silent cross-branch fallback); attaching under a parent with a different, non-null `branch_id` throws `InvalidAccountHierarchyException`.
  - `AccountService::search()` had no `branch_id` filter, so branch-unaware callers (e.g. the legacy `trialBalance()`) mixed all branches together.
  - `DocumentService` did not check that an item's account belonged to the document's branch, allowing cross-branch postings. `create()` / `post()` now reject an item whose account has a different, non-null `branch_id` than the document.
  - `AccountingManager::currentBranch()` only read `accounting.branch.default_id` and ignored a configured `accounting.branch.resolver`, unlike `DocumentService`. Both now share the new `Support\BranchContext::resolveDefaultId()` helper.
  - `ReportService::buildAccountLedgers()`'s "no accounts requested" fallback could include accounts from every branch; it now respects the `LedgerQuery`'s branch filter.
  - `HasAccount::createAccount()` looked up `parent_code` without `branch_id`, same class of bug as `AccountService::create()`.
- `DefaultAccountsSeeder` now fails fast with a clear error if `accounting.account.code_length` doesn't match the hardcoded seed codes' assumed lengths (`[1,2,4,6]`), instead of silently seeding a chart that corrupts later auto-generated codes.

### Added

- New `Support\BranchContext::resolveDefaultId()` — centralizes branch resolution (resolver callback, else `default_id`, else `null` when branching is disabled), shared by `DocumentService` and `AccountingManager`.
- New exception messages (`account_parent_branch_mismatch`, `account_branch_mismatch`) in `lang/en` and `lang/fa`.
- **Extended default chart of accounts** (`DefaultAccountsSeeder`) — new accounts validated against real customer apps built on this package (HR payroll/loans, `karnoweb/laravel-inventory`, e-commerce/LMS billing):
  - Inventory asset (`110901`), inventory shrinkage/waste expense (`520401`), and inventory count-surplus income (`410201`) for stock receive/issue/waste/adjustment flows.
  - Employee loan/advance receivable (`111101`) and payroll payable/insurance-payable/tax-payable liabilities (`210501`, `210502`, `210402`) plus salary and employer-insurance expense (`520201`, `520202`) for HR payroll accrual and payment.
  - Payment gateway clearing asset (`110501`) so online captures land here before settling to the bank, instead of being posted straight into the operating bank account.
  - Sales discount (`490101`) and sales return (`490201`) contra-revenue accounts, kept separate from gross sales income.
  - VAT payable (`210401`) liability for output tax on sales.
  - Petty cash / imprest float (`110401`), separate from the main cash drawer.
  - Bank/gateway fee expense (`520301`).
  - Customer wallet / store-credit liability group (`2106`) for prepaid balances (left as a group; individual wallets get their own detail account nested here at runtime).
  - `config('accounting.account.system_accounts')` gained matching keys: `inventory`, `inventory_shrinkage`, `inventory_count_gain`, `employee_loan_receivable`, `gateway_clearing`, `sales_discount`, `sales_return`, `vat_payable`, `payroll_tax_payable`, `payroll_payable`, `payroll_insurance_payable`, `payroll_salary_expense`, `payroll_employer_insurance`, `bank_fee`.
  - Also fixes `receivables` / `payables`: they previously resolved to the level-2 group (moein) accounts `1103` / `2101`, which always have `allow_direct_posting = false`, so any document posted against `Accounting::systemAccount('receivables'|'payables')` threw `NotPostableException`. `system_accounts` now points these keys at new level-3 detail leaves (`110300`, `210101`); the old group accounts are still seeded (for hierarchy/rollup) and remain correctly non-postable.

## [13.4.2] - 2026-08-31

### Changed

- مایگریشن‌ها با تگ `accounting-migrations` قابل publish شدند (`publishes` با حفظ نام فایل).
- تاریخ فایل‌های مایگریشن از `2024_01_01_*` به `2021_01_01_*` تغییر کرد تا در ابتدای ترتیب مایگریشن پروژه اجرا شوند.
- README بخش publish مایگریشن به‌روز شد.

## [13.4.1] - 2026-08-18

### Docs

- بازنویسی مرجع مستندات پکیج بر اساس کد واقعی، migrationها، مدل‌ها، سرویس‌ها و گزارش‌ها.
- تفکیک مستندات به دو محور «راهنمای استفاده» و «دانشنامه مفاهیم و ماژول‌ها» با اصلاح API reference، معماری، نصب، پیکربندی، امنیت، گزارش‌ها و مثال‌ها.
- افزودن `docs/16-documentation-gaps.md` برای ثبت صریح اختلاف‌های بین مستندات قدیمی، config و رفتار واقعی هسته.
- بازنویسی همه مثال‌های `docs/examples/shop/` به‌عنوان سناریوهای مفهومی دامنه‌ای، نه قراردادهای هسته پکیج.

## [13.4.0] - 2026-08-15

### Added

- **Operational document reversal (E8)** — `ReversalService` / `Accounting::reversal()->reverse($document, $options = [])` and `Document::reverse($reason = null)`:
  - Same-fiscal-year reversal only: posts a new `type=reversal` journal in the original document's **active** FY. Original stays `posted` and is not mutated.
  - Full-document inverse from persisted `document_items` (`amount` unchanged, `sign` flipped). Not `cached_balance` / `BalanceService` / ledger totals.
  - `reversed_document_id` relation on the new row (nullable FK, indexed, not unique) plus `Document::reversedDocument()` / `reversals()` / `postedReversal()`.
  - Reversal idempotency via deterministic key `reversal:{originalId}`; at most one posted reversal per document.
  - Reversal-of-reversal allowed (`J1 → R1 → R2`).
  - Reversal/void interaction: lock FY then document; cannot void a document that has a posted reversal; voiding a `type=reversal` row releases `idempotency_key` (same write as opening/closing).
  - Posting-control integration: reversal create/post goes through `PostingService`; closed FY → `ClosedFiscalYearException`.
  - Opening/closing documents refused. Posted closing in the FY blocks operational reverse (void closing first, then reverse, then re-close).
- Exception: `DocumentNotReversibleException`.
- Migration `2024_01_01_000010_add_document_reversal.php`.
- Test coverage: `ReversalServiceTest` (same-FY reverse, inverse lines, idempotency, reversal-of-reversal, void interaction, posting-control / closed-FY, opening/closing refusal).

### Notes

- **VOID ≠ REVERSAL.** Void hides the original from the posted ledger. Reversal keeps both journals.
- This release does **not** implement: cross-FY reversal, closed-FY correction, prior-period adjustment, RE correction, partial reversal, opening reversal, closing reversal, or FY reopening.

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
