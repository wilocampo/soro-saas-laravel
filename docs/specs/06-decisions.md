# 06 — Decisions (ADRs), Sign-off List & Open Items

## Resolved decisions (ADR-style)

**D0 — Framework/tenancy/DB (corrected from the original handoff).** Laravel 12 (not 13); DB-per-tenant via `spatie/laravel-multitenancy` (not shared `tenant_id`); ~~MySQL 8~~ → **MariaDB 11.8** (amended — see D22). *Why:* these are what the repo actually is; physical isolation removes any need for Postgres RLS. Ledger tables live in the tenant DB with no `tenant_id` columns.

**D1 — Ledger code lives in `App\Domain\Ledger\`** (plain namespace), not an nwidart module. *Why:* `config/modules.php` points at root `Modules/` while composer merges `app/Modules/*`, and no module exists — a broken tooling state. Revisit modules for later phases (Invoicing, Reports, BIR) only after fixing that mismatch. Tenant migrations go in `database/migrations/tenant/`.

**D2 — Post in the tenant's registered basis (accrual OR cash), per-tenant setting.** *Why:* BIR examines the books themselves; basis is registration-like (fixed per tenant); always-accrual-then-derive concentrates re-recognition bugs. Cash-basis A/R & A/P aging comes from the subledger. Switching basis is a deliberate restatement event. (See `02` §2.)

**D3 — Money is `BIGINT` centavos, never float/DECIMAL in the ledger.** *Why:* PHP float/string hazard; exact integer arithmetic end-to-end. (This is a primary reason we did not adopt `eloquent-ifrs`.)

**D4 — Build our own core; packages are reference-only; Akaunting rejected.** *Why:* the differentiator (BIR) touches the core deeply and no package provides it; the strongest candidate (`ekmungai/eloquent-ifrs`, MIT) stores money as floats and uses a shared-DB multi-entity model. `scottlaurent/accounting` (MIT) is a lighter reference. **Akaunting is rejected as a base** — BSL 1.1 forbids offering it "as a commercial accounting service to third parties" (later GPLv3). Resolves original handoff open-question §6.5: do not derive schema/logic from Akaunting.

**D5 — Integrity = CHECK + app `PostingService` + triggers + nightly reconciliation.** *Why:* MariaDB/MySQL have no deferred constraints, so the balance invariant binds to the `draft→posted` flip; the app is the primary enforcer, triggers are the net, `ledger:verify` catches drift/tampering. (See `01` §2.)

**D6 — Gapless numbering via `document_sequences` + `FOR UPDATE` inside the posting transaction.** *Why:* AUTO_INCREMENT isn't gapless; BIR requires gapless, sequential, non-reusable numbers; voids retain their number; migration continues the series. (See `01` §3, `03`.)

**D7 — Balances derived, cached in `account_period_balances`, rebuildable.** *Why:* O(n) full-history sums don't scale; the cache is provably final for closed periods and rebuildable from `journal_lines`, so it's never treated as truth. (See `01` §4.)

**D8 — Product scope v1 (amended 2026-07-21).** ~~Inventory/COGS out of scope~~ → **Inventory is IN scope** (user decision — see D9–D12 and spec `08-inventory-module.md`). Bank feeds deferred to v2 (manual reconciliation v1, assigned to Phase 3); single currency PHP. Explicitly **out of scope** (write these into customer-facing docs too): payroll (no 1601-C/2316/SSS/PhilHealth/Pag-IBIG), income-tax returns (1701/1702 series — the books feed a CPA; the 8% election flag is captured but no ITR is filed), historical transaction migration (opening balances + serial continuation only; the prior system remains the record for its years), POS/eSales integration, multi-currency. Explicit **v2 backlog:** quotes/estimates, purchase orders (note: a printed PO is a BIR supplementary doc needing a registered series — `document_class`/sequences already accommodate), recurring invoices/bills, dunning/payment reminders, payment links (PayMongo/GCash/Maya), bank-statement CSV import then feeds, fixed-asset register + depreciation schedules, Statement of Cash Flows (unless pulled into Phase 3), department/project reporting (line dimensions already reserved), budgets, customer/vendor portals, per-location average costing.

**D9 — Inventory costing: moving weighted average** (user, 2026-07-21). Lot actual costs still captured for traceability; COGS at moving average. Avoids the ninetails bifurcation (last-cost valuation + unused lot costs).

**D10 — COGS timing: perpetual.** Dr COGS / Cr Inventory on every sale at average cost; `inventory:verify` ties subledger to GL nightly.

**D11 — Inventory locations: multi-location v1.** `location_id` on all movements + transfer documents; one default location seeded.

**D12 — Lots/expiry: v1**, per-item `none|lot` toggle, FEFO suggestions with server-side re-validation, idempotent lot reversal (pattern lifted from ninetails `InventoryLotService`/`LotAllocationService`).

**D13 — Raise PHP floor to `^8.3` in Phase 0** (pending VPS verification) — unlocks current openspout/endroid/infection lines; dev box already on 8.3. See `09-implementation-kit.md` §H.

**D14–D17 — Inventory open decisions:** global vs per-location average cost (v1: global); GRNI clearing vs receive-creates-bill (default: GRNI); found-stock credit account (CPA); negative-stock policy block vs warn (default: block).

**D18 — Timezone: Asia/Manila** app-wide (single-market product). `entry_date`, period boundaries, `posting_lock_date` comparisons, and audit partitions are Manila dates — currently `config/app.php` is UTC; fix in Phase 0 or close-day boundaries produce off-by-one-day bugs.

**D19 — Encryption at rest:** MariaDB data-at-rest encryption (InnoDB + keyring plugin) + encrypted backups, NOT Laravel `encrypted` casts on TIN columns (they break `.dat` exporters, joins, per-tenant dumps). APP_KEY rotation + escrow documented. See `10-operations.md` §5.

**D20 — `users` live in the tenant DB** (roles are per-tenant); provisioning seeds an initial `owner` **and a `system` user** whose id satisfies `created_by`/`posted_by` for non-interactive postings.

**D21 — Packages/tooling** per `09-implementation-kit.md`: spatie/laravel-pdf(+Browsershot), openspout, league/csv, endroid/qr-code `^6.0`, sentry-laravel; dev: larastan, infection (Ledger-scoped), snapshot-assertions. BIR `.DAT` writers are hand-built with golden-file tests (no package exists — verified). mpdf rejected (GPL vs SINGLE_TENANT distribution).

**D22 — Database engine: MariaDB 11.8** (user, 2026-07-21 — amends the earlier "MySQL 8" choice after environment verification). The dev box runs MariaDB 11.8 as its only DB service (same as the developer's ninetails projects); no MySQL 8 is installed. All spec-01 mechanisms work identically on MariaDB (enforced CHECK constraints since 10.2, `SIGNAL` triggers, `SELECT ... FOR UPDATE`, partitioning with the same unique-key rule). Consequences: Laravel **`mariadb` driver** everywhere (note: `SwitchTenantDatabaseTask`/`TenantController` currently clone the **`mysql`** connection template — switch to `mariadb` in the Phase-0 provisioning rework); version floor **MariaDB ≥ 10.5**; CI service image **`mariadb:11.8`**; VPS installs MariaDB; backups use `mariadb-dump`; encryption at rest via MariaDB data-at-rest encryption (D19).

**D23 — Statement of Cash Flows: DEFERRED to v2, explicitly** (2026-07-22, Phase 3). Spec `00`/the phase plan allowed "Statement of Cash Flows **or** explicit v2 deferral"; this is the deferral, with reasons, so nobody re-litigates it later.

*Why not now.* A cash flow statement is not another view of the trial balance the way the balance sheet and P&L are — it needs information the ledger does not yet carry:

1. **Activity classification.** Every account must be tagged operating / investing / financing. That mapping is a judgement call per chart of accounts (is a director's loan financing or operating?), and getting it wrong produces a statement that foots correctly and says something false. `accounts.bir_fs_line` exists as a placeholder but is unpopulated and unvalidated.
2. **The indirect method needs non-cash detail** the ledger does not distinguish today: depreciation is visible (contra-asset movement), but gains/losses on disposal, provisions and FX are not separable from ordinary movement without transaction-level tagging.
3. **Working-capital movements need opening balances per classified account across a range**, which the balance cache supports, but only once (1) exists.
4. **It is not a BIR filing requirement for the SME segment.** The 2550Q/1601EQ/1604E/SLSP set (Phase 4) is what actually blocks go-live; PFRS for SMEs requires a cash flow statement in a full annual financial-statement package, which is an accountant's deliverable and is out of scope per D8 (payroll/income-tax returns already excluded).

*What we ship instead in Phase 3.* Cash movement is fully visible and provably correct through the General Ledger on each cash account (with its own opening/closing tie-out), the bank reconciliation, and the dashboard's cash position. Those answer "where did the cash go" without asserting a classification we cannot yet justify.

*Preconditions for v2.* (a) an `activity_class` column on `accounts` (operating/investing/financing) seeded per chart and **CPA-reviewed** — added to the sign-off list below; (b) a decision on direct vs indirect method (indirect is conventional for SMEs and cheaper here); (c) disposal/provision transaction tagging in the posting rules. Until all three exist, the correct behaviour is to render no cash flow statement at all rather than a plausible wrong one.

## Design corrections captured this session
- **Audit-log PK must be `(id, occurred_at)`** (partition column in every unique key), else the partitioned-table DDL fails. (`01` §6.)
- **Retention is 5 years statutory (RR 7-2024)**, conflicting with 10 years in older issuances — engineer conservatively (10 yr) with a legal-hold flag. (See open items.)
- **EOPT: the "Invoice" is the single principal VAT document (goods + services); OR is supplementary** — dropped the old Sales-Invoice-vs-Official-Receipt split; use a `document_class` field. (`03`.)
- The "RR 9-2009 prima-facie line-delete" claim was **wrong**; the append-only rule is grounded in **RMC 5-2021 Annex B item 10**.

## Accountant / CPA sign-off list (blocking for Phase 4; do not ship on Claude output alone)

> **The list below is packaged for an accountant in [`../cpa-briefing.md`](../cpa-briefing.md)** — each item restated in the accountant's terms with the drafted treatment, its citation, and what changes in the software depending on the answer. Send that document, not this one. Answers come back keyed to its Q-numbers; record them here as they land.

- **Cash-flow activity classification** (D23): the operating/investing/financing tag for every account in the chart, before any Statement of Cash Flows is built.
- Chart-of-accounts structure + BIR field mappings (`bir_tax_type`, `bir_atc_code`, `bir_fs_line`) and the full ATC/alphalist code set.
- Document book codes + number formats/starting series vs the BIR-registered CAS/AC series.
- VAT recognition timing (Output vs Deferred-Output VAT on services; Input-VAT timing on the cash-basis vendor path).
- Withholding: exact ATC↔rate table, sworn-declaration handling, TWA gating.
- Year-end close mechanics: Income Summary vs direct-to-Retained-Earnings; drawings vs dividends; book-vs-tax (MCIT, NOLCO).
- Opening Balance Equity clearing procedure + cutover date.
- Cash-basis vs accrual posting-rule differences per document type.
- Whether the trigger + DB-privilege + hash-chain controls satisfy a PH CAS audit-trail review.
- Retention: 5 vs 10 years (which is the operative CAS obligation).
- The `docs/fixtures/` scenarios (they encode tax treatment).
- **Advance/deposit VAT timing under EOPT** (customer deposits before invoice — services-accrual question).
- **Credit-note VAT/serial treatment** and its 2550Q/SLSP feed.
- **Cash-basis EWT timing divergence**: RR 4-2024 accrues EWT at booking, but the cash-basis GL defers to payment — the 0619E/1601EQ return data must pick up booking-date withholding even for cash-basis tenants; reconcile GL-vs-return treatment.
- **Inventory:** weighted-average declaration/consistency to BIR; spoilage deductibility documentation (notice/inspection requirements); found-stock credit account (D16).
- **DPA counsel review:** the "required by law" reasoning that resolves DPA minimization vs BIR retention (see `10-operations.md` §4).

See the full "Open items needing a PH CPA / tax lawyer" list in [`03-bir-accreditation.md`](03-bir-accreditation.md).

## Open build decisions (confirm during implementation)
- **D1 revisit:** stay with `app/Domain/Ledger` or migrate to a fixed nwidart module for later phases.
- **One entry per document** sufficient for v1, or add a `document_postings` link table (needed if one document must yield multiple entries).
- **Monthly close default:** soft (`closed`, admin-reopenable) vs hard (`locked`).
- **`bookkeeper` posting rights:** post-but-not-close vs draft-only (default draft-only, `04`).
- **Reversal dating policy:** original-date-if-open vs always-current-period.
