# Fiscal Year Lifecycle

Package version: **13.3.0**

This document describes the fiscal-year lifecycle implemented by `FiscalYearService`.
It matches the code. Features listed under [Not implemented](#not-implemented) are intentionally absent.

---

## States

Persisted `status` values remain:

| Status | Meaning | Posting | Reports |
|--------|---------|---------|---------|
| `draft` | Configuration only | rejected | year is still queryable if referenced |
| `active` | Open books | allowed (date must fall in range) | yes |
| `closed` | Books frozen | rejected (`ClosedFiscalYearException`) | **yes — historical data stays visible** |

There is **no persisted `closing` status**. Close preparation is `validateCanClose()`, then `close()` moves `active → closed` in one transaction.

`opening_done` is a **lifecycle completion flag**, not proof that an opening journal exists.

- `create()` / `update()` cannot set it (`fiscal_year_lifecycle_fields_locked`).
- Canonical writers: `activate()`, `close()`, `completeOpening()`, `revertOpening()`.
- `activate()` leaves it `false`. `close()` preserves whatever value it already has.
- `completeOpening()` / `revertOpening()` change **only** this flag. They do not create, post, void, or delete documents.

Opening journals, carry-forward, and P&L close are **not** implemented in `FiscalYearService`.

Manual opening and carry-forward live on `OpeningService`. P&L close lives on `ClosingService`. `FiscalYearService` still writes flags and status only.

---

## Allowed transitions

```text
(create) → draft → (activate) → active → (close) → closed
                 ↘ title / dates may be edited
```

| From | Operation | To | Notes |
|------|-----------|----|--------|
| — | `create()` | `draft` | `is_current = false`, `opening_done = false` |
| `draft` | `update()` | `draft` | title and dates; dates re-check overlap |
| `draft` | `activate()` | `active` | sets `is_current`, `opened_at`; refuses if another FY is already `active` |
| `active` | `activate()` | `active` | idempotent; does not reset `opened_at` |
| `active` | `update()` | `active` | **title only**; date range is locked |
| `active` | `completeOpening()` | `active` | sets `opening_done = true`; idempotent if already true; no journals |
| `active` | `revertOpening()` | `active` | sets `opening_done = false` only if no posted `type=opening` document remains; idempotent if already false |
| `active` | `close()` | `closed` | after `validateCanClose()`; sets `closed_at`, clears `is_current` |
| `closed` | anything mutating | — | rejected (`FiscalYearStateException`) |

Closed years **cannot be reopened**. There is no `reopen()` method. Calling `activate()` on a closed year throws `FiscalYearStateException`.

---

## Current fiscal year

`FiscalYearService::current()` / `FiscalYear::current()`:

1. `status = active` AND `is_current = true`, else
2. first `status = active` row, else
3. `null`

Draft and closed years are never returned as current.

After `close()`, current is `null` until another year is activated. Close does **not** auto-create or auto-activate the next year.

---

## Date lookup

`findByDate($date)` returns the unique fiscal year whose `[start_date, end_date]` contains the date, **including draft and closed years**.

If more than one row matches, it throws `FiscalYearOverlapException` instead of picking a preferred status. Overlaps are rejected at create/update when `config('accounting.fiscal_year.allow_overlap')` is `false` (default).

---

## Posting control

The reusable ERP question is: **can this document be posted on this date, for this fiscal year?**

`Accounting::posting()->assertAllowed($date, $fiscalYear = null, $type = null, $branchId = null)` is the canonical gate.

`DocumentService::create()` / `post()` call that gate. `Document::post()` still delegates to `DocumentService::post()`.

`FiscalYearService::assertAcceptsPosting($fy, $date)` remains the **fiscal-year + date primitive**. It is not the ERP-facing API. `PostingService` resolves the year, then delegates there.

| Input | Result |
|-------|--------|
| active FY + date on `start_date` / inside / `end_date` | allowed |
| date before `start_date` or after `end_date` | `RuntimeException` (`date_out_of_fiscal_year`) |
| draft FY | `FiscalYearStateException` (`fiscal_year_not_active`) |
| closed FY | `ClosedFiscalYearException` (`fiscal_year_closed`) |
| no FY contains the date (FY omitted) | `FiscalYearStateException` (`no_fiscal_year_for_date`) |
| more than one FY contains the date | `FiscalYearOverlapException` (`fiscal_year_ambiguous`) |
| empty / malformed date | `InvalidArgumentException` (`date_required`) |

`findByDate()` is reused when the year is omitted. The gate does **not** fall back to `FiscalYear::current()` — that would hide a missing or ambiguous match.

Historical and future dates inside an **active** year are allowed. There is no “today” cutoff.

`type` and `branch_id` are part of the stable call shape. They do **not** change the decision today. `NULL` branch is a real bucket and is never merged with `config('accounting.branch.default_id')`.

### What stays outside the generic gate

| Owner | Still owns |
|-------|------------|
| `FiscalYearService` | draft → active → closed; `close()` is journal-free |
| `OpeningService` | permanent accounts, start date, deterministic opening key, operational-activity block |
| `ClosingService` | temporary close into retained earnings, end date, deterministic closing key |
| `DocumentService` | balance, postable accounts, numbering, idempotency, `create()` → `post()` |
| `ReversalService` | same-FY operational full-document reversal; does not post into a closed year |
| `Document::void()` | POSTED → VOIDED; void does **not** require an “open period” |

Opening and closing documents still pass through `DocumentService`, so they cannot bypass FY/date control. Their extra rules stay on those services.

### AccountingPeriod

**Intentionally not introduced.** The fiscal year is the only persisted posting period. Monthly / quarterly / tax / audit locks are not in the current contract. A period table would be premature until a real lock smaller than a fiscal year is required.

Void, reporting, and ledger arithmetic are unchanged. Period controls do not filter `LedgerQuery`.

### How ERP packages should post

```php
Accounting::posting()->assertAllowed($date, $fy, 'sale', $branchId);

$document = Accounting::document()
    ->type('sale')
    ->date($date)
    ->fiscalYear($fy)
    ->debit($debit, $amount)
    ->credit($credit, $amount)
    ->save();

$document->post();
```

The adapter must not copy fiscal-year SQL, status checks, or date-range rules. Accounting remains the source of truth.

---

## What `close()` does

Inside a DB transaction, with row locks:

- re-checks `validateCanClose()`
- sets `status = closed`, `closed_at = now()`, `is_current = false`
- leaves `opening_done` unchanged
- does **not** insert documents, closing entries, or retained-earnings postings
- does **not** mutate posted/voided ledger rows
- does **not** hide the year from reports

`validateCanClose()` requires the year to be `active` and to have **no** documents in `draft`, `pending`, or `approved`. `posted` and `voided` documents are allowed.

---

## Reopen policy

Closed fiscal years are immutable. Reopening would allow new postings into a period whose reports and (future) carry-forward were already taken as final.

That is incompatible with posted-line immutability and with a later opening-balance phase. Therefore:

- no `reopen()` API
- `activate()` / `update()` / `close()` / `completeOpening()` / `revertOpening()` on a closed year throw `FiscalYearStateException`

---

## Database

No new migration in 13.3.0. Existing `acc_fiscal_years` already has:

- unique `(start_date, end_date)` — exact duplicate ranges
- indexes on `status` and `is_current`

Non-exact overlap (e.g. Jan–Dec vs Jun–May) cannot be expressed as a portable CHECK/EXCLUDE constraint across SQLite, MySQL, and PostgreSQL. It is enforced **transactionally** in `FiscalYearService` (`lockForUpdate` on all fiscal-year rows, then `assertNoOverlap`).

A partial unique index on `is_current = true` is likewise not portable. “At most one current active year” is enforced in `activate()` / `close()`.

---

## `completeOpening()` / `revertOpening()`

Inside a DB transaction, with the same row locks as `activate()` / `close()` (`lockAllFiscalYears()` then `lockFiscalYear()`):

**`completeOpening()`**

- requires `active` (draft and closed are rejected)
- if `opening_done` is already true, returns unchanged
- otherwise sets **only** `opening_done = true`
- does not change `status`, `is_current`, dates, `opened_at`, or `closed_at`
- does not insert or post documents

**`revertOpening()`**

- requires `active`
- if `opening_done` is already false, returns unchanged
- if any posted document with `type = opening` exists for the year, throws `FiscalYearStateException` (`fiscal_year_has_posted_opening`)
- voided opening documents do not block revert
- otherwise sets **only** `opening_done = false`
- does not void, delete, or mutate documents

`FiscalYear::completeOpening()` / `FiscalYear::revertOpening()` delegate to the service (no domain logic on the model).

---

## Manual opening (`OpeningService`)

`Accounting::opening()->post($fy, $items, $branchId = null)` posts one `type=opening` document through `DocumentService` and then sets `opening_done`.

- Target must be **active**. Draft → `FiscalYearStateException`. Closed → `ClosedFiscalYearException`.
- Date is always the fiscal year `start_date`.
- Lines must be postable **permanent** accounts (asset / liability / equity). Income and expense are refused.
- `branch_id` is always sent explicitly, including `null`.
- Idempotency key: `opening:{fyId}:branch:{id|none}`. A second call for the same FY+branch returns the existing posted document.
- Posted operational (non-opening) documents in the target year refuse opening.
- `isComplete($fy)` is `opening_done`, not “a journal row exists”.
- Voiding a posted opening (active FY) releases its idempotency key in the same `POSTED → VOIDED` write. Retry with the same deterministic key is allowed after the last posted opening is gone (`opening_done` becomes false).

---

## Carry-forward (`OpeningService::carryForward`)

`Accounting::opening()->carryForward($source, $target)` copies **posted permanent** balances from a **closed** source year into the immediately following **active** target year.

Required caller sequence (the package does **not** auto-create the next year):

```text
ClosingService::closeProfitAndLoss(source)   // while source is still active
    ↓
source.close()
    ↓
target.create() + target.activate()
    ↓
OpeningService::carryForward(source, target)
```

Rules:

- `target.start_date` must equal `source.end_date + 1 day`. Gaps, overlaps, earlier years, and later non-consecutive years are refused.
- Source extraction is `LedgerQuery::make()->forFiscalYear($source)->branch($branchId)->periodTotalsByAccount()`. Never `cached_balance` / `BalanceService`.
- Permanent = `AccountType::isPermanent()` (asset, liability, equity). Temporary = `isTemporary()` (income, expense). Temporaries never become opening lines.
- Signed balance `S = debit - credit`. `S > 0` → debit amount `S`; `S < 0` → credit amount `abs(S)` after orientation is known. `|S| < 0.01` is omitted.
- One opening document per source `documents.branch_id` bucket. **`NULL` is a real bucket** and is not merged with the configured default branch.
- Each branch document must balance on its own. If that branch’s temporary residual has `abs(R) >= 0.01`, the **entire** carry-forward fails (no retained earnings, no equity plug, no closing journal).
- A material permanent balance on a non-postable account fails the entire operation.
- Canonical path: `DocumentService::create()` + `post()` for each non-empty bucket, then `completeOpening($target)` **once**. Does **not** call `OpeningService::post()` (that would set `opening_done` after the first branch).
- Document: `type=opening`, `date=target.start_date`, `branch_id` always present (including `null`), `idempotency_key=opening:{targetId}:branch:{id|none}`, `meta.source_fiscal_year_id` and `meta.operation=carry_forward`.
- Empty source (no material permanents, residual ~0): **no document**, then `completeOpening()`; returns `[]`. `opening_done` is still true.
- Repeat with matching posted openings is idempotent. `opening_done` plus mismatched openings fails; it is not auto-repaired. Partial posted openings while `opening_done` is false also fail.
- FY-scoped reports: the opening journal on `start_date` is **period** activity for a full-year query; it contributes to opening only when `from > start_date`. Prior years do not leak into FY-scoped opening.

Carry-forward still refuses a material temporary residual. Run `ClosingService::closeProfitAndLoss()` on the **active** source year first so that residual is ~0.

---

## P&L close (`ClosingService`)

`Accounting::closing()->closeProfitAndLoss($fy)` posts `type=closing` journals that zero **temporary** (income/expense) accounts into the configured retained-earnings equity account.

This is a separate **active-FY** operation. It does **not** call `FiscalYearService::close()`, does not set a `closing_done` flag, and does not create the next year.

```text
(optional) OpeningService::post / carryForward
    ↓
operational posted activity
    ↓
ClosingService::closeProfitAndLoss($fy)     // FY still active
    ↓
FiscalYearService::close($fy)               // still journal-free
    ↓
next FY create + activate
    ↓
OpeningService::carryForward(source, target)
```

Rules:

- Target must be **active**. Draft → `FiscalYearStateException`. Closed → `ClosedFiscalYearException`. Closed years cannot receive closing journals and cannot be reopened.
- `opening_done` is irrelevant (true or false both allowed).
- Unposted documents are not checked here; `FiscalYearService::close()` still owns that gate.
- Extraction is `LedgerQuery::make()->forFiscalYear($fy)->branch($branchId)->periodTotalsByAccount()`. Never `cached_balance` / `BalanceService`.
- One closing document per posted `documents.branch_id` bucket that has material temporaries. **`NULL` is a real bucket.**
- `S = round(debit - credit, 2)`. Temporary `S > 0` → credit `abs(S)`; `S < 0` → debit `abs(S)`. `|S| < 0.01` is omitted.
- Branch residual `R = Σ S` over temporaries. `|R| ≥ 0.01`: debit RE on net loss (`R > 0`), credit RE on net profit (`R < 0`). `|R| < 0.01`: no RE line.
- RE is `config('accounting.account.system_accounts.retained_earnings')` and must be an active, postable, permanent **equity** account (default code `310101`).
- A material temporary on a non-postable/inactive account fails the **entire** transaction (`closing_non_postable_temporary`).
- Document: `type=closing`, `date=fy.end_date`, `branch_id` always present (including `null`), `idempotency_key=closing:{fyId}:branch:{id|none}`, `meta.operation=close_pnl`.
- Empty activity: **no document**; `isProfitAndLossClosed()` is true.
- Repeat with matching posted closings is idempotent. Mismatched posted closings fail (`closing_inconsistent_state`); they are not auto-repaired.
- `isProfitAndLossClosed($fy)` recomputes per-bucket temporary `R`. There is no `closing_done` column.
- Voiding a posted closing (same path as E5) clears its idempotency key. It does **not** change `opening_done` or reopen a closed year.

FY-scoped full-year reports treat the closing journal on `end_date` as **period** activity. A query with `to < end_date` excludes it.

`FiscalYearService::close()` remains journal-free.

---

## Opening void lifecycle

`Document::void()` remains the only void entry point. Opening-specific reactions live in `DocumentObserver::handleVoided()` after `BalanceService::reverseDocument()`. `Document::void()` never writes `opening_done`.

**Active fiscal year**

- The opening row is voided through the canonical path (status, notes, ledger reverse).
- `idempotency_key` is set to `null` in the same `POSTED → VOIDED` update for `type=opening` and `type=closing`. VOIDED rows are immutable, so the key cannot be cleared later.
- If **no other posted** `type=opening` document remains in that year, `FiscalYearService::revertOpening()` runs and `opening_done` becomes false.
- If another posted opening remains (typical multi-branch carry-forward), `opening_done` stays true.
- After the last posted opening is voided, `OpeningService::post()` / `carryForward()` may reuse `opening:{fyId}:branch:{id|none}`.
- A posted document **cannot** be voided while a posted reversal points at it.

**Closed fiscal year**

- Existing void permission is unchanged (closed years are not given a new “cannot void” rule).
- `revertOpening()` is **not** called. The year stays `closed`. `opening_done` stays true.
- The voided opening remains immutable. No replacement document is created.

**Operational documents**

- Void does not clear `idempotency_key` (except `type=reversal`).
- Void does not change `opening_done`.

**Reversal documents**

- Voiding a posted `type=reversal` document clears `idempotency_key` in the same write so `reversal:{originalId}` can be reused.
- The `reversed_document_id` FK is kept.

---

## Operational reversal (`ReversalService`)

`Accounting::reversal()->reverse($document, $options = [])` posts a new `type=reversal` journal that inverts the original’s persisted items. `Document::reverse($reason = null)` delegates to the same service.

**VOID ≠ REVERSAL**

| | Void | Reversal |
|---|---|---|
| Original | `posted` → `voided` | stays `posted` |
| Ledger | disappears (posted-only) | original + opposite journal |
| New document | no | yes, new number |

A void is not historical correction. A reversal does not hide the original.

Rules:

- Same fiscal year only. Default date = original date. Optional `date` must stay inside that FY and pass `PostingService`.
- Original FY must be **active**. Closed → `ClosedFiscalYearException`. Draft → `FiscalYearStateException`.
- Closed-FY / cross-FY / prior-period adjustment is **not** implemented.
- Full document only. Source is persisted `document_items` (`amount` unchanged, `sign` flipped). Not `cached_balance`, `BalanceService`, or ledger totals.
- Operational documents only. `type=opening` and `type=closing` are refused.
- If any posted `type=closing` exists in the original FY, reverse is refused. Operator sequence: void closing → reverse → `closeProfitAndLoss()`.
- `branch_id` is copied, including `NULL`. No FY/branch override.
- `R1.reversed_document_id = J1.id`. `R1.idempotency_key = reversal:{J1.id}`. Repeat returns the same posted R1.
- Reversal-of-reversal is allowed (`J1 → R1 → R2`). Each document has at most one posted reversal.
- After R1 is voided, a new reversal of J1 may be created.
- J1 cannot be voided while R1 is posted.
- Does not call `FiscalYearService::close()`, `completeOpening()`, or `revertOpening()`.

---

## Not implemented

These belong to later phases and are **not** provided here:

- automatic next-year generation
- Balance Sheet
- customer/supplier subledgers
- closed-FY / prior-period correction
- partial reversal
- opening or closing reversal
