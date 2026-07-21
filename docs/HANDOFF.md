# HANDOFF — Accounting SaaS (working title)

> Purpose: single source of truth for any Claude Code session (or human) picking up this project.
> Location: keep this at `docs/HANDOFF.md`. Pair it with `CLAUDE.md` at repo root and the specs in `docs/specs/`.
> Update this file at the end of every working session. Stale handoffs are worse than none.

## 0. Read this first — spec package index

The decisions below are grounded in a repo inventory and a fact-checked BIR research pass done 2026-07-20/21. The detailed, implementable specs live here:

- [`docs/specs/01-ledger-data-model.md`](specs/01-ledger-data-model.md) — MariaDB 11.8 schema + integrity-enforcement stack.
- [`docs/specs/02-posting-engine.md`](specs/02-posting-engine.md) — `PostingService`, posting rules, accrual/cash, rounding, reversal, worked examples.
- [`docs/specs/03-bir-accreditation.md`](specs/03-bir-accreditation.md) — **the BIR/CAS compliance reference** (citation-backed, adversarially fact-checked). Read before touching tax, invoicing, books, or audit trail.
- [`docs/specs/04-roles-permissions.md`](specs/04-roles-permissions.md) — permission matrix + CAS security rules.
- [`docs/specs/05-testing-strategy.md`](specs/05-testing-strategy.md) — the ledger test gate.
- [`docs/specs/06-decisions.md`](specs/06-decisions.md) — ADRs + **accountant sign-off list** + open build decisions.
- [`docs/specs/07-glossary.md`](specs/07-glossary.md) — double-entry + PH/BIR vocabulary.
- [`docs/specs/08-inventory-module.md`](specs/08-inventory-module.md) — **inventory** (in scope as of 2026-07-21): weighted-average perpetual COGS, multi-location, lots/FEFO, counts & variance, easy receiving UX.
- [`docs/specs/09-implementation-kit.md`](specs/09-implementation-kit.md) — verified package install list, frontend components, `package.json` fixes, PHP-floor decision.
- [`docs/specs/10-operations.md`](specs/10-operations.md) — per-tenant backups (⛔ current config misses tenant DBs), fleet migrations, CI shape, **Data Privacy Act (RA 10173)** obligations, security ops, RPO/RTO, timezone.
- [`docs/specs/11-frontend-components.md`](specs/11-frontend-components.md) — **Sakai design system + reusable component library**: audit of non-Sakai pages (Profile/Settings/Tenants/Notifications/auth), the full build list (tables, form fields, money/TIN inputs, confirm/toast patterns, accounting grids), and the conversion backlog.
- [`docs/fixtures/`](fixtures/) — hand-verifiable posting scenarios (draft for accountant review), incl. month-close + inventory round-trip.

## 1. Product decision (read first)

- **Not** a full QuickBooks Online clone. Scope = double-entry books + invoicing + expenses + **inventory (weighted-average perpetual COGS, variance detection — spec `08`)** + reports for Philippine SMEs, with **BIR compliance as the differentiator**. **Both VAT and NON-VAT taxpayers are first-class** (most PH SMEs are non-VAT: percentage-tax posting rules, non-VAT invoice variant, 2551Q).
- Target reports v1: Trial Balance, Income Statement (P&L), Balance Sheet, General Ledger, General Journal. BIR-oriented outputs (columnar books of accounts, VAT summary, 2307/withholding, SLSP/alphalist `.dat`) are the moat — spec'd in `03-bir-accreditation.md`; **require accountant/CPA review before shipping.**
- Deployment: one codebase, two modes via config:
  - `SINGLE_TENANT=true` → registration disabled, seeded with one tenant (per-client VPS install)
  - default → open SaaS with tenant signup + billing (`laravel/cashier` already installed)

## 2. Architecture decisions (locked — corrected against the actual codebase)

> These were re-derived from a real repo inventory. The earlier version of this file stated several things the code contradicts; those are corrected here.

- **Framework:** **Laravel 12** (PHP 8.2+) — the repo is on `laravel/framework: ^12.0`. There is **no L13 upgrade task**; build on 12. **MariaDB 11.8** (`mariadb` driver; ≥ 10.5 floor for enforced CHECKs — verified on dev 2026-07-21; install MariaDB on target VPS).
- **Tenancy:** **database-per-tenant** via `spatie/laravel-multitenancy` (subdomain finder + connection-swap in `app/Multitenancy/SwitchTenantDatabaseTask.php`). Physical isolation. **Ledger tables live in each tenant DB; no `tenant_id` columns on ledger tables.** The `users.tenant_id` column is vestigial shared-DB scaffolding — remove or ignore for ledger work. (This corrects the old "shared tables + tenant_id / Postgres RLS" note — RLS is unnecessary given physical isolation.)
- **Ledger-first core (non-negotiable):**
  - `journal_entries` (header) + `journal_lines` (debit/credit lines) are the source of truth.
  - Every business document (invoice, bill, payment, adjustment) POSTS to the journal via a single `PostingService`; it never IS the balance.
  - Account balances are always **derived** (sum of lines). Materialized `account_period_balances` is an **invalidatable, rebuildable cache**, never mutable truth.
  - Posted entries are **immutable**. Corrections = reversing entries. Documents are **voided, never edited/hard-deleted** (BIR rule — RMC 5-2021 Annex B item 10).
  - Period close: lock date per tenant; posting on/before the lock date, or into a closed period, is rejected.
  - Every entry must balance (Σdebits = Σcredits) — enforced at DB (per-row CHECK + trigger on the `draft→posted` flip) AND app level. See `01`.
