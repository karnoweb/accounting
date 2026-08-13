# Changelog

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
