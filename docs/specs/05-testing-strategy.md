# 05 — Testing Strategy (the ledger gate)

The ledger core gets the strictest tests in the codebase. The repo uses **PHPUnit** (not Pest) with SQLite `:memory:` for the default suite — but ledger tests that exercise triggers/CHECK constraints must run on **MariaDB 11.8** (SQLite cannot enforce them). Configure a `mariadb_testing` connection + a CI `mariadb:11.8` service for the `Ledger` suite.

## Approach: generative harness + hand-verified fixtures (no heavy new dependency)
There is **no property-based library** installed, and Phase-1 exit criteria depend on property tests. Rather than fork the framework (Pest) or add `eris`, add a lightweight **generative harness** in `tests/Support/LedgerGenerator.php`: builds random *valid balanced* postings (random accounts, random split amounts that sum to a balanced entry, random dates within open periods) and random *invalid* postings (unbalanced, both-zero line, pre-lock date, edit-after-post). Seed the RNG from a fixed value per test for reproducibility; vary per-iteration by index.

## Invariants to prove (property tests)
For any random set of valid postings:
1. **Trial balance nets to zero** (Σ all `debit_centavos` = Σ all `credit_centavos`).
2. **Per-account balance = sum of its lines** (cache matches a fresh recompute — run `ledger:rebuild-balances` and diff).
3. **Reversal nets to zero** — post then `reverse()`; the two entries sum to zero on every account.
4. **Idempotency** — posting the same draft (same `idempotency_key`) twice yields one entry.
5. **Gapless numbering** — under N concurrent posts (use DB transactions / parallel processes), numbers are consecutive with no gaps; a rolled-back post consumes no number.

For invalid postings, assert **rejection**:
6. Unbalanced entry → `UnbalancedEntry`.
7. A line with both debit & credit, or both zero → DB CHECK violation.
8. Editing/deleting a posted entry or its lines → trigger `SIGNAL` (immutability).
9. Posting on/before `posting_lock_date` or into a closed period → `PeriodClosed`.
10. `audit_log` UPDATE/DELETE → rejected (append-only); hash chain verifies.

## Hand-verified fixtures (accountant-confirmed)
5–10 scenarios in `docs/fixtures/` with hand-computed expected debits/credits and trial balances, covering: opening balances, VATable sales invoice (accrual + cash), collection with EWT, vendor bill with withholding, manual adjusting entry, reversal, month close, year-end close. Each fixture is a test: build the documents → post → assert the exact journal lines and the resulting trial balance. **These must be reviewed by a CPA** before they're treated as ground truth (they encode tax treatment — see `03`/`06`).

## Conformance oracle (optional)
Use `ekmungai/eloquent-ifrs` in a throwaway harness as a **second implementation** to cross-check pure double-entry math (post the same neutral scenarios and compare account balances). It is reference-only — never a dependency, and its float money means only whole-peso scenarios are comparable.

## The gate
Any PR touching `journal_*`, `PostingService`, the enforcement triggers, or `document_sequences` must run the **full `Ledger` suite green on MariaDB 11.8**. Wire this into CI as a required check. `php artisan ledger:verify` must also pass clean on the seeded fixtures.

## Commands
```bash
php artisan test --testsuite=Ledger        # the strict suite (MariaDB 11.8) — add this <testsuite> to phpunit.xml in Phase 1
php artisan test                           # full suite
php artisan ledger:verify                  # balance + audit-hash reconciliation
php artisan inventory:verify               # stock cache vs movements + subledger-to-GL tie-out (spec 08)
```
Notes: `--testsuite=Ledger` is the canonical invocation (CLAUDE.md updated to match); the gapless-numbering **concurrency** property (item 5) requires real parallel MariaDB connections — it cannot run on SQLite, so it lives only in the MySQL suite/CI. Fixtures now include month close (S9) and the inventory round-trip (S10).