- **Chart of accounts:** per-tenant, seeded from a PH SME default template. Types: Asset / Liability / Equity / Income / Expense (+ contra flag, parent/sub hierarchy).
- **Money:** **integer minor units (centavos, `BIGINT`)** — never floats/DECIMAL in the ledger. Single currency (PHP) for v1; multi-currency out of scope.
- **Build our own core.** Do **not** adopt a package as the ledger dependency. `ekmungai/eloquent-ifrs` (MIT) and `scottlaurent/accounting` (MIT) are **reference implementations only** (eloquent-ifrs stores money as floats and its multi-entity model is shared-DB — both wrong for us). **Do not derive from Akaunting** — its BSL 1.1 license forbids offering it as a commercial accounting service. (Resolves old open-question §6.5.) See `06-decisions.md`.

## 3. Repo status

- This repo is a **near-stock Laravel 12 skeleton** with: `spatie/laravel-multitenancy` (DB-per-tenant, subdomain), `spatie/laravel-permission` (teams OFF), Breeze auth (open registration), `laravel/cashier` (installed, unused), Inertia + Vue 3 + PrimeVue (Sakai), `nwidart/laravel-modules` (installed, no module exists), Telescope, media-library, backup. **No accounting domain exists yet.**
- **Known base-repo debt to fix in Phase 0** (see `06-decisions.md`): two divergent `tenants` migrations (`database/migrations/` vs `database/migrations/landlord/`); newly provisioned tenant DBs are **never seeded** (no roles/admin) and `DatabaseSeeder` never calls `RolePermissionSeeder`; tenant DBs are migrated with the *landlord* set (no `--path`) → need a dedicated `database/migrations/tenant/` path; `nwidart` config path (`Modules/`) ≠ composer merge path (`app/Modules/*`); no `SINGLE_TENANT` concept; Inertia client v1 vs server v2; case-variant `components/`+`Components/`, `layout/`+`Layouts/`.
- **Reusable:** DB-per-tenant isolation, subdomain routing, Breeze auth, roles/permissions, and `CRUDDataTable`/`CRUDForm`/`CRUDField`/`CRUDModal` Vue components for accounting UI.

## 4. Phase plan (exit criteria per phase)

- **Phase 0 — Foundation & cleanup:** fix the base-repo debt; dedicated tenant migration path; provisioning saga (seed roles + admin + `system` user, per-DB grants, failure compensation); `SINGLE_TENANT` mode; Money value object; **fix `package.json` conflicts (Inertia v1/v2, Tailwind v3/v4)**; **PHP `^8.3` floor decision (D13)**; **timezone → Asia/Manila (D18)**; **CI up (pint + larastan + MariaDB-11.8 Ledger suite — spec `10` §3)**; **tenant-aware queue test**; **per-tenant backup orchestration (spec `10` §1 — current config misses tenant DBs)**; verify MySQL ≥ 8.0.16. *Exit: a freshly provisioned tenant DB is fully usable; landlord/tenant schemas cleanly separated; SINGLE_TENANT works; CI green incl. Ledger suite; a tenant backup restores clean with triggers intact.*
- **Phase 1 — Ledger core:** CoA, journal entries/lines, `PostingService`, enforcement stack, gapless numbering (per-branch series), fiscal periods + lock (+ period-13 adjustment period), opening balances, trial balance, `tax_codes`/`atc_rates`/`company_profile`/`tax_regimes`/`account_roles` tables, `ledger:verify`/`ledger:rebuild-balances`, password-rotation job. *Exit: generative tests prove the trial balance always balances under random valid postings; unbalanced/edited/pre-lock/duplicate postings are rejected; audit-log hash chain verifies.*
- **Phase 2 — Documents:** customers/vendors (+ TIN/branch, sworn-declaration validity, CSV import incl. CoA + open documents), invoices, bills, payments **with allocation model (partials, over-payments, advances/deposits)**, expenses (+ receipt attachments via medialibrary), **credit/debit memos + the cancellation flow (void in-period, credit note after filing)**, **non-VAT posting rules + invoice variant**, **BIR-compliant invoice + supplementary-doc PDF templates (11 mandatory fields, "not valid for input tax" marker) + email delivery** → each posts via a `PostingRule` (accrual/cash templates); A/R & A/P aging from the subledger. *Exit: worked fixtures post correct debits/credits in both bases and both regimes; reversal unwinds documents cleanly; an issued invoice renders a compliant PDF.*
- **Phase 2b — Inventory (spec `08`):** items/UoM/barcodes/locations, receiving (+GRNI), perpetual weighted-average COGS, transfers, counts + variance workflow, lots/FEFO/expiry, `inventory:verify`. *Exit: receive→sell→count round-trip posts correct JEs; subledger ties to GL to the centavo; count variance yields an immutable approved adjustment.*
- **Phase 3 — Reports:** P&L, Balance Sheet, GL, GJ, **customer SOA, manual bank reconciliation, dashboard-lite (cash, AR/AP overdue, P&L snapshot, upcoming deadlines)**; PDF/XLSX (spatie-pdf/openspout); Statement of Cash Flows or explicit v2 deferral. *Exit: reports tie to the trial balance to the centavo; year-end close yields a correct post-closing balance sheet.*
- **Phase 4 — BIR layer (accountant-gated):** VAT (2550Q) **+ percentage tax (2551Q)**, withholding/2307 + QAP/alphalist, columnar books of accounts (incl. **Inventory Book** + annual Inventory List), EOPT invoice serial rules, SLSP/alphalist `.dat` (golden-file tested), ACCN + mandatory report header/footer, **compliance calendar with deadline reminders**, registration-pack generator; **EIS transmission: named deferral with the 31-Dec-2026 mandate risk stated, emitter interface stubbed**. **Do not ship on Claude output alone; CPA sign-off required.**
- **Phase 5 — SaaS shell:** wire the existing `cashier` billing, tenant onboarding (**ACCN gate + NPCRS/DPA registration gate** + SINGLE_TENANT), offboarding export bundle, production hardening checklist (spec `10` §7), single-tenant deploy (Docker Compose incl. Chromium for PDF).

