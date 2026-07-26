# ============================================================================
# AGENT EXECUTION & TOKEN OPTIMIZATION POLICY
# ============================================================================

## Primary Objective

Produce the highest-quality implementation while minimizing unnecessary token
usage.

Optimize by reducing duplicate work, repeated repository exploration, and
unnecessary delegation—not by sacrificing correctness.

Quality always takes priority over saving tokens.

---

## Repository Understanding

Build repository understanding progressively.

Start from the most relevant files.

Expand investigation only when additional context is required to make a correct
implementation.

Do NOT perform full repository scans unless explicitly requested.

Avoid reopening files that have already been analyzed.

Treat previous discoveries as authoritative unless new evidence contradicts them.

---

## Context Management

Provide complete context when it materially improves correctness.

Do NOT intentionally starve the model of context.

Instead:

- send only relevant context
- avoid unrelated files
- avoid duplicate context
- avoid repeated repository indexing

Favor context quality over context quantity.

---

## Subagent Policy

The main agent owns the task.

Do not delegate work simply because delegation is available.

Spawn a subagent only when at least one of these is true:

- large repository-wide search
- independent research
- architecture investigation
- security audit
- dependency analysis
- parallel work that saves significant time

Do NOT spawn subagents for:

- CRUD implementation
- Laravel controllers
- Services
- Models
- Policies
- Requests
- Migrations
- Seeders
- Vue components
- Blade templates
- CSS
- HTML
- Tailwind
- TypeScript fixes
- JavaScript fixes
- API implementation
- Unit tests
- Feature tests
- Small refactors
- Documentation
- Prompt writing

Prefer one well-informed agent over many specialized agents.

Avoid recursive delegation.

Maximum concurrent subagents: 1 unless parallel execution provides clear value.

---

## Investigation Strategy

Never scan the repository from the top.

Investigate incrementally.

Typical Laravel flow:

Routes
→ Controller
→ Service
→ Model
→ Validation
→ Related Vue component
→ Shared component only if needed

Stop investigation once sufficient context has been gathered.

---

## File Reading

Before opening a file ask:

"Will this file likely affect the implementation?"

If no:

Do not open it.

If uncertain:

Open the smallest relevant file first.

Never repeatedly read the same file unless it has changed.

---

## Reasoning Depth

Use reasoning proportional to task complexity.

Low reasoning:

- UI tweaks
- CSS
- HTML
- Vue template updates
- CRUD
- Validation
- Small bug fixes
- Refactoring
- Eloquent queries

Medium reasoning:

- New features
- Database changes
- API design
- Authentication
- State management

High reasoning:

- Architecture
- Concurrency
- Performance
- Security
- Complex debugging
- Distributed systems
- Breaking changes

Avoid deep reasoning for simple edits.

---

## Code Changes

Prefer modifying existing code over rewriting entire files.

Keep diffs focused.

Avoid unrelated cleanup.

Avoid unnecessary formatting changes.

Preserve existing project conventions.

---

## Reuse Existing Knowledge

Do not rediscover information already known.

Reuse:

- architecture findings
- coding conventions
- route structure
- database relationships
- framework patterns

Assume previous findings remain valid unless evidence suggests otherwise.

---

## Token Efficiency

Before performing expensive work ask:

- Is this investigation necessary?
- Has this already been analyzed?
- Can existing knowledge answer this?
- Is delegation actually beneficial?

Prefer one comprehensive investigation over many repeated investigations.

Avoid duplicate analysis.

Avoid duplicate explanations.

Avoid repeating summaries.

Avoid explaining obvious code unless requested.

---

## Output Style

Be concise.

Return:

- implementation
- reasoning only when useful
- important assumptions
- risks if applicable

Do not narrate every action.

Do not repeat the prompt.

Do not generate unnecessary implementation commentary.

---

## Working Principle

Think broadly.

Investigate progressively.

Implement precisely.

Reuse knowledge.

Delegate rarely.

Optimize for correctness first and token efficiency second.

# CLAUDE.md — Accounting SaaS (PH SME, BIR-compliant)

Read [`docs/HANDOFF.md`](docs/HANDOFF.md) first (single source of truth) and the specs in [`docs/specs/`](docs/specs/). This file is the quick brief + the rules you must never break.

## What this is
A multi-tenant SaaS **double-entry accounting** system for Philippine SMEs. Differentiator = **BIR compliance**. Build the correct ledger core first; features later. Comparable to QuickBooks in scope, not a clone.

## Stack (verified, not aspirational)
- **Laravel 12**, PHP 8.2+. (No Laravel 13 upgrade — the repo is on `^12.0`.)
- **MariaDB 11.8** (Laravel `mariadb` driver; ≥ 10.5 floor for enforced CHECK constraints — D22; the dev box runs MariaDB 11.8). Dev default is SQLite but anything ledger-related runs on MariaDB.
- **DB-per-tenant** via `spatie/laravel-multitenancy` (subdomain finder, connection swap). **Ledger tables live in the tenant DB; no `tenant_id` columns.**
- Frontend: Inertia + Vue 3 + PrimeVue (Sakai). Reuse `resources/js/components/CRUDDataTable|CRUDForm|CRUDField|CRUDModal.vue`.
- Auth: Breeze. Roles: `spatie/laravel-permission` (teams OFF; roles live per tenant DB).
- Billing: `laravel/cashier` (installed, unused — wire in Phase 5).

