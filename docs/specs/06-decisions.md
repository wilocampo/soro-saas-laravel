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

## D24–D42 — CPA sign-off: research-draft answers recorded (2026-07-24)

All 23 questions in [`../cpa-briefing.md`](../cpa-briefing.md) have **draft answers** in [`../cpa-briefing-draft-answers.md`](../cpa-briefing-draft-answers.md). **Read that document's status line before treating any of this as settled:** the answers are AI-assisted research, *not* a licensed Philippine CPA's sign-off, and its §4 lists items a practitioner must still decide (the seeded ATC table, the chart review, the mid-year ₱3M catch-up rule, SLSP credit-note presentation, the RMO 21-2020 notice window, and counsel's erasure nod). We build against these drafts because they are well-sourced and let the reviewer *confirm rather than compose* — but nothing below is "CPA-confirmed", and code/comments must not claim it is.

**These decisions supersede the earlier drafted treatments; where a decision contradicts an earlier ADR or spec section, this section wins.** Below is only what the build must do about each.

**D24 — VAT rounding: confirmed as built.** Per-line, half-up, VAT-inclusive prices back out the net so VAT is the remainder. No issuance prescribes a method — **consistency is the audited property**, so the policy must be identical on screen, in the posting and in the return. `Money::taxOf()` is the single implementation; anything computing VAT elsewhere is a defect.

**D25 — ATC table.** The seven drafted payment types are answered; the full table still loads from the **eBIRForms ATC library** (not yet supplied), and no rate may be invented meanwhile (`TaxResolver::atcRateBp()` throws). WC157/WI157 and WC640/WI640 stay reserved for government payments. **Commissions took two corrections:** the codes are **WI515 / WC515** (not WI139/WI140, WC139/WC140), and they are a **flat 10% pair** narrowly scoped to brokers/agents and entertainer agents — the first fix wrongly seeded WI515 at 5% with the professional-fee sworn-declaration logic. **Generic** non-employee commissions are professional fees → WI010/WI011. Both the code set and this scope are on the reviewer's confirm list (`draft-answers §2 Q2, §4`).

**D26 — Sworn declarations: prospective, never retroactive.** Absent or expired ⇒ the higher rate, from that date forward. Good-faith reliance on the declaration on file is the design of RR 11-2018; documents already posted are not re-rated.

**D27 — TWA effective dating.** The obligation begins on the **1st of the month following publication**, and de-listing likewise takes effect by publication ⇒ `company_profile` needs a **`twa_effective_to`** to pair with `twa_effective_from`. TWA rates apply iff `date >= from AND (to IS NULL OR date <= to)`. Status remains conferred, never computed.

**D28 — Output VAT is invoice-basis by law for goods AND services (RR 3-2024), independent of the tenant's book basis — and FQ1 is resolved.** The follow-up question ("what balances a cash-basis output-VAT line at invoice?") had a false premise. The draft answer (Q5, Q18, Q22 finding #6) is that **the cash-basis GL does NOT book output VAT at invoice at all** — it recognises it at collection, as cash-basis books should — while the **2550Q, sales journal and SLSP are generated from the invoice subledger by invoice date**, never from the GL. The two legitimately diverge by the credit period and reconcile over time.

Consequences for the build:
- **The shipped posting rule is CORRECT, not defective.** `SalesInvoicePostingRule` returning an empty cash-basis draft and `PaymentPostingRule` recognising output VAT at collection is the right GL behaviour. My earlier "shipped defect / recommend prohibit cash+VAT" note (and FQ1's three candidate entries) is withdrawn — none is needed. **cash + VAT-registered is a PERMITTED combination** (only cash + inventory is barred, D38).
- **The risk moves to the not-yet-built 2550Q generator**, which must read the invoice subledger by invoice date. This is now a hard invariant recorded in [`../fixtures/scenarios.md`](../fixtures/scenarios.md) (S3/S4) so an implementer cannot wire the return to the GL and file every cash-basis client's VAT a quarter late.
- **A VAT-vs-GL reconciliation report** ships for cash-basis VAT tenants (the analogue of D29's withholding reconciliation), and onboarding **advises** — does not force — accrual for VAT-registered tenants, since the divergence is permanent though explainable.
- Two data items still to build (Phase 4 / onboarding, not blocking): the **transitional** rule for pre-EOPT service receivables billed-but-uncollected (an onboarding note — those carry residual collection-basis VAT outside Soro), and the EOPT **uncollected-receivables output-VAT credit** (RR 3-2024 §4.110-9) — capture per-invoice agreed-credit-period, claimed-credit flag and recovery add-back now even though the return line ships later.

**D29 — Withholding follows the payable date, not the book basis, and is extracted from the SUBLEDGER.** RR 4-2024's test is when the liability becomes **due, demandable or legally enforceable**. The 0619-E / 1601-EQ / QAP / 1604-E extract reads the **vendor-bills subledger**, never the GL — so a cash-basis tenant's returns are right even though its ledger recognises the expense later. A GL-vs-return reconciliation ships alongside.

**D30 — Customer money before invoicing splits into two flows.** A **security deposit** is a liability with no VAT and no invoice. An **advance payment** is invoiced on receipt and carries output VAT. These are different documents with different accounts; the distinction is the user's to declare, and the UI must make it explicit rather than inferring it.

**D31 — Credit notes hit the CURRENT period** (Sec. 106(D)) — no amended 2550Q machinery is needed. The credit note keeps its own registered series as a supplementary document, appears as a **deduction** on the 2550Q, and as an **adjustment** in the SLSP.

**D32 — Percentage tax is in scope: 2551Q.** Percentage-tax tenants file 2551Q; **8% electors file none** (the 8% is in lieu of percentage tax) — so the return set is driven by the date-effective `tax_regimes` row, not by the VAT flag alone. Every non-VAT invoice must carry the **"NOT VALID FOR CLAIM OF INPUT TAX"** legend.

**D33 — Retention: ten years stays, as policy.** The legal floor is five (RR 7-2024); the pending-case extension is confirmed and our legal-hold flag matches the rule. Nothing changes.

**D34 — Four additions to the CAS control set** (`03` §2, beyond what shipped): a **user-access/activity log** (logins, failures, permission changes — distinct from and not necessarily hash-chained like the transaction audit log, but append-only and reportable), a **printable audit-trail report** (filterable by user/date/document, carrying the mandatory header block — not just read access to a table), a **registration documentation package** generator for the AC submission (system description, process flow, sworn statement of books/reports/serial ranges), and a **documented backup/restore procedure** (the capability exists per spec 10; the client-facing one-pager does not). Clarification for the reviewer's copy: a **demo/sandbox tenant** for BIR evaluation or training is a distinct empty database, **not** a "training mode" suppression feature — say so explicitly, because "no training mode" can confuse an examiner who expects to be given a demo environment.

**D35 — The ACCN prints on the face of issued documents** (required). Separately: **"gapless" is our word, not BIR's** — the actual rule is sequential, unique and non-reusable. Keep the behaviour exactly as built; remove the word from validation messages and customer-facing copy.

**D36 — Chart of accounts corrections.** Split **withholding tax payable by type** (rather than one 2150); split **Input VAT into sub-accounts that map 1:1 onto the 2550Q lines**; add **Percentage Tax Payable**. No Deferred Input VAT account is needed — capital-goods input-VAT amortisation ended for purchases after 2021. (The reviewed chart itself, and the D23 activity classification, arrive separately.)

**D37 — Cutover: fiscal-year start strongly preferred**, any period start permitted with an explicit warning the user must acknowledge. OBE-as-plug is confirmed standard.

**D38 — The cash basis is narrower than we assumed.** It is legitimate only for a genuine cash-basis **service** business. Two hard consequences: **VAT remains invoice-basis regardless of book basis** (see D28), and **an inventory-carrying tenant cannot be on the cash basis** — enforce at settings level.
⚠️ This retires the Phase-2b **Deferred COGS** path (account 1450, `CostOfSales::lines(..., deferred: true)`), which existed solely to serve cash-basis inventory clients. The guard is the fix; the branch is dead code pending removal.

**D39 — Inventory costing and spoilage.** Weighted average is fine, is **disclosed in the FS/ITR**, and **a change of method requires prior BIR consent** ⇒ the costing method locks at go-live and may only change against a recorded consent reference. Spoilage/shrinkage is deductible only through the **RMO 21-2020 destruction process** ⇒ a write-off needs a destruction record (application, schedule, BIR witness / certificate of deduction) before it counts as deductible, and the shrinkage report must separate documented from undocumented losses.

**D40 — Cash flow (v2): indirect method.** Confirms D23's assumption. The per-account operating/investing/financing classification arrives with the chart review.

**D41 — DPA ground confirmed: Sec. 12(c), processing necessary for compliance with a legal obligation.** Erasure is not absolute and is refusable for records the BIR mandates we keep. Counsel to confirm one boundary: the refusal covers data *forming part of the mandated records* only — personal data held outside them (marketing lists, former-user portal accounts) still honours erasure, and once retention lapses without a hold the DPA *requires* disposal. Final customer-facing wording still routes to counsel.

**D42 — Year-end close is entity-type-aware in naming only.** Income Summary → Retained Earnings is confirmed for corporations; the posting rule is unchanged. For a **sole proprietorship** the same mechanics close **Drawings → Owner's Capital** and **Income Summary → Owner's Capital** (not "Retained Earnings"). Make the equity-account *names* entity-type-aware in the chart seed; book-tax differences (MCIT, NOLCO) stay outside the books as ITR working-paper items.

**Q15 stays flagged, nothing load-bearing.** The threshold likely sits at Sec. 109(CC) post-CREATE and Sec. 110(D) is plausibly the EOPT uncollected-receivables insert — to be confirmed against the consolidated text. No computation depends on either cite.

### Still outstanding after this round
1. **The eBIRForms ATC library** (D25) — blocks the full withholding table; the confirmed rows (incl. the corrected WI515/WC515 at flat 10%) can seed now, but scope/rate is a reviewer-confirm item.
2. **The reviewed chart of accounts + activity classification** (D36, D40) — plus the six structural corrections in `draft-answers §2 Q14` when it lands.
3. **FQ1 is RESOLVED** (D28): cash + VAT-registered is permitted; the GL stays cash-basis and the 2550Q reads the invoice subledger by invoice date. No posting-rule change; the constraint lives in the future return generator and is recorded as a fixture invariant.
4. **Licensed sign-off** on the items in `draft-answers §4` (the seeded ATC table, the chart, the mid-year ₱3M catch-up rule, SLSP credit-note presentation, the RMO 21-2020 notice window, counsel's erasure nod).

---

## Accountant / CPA sign-off list (draft answers recorded 2026-07-24 — retained for traceability)

> Every item below has a **draft answer** in **D24–D42 above** (sourced from [`../cpa-briefing-draft-answers.md`](../cpa-briefing-draft-answers.md), which is research, not a licensed CPA's sign-off). The list is kept so an auditor can trace each question to its answer and so the practitioner can confirm-tick rather than compose. The packaged question set is [`../cpa-briefing.md`](../cpa-briefing.md).

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