## 5. Testing policy

- The ledger core gets the strictest tests in the codebase (PHPUnit + a generative harness). See `05-testing-strategy.md`.
- **Gate:** any PR touching `journal_*` or `PostingService` requires the full ledger suite green.

## 6. Open questions — resolved + remaining

Resolved (see `06-decisions.md`):
- Accrual **and** cash basis, as a **per-tenant setting** (not per-transaction); basis-selective posting.
- **Inventory IN scope (2026-07-21, reverses the earlier call):** moving weighted average, perpetual COGS, multi-location, lots/FEFO v1 (D9–D12, spec `08`).
- Bank feeds **v2**; manual reconciliation v1 (Phase 3). Payroll + income-tax returns explicitly out of scope (D8).
- License: build our own core; Akaunting rejected (BSL); eloquent-ifrs/scottlaurent reference-only. Packages per `09` (D21).
- Timezone Asia/Manila (D18); PHP `^8.3` floor pending VPS check (D13); TDE for encryption at rest (D19); users + `system` actor per tenant DB (D20).

Remaining (need a PH CPA/tax lawyer — see `06` and the "Open items" in `03-bir-accreditation.md`):
- Retention 5 vs 10 years (RR 7-2024 vs older issuances); VAT rounding method; exact ATC↔rate table; year-end close mechanics (Income Summary vs direct-to-RE, drawings/dividends, MCIT/NOLCO); who reviews Phase-1 fixtures and Phase-4 outputs.

## 7. Session log

- 2026-07-20 — Initial handoff created from planning discussion.
- 2026-07-21 — Repo inventory done (§3 corrected: L12, DB-per-tenant, cashier present). Full spec package produced (`docs/specs/01-07`, `CLAUDE.md`, fixtures). Build-vs-buy resolved (own core; reference eloquent-ifrs/scottlaurent; reject Akaunting). BIR/CAS compliance researched + fact-checked → `03-bir-accreditation.md`. Key corrections: retention 5-yr (conflict flagged), EOPT "Invoice" is the single principal VAT doc (OR supplementary), append-only grounded in RMC 5-2021 not RR 9-2009.
- 2026-07-21 (later 2) — Frontend audit vs Sakai (`primefaces/sakai-vue` structure verified): shell is correctly Sakai but Profile/Settings/Tenants/Notifications + 4 auth pages are Breeze/Tailwind; CRUD components carry PrimeFlex/v3 legacy classes; Confirm/Toast services registered but unused; two component roots. → `11-frontend-components.md` (conventions, component build list incl. accounting grids, conversion backlog; kit + Users/layout fixes pulled into Phase 0 exit criteria).
- 2026-07-21 (later) — Four-lens gap review (packages / product / operations / consistency) + ninetails inventory prior-art mining. **Inventory brought into scope** (D9–D12) → spec `08`. Added `09-implementation-kit.md` (verified packages; no BIR `.dat` package exists — hand-build; mpdf rejected GPL) and `10-operations.md` (per-tenant backups ⛔, fleet migrations, CI, **RA 10173/DPA obligations + NPCRS gate**, security, RPO/RTO, Asia/Manila). Fixed 4 spec blockers (missing `tax_codes`/profile tables → `01` §7; entry-number format mandate; T4 vs `reverse()`; period-13 close deadlock) + the S4 ATC/rate mismatch (now 2% WC160). Product gaps folded into phases: credit notes, payment allocations, **non-VAT/2551Q as first-class**, compliant invoice PDF in Phase 2, SOA/recon/dashboard in Phase 3, compliance calendar + EIS deferral note in Phase 4. Next action: begin Phase 0.