## Commands
```bash
composer test                 # = php artisan config:clear && php artisan test  (PHPUnit)
php artisan test --testsuite=Ledger       # the strict suite (MariaDB 11.8; suite added in Phase 1)
php artisan migrate --path=database/migrations/tenant --database=tenant   # tenant-only schema
php artisan ledger:verify                 # nightly: re-derive balances + walk audit hash chain
php artisan ledger:rebuild-balances       # rebuild the derived balance cache from journal_lines
php artisan inventory:verify              # stock cache vs movements + subledger-to-GL tie-out
composer run dev              # serve + queue + vite + pail
npm run dev | npm run build
```

## NON-NEGOTIABLE ledger rules (a PR that breaks any of these is wrong)
1. **Journal is the only source of truth.** `journal_entries` (header) + `journal_lines` (debit/credit). Nothing writes them except `App\Domain\Ledger\PostingService`.
2. **Balances are derived**, never stored as mutable columns. `account_period_balances` is a rebuildable cache only.
3. **Every entry balances**: Σ`debit_centavos` = Σ`credit_centavos`. Enforced at DB (per-row `(debit>0) XOR (credit>0)` CHECK + trigger on the `draft→posted` flip) **and** in `PostingService`.
4. **Posted entries are immutable.** Corrections = reversing entries. Documents are **voided, never edited or hard-deleted** (BIR: RMC 5-2021 Annex B item 10).
5. **Money = `BIGINT` centavos.** Never float, never DECIMAL in the ledger. (This is why we did NOT adopt `eloquent-ifrs` — it uses floats.)
6. **Gapless, sequential document numbers**, allocated inside the posting transaction under `SELECT ... FOR UPDATE`; a rollback consumes no number; voids retain their number; migration continues the series (no reset).
7. **Period lock respected.** Posting on/before `posting_lock_date` or into a closed period is rejected.
8. **Append-only audit log**, hash-chained; app DB user has only `INSERT, SELECT` on `audit_log`. Every create/void writes an audit row in the same transaction.
9. **No suppression features** — no "training mode", hidden delete, or reset-to-zero (NIRC Sec. 264-B).
10. **Inventory mirrors the ledger** ([`docs/specs/08-inventory-module.md`](docs/specs/08-inventory-module.md)): `stock_movements` is append-only, on-hand is derived/cached, every money-effect movement posts through `PostingService`, COGS is perpetual at moving weighted average, and `inventory:verify` must tie the subledger to the GL to the centavo.

See [`docs/specs/01-ledger-data-model.md`](docs/specs/01-ledger-data-model.md) and [`02-posting-engine.md`](docs/specs/02-posting-engine.md) for the how.

## BIR: build to the reference, don't guess
Anything touching tax, invoicing, books, serial numbers, or audit trail must follow [`docs/specs/03-bir-accreditation.md`](docs/specs/03-bir-accreditation.md) (citation-backed). Highlights: the **Invoice** is the single principal VAT document (goods + services); OR is supplementary; VAT is quarterly **2550Q**; EWT accrues at booking date; store the **Acknowledgement Certificate number (ACCN)** and gate go-live on it; mandatory **report header/footer** (software name+version, TIN+branch, user, timestamp). BIR is **not legal advice we can finalize** — items in `06-decisions.md` need a CPA/tax lawyer.

## Conventions
- Ledger code lives in `App\Domain\Ledger\` (plain namespace; not an nwidart module until the `config/modules.php` vs composer merge-path mismatch is fixed — see `06`). Inventory in `App\Domain\Inventory\`.
- Packages: install only what [`docs/specs/09-implementation-kit.md`](docs/specs/09-implementation-kit.md) lists (verified L12/license/maintenance). Notables: spatie/laravel-pdf for BIR-faithful PDFs (mpdf is GPL — rejected), openspout for books export, hand-built BIR `.dat` writers with golden-file tests. Timezone is **Asia/Manila** (D18).
- Ops obligations (backups per tenant, DPA/NPCRS, CI shape): [`docs/specs/10-operations.md`](docs/specs/10-operations.md).
- Tenant migrations in `database/migrations/tenant/`; landlord migrations stay in `database/migrations/`.
- Follow existing CRUD patterns: resource controllers returning `Inertia::render` with `->paginate()` props; pages under `resources/js/Pages/<Resource>/` wrapped in `AuthenticatedLayout`.
- **Frontend follows the Sakai design system** — [`docs/specs/11-frontend-components.md`](docs/specs/11-frontend-components.md) is the law: PrimeVue **v4 names** (`Select`/`DatePicker`, never `Dropdown`/`Calendar`), no PrimeFlex/v3 classes, theme tokens only (no hardcoded hex/Tailwind color badges), `.card` containers, one confirm (`useConfirm`) + one toast pattern, money crosses the wire as centavos via `MoneyInput`/`MoneyText`, single lowercase `components/` root (Breeze `Components/` is being retired). Design *intent* (who the users are, the trustworthy/precise personality, the aesthetic principles) lives in [`.impeccable.md`](.impeccable.md) — spec 11 is the "how", that file is the "why". Never ship fabricated/demo data in the UI.
- Reference-only packages: `ekmungai/eloquent-ifrs`, `scottlaurent/accounting`. **Never** derive from Akaunting (BSL license).

## Testing gate
Any PR touching `journal_*` or `PostingService` must run the **full ledger suite green** ([`docs/specs/05-testing-strategy.md`](docs/specs/05-testing-strategy.md)): the balancing invariant (generative), immutability, gapless numbering, period lock, reversal-nets-to-zero, and the hand-verified fixtures.
