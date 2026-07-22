# Soro — CPA / Tax-Counsel Briefing

**Prepared for:** a Philippine CPA (and, for two items, tax counsel)
**Subject:** professional confirmation of the tax and bookkeeping treatments encoded in an accounting system before it generates its first BIR return
**Date prepared:** 2026-07-22
**Estimated time to answer:** ~2 hours for Part A and B; Part C is mostly confirm-or-correct

---

## 1. What we are asking you to do

We have built a double-entry accounting system for Philippine SMEs. The ledger, documents, inventory, reports and the SaaS shell are complete and tested. **The BIR returns-and-books layer is deliberately not built yet**, because roughly twenty treatments in it are judgement calls we are not qualified to make, and several of them are baked into files that get filed with the BIR.

This document lists every one of those open questions. For each we give you:

- the question in plain terms,
- **what we currently do** (our drafted treatment — usually you can simply confirm it),
- the issuance we relied on, flagged where the source is weak,
- **what changes in the software** depending on your answer.

We are not asking for a formal tax opinion. We are asking a practitioner to confirm, correct, or flag-for-counsel each item, so the software is built once against a confirmed treatment rather than twice.

**Where we are uncertain, we have deliberately built nothing rather than guess.** The withholding-rate table, for example, exists as an empty database table, and the system refuses to compute a withholding amount rather than invent a rate. That is why we need you before we can proceed.

---

## 2. What Soro is, in one page

- **Scope:** double-entry general ledger, chart of accounts, sales invoices, vendor bills, payments, expenses, inventory with perpetual moving-weighted-average costing, financial statements, and the BIR books/returns layer (not yet built).
- **Users:** Philippine SMEs, both VAT-registered and non-VAT.
- **Basis:** accrual *or* cash, chosen per client and fixed for that client. Switching is treated as a restatement event, not a toggle.
- **Money:** whole centavos as integers throughout. No floating-point arithmetic touches the ledger.
- **Immutability:** a posted entry cannot be edited or deleted by anyone, including us. Corrections are reversing entries. Documents are voided, never erased. This is enforced in the application, by database triggers, and by database privileges (the application's database account is granted only `INSERT` and `SELECT` on the audit log).
- **Numbering:** document serial numbers are drawn inside the posting transaction under a row lock, so a failed save consumes no number and no gaps appear. A voided document keeps its number.
- **Audit trail:** every create and void writes a hash-chained audit record in the same transaction. A nightly job re-derives every balance from the journal and walks the hash chain.
- **Explicitly out of scope:** payroll, income-tax returns (1701/1702), multi-currency, POS integration, and historical transaction migration (we carry opening balances and continue the serial series; the prior system remains the record for its own years).

**What is NOT in the system:** any facility to hide, suppress, reset, or retroactively alter a posted transaction. There is no "training mode" and no hidden delete.

---

## 3. What is built, and what your answers unblock

| Layer | Status |
|---|---|
| Ledger core, posting engine, period lock, audit chain | Built and tested |
| Customers, vendors, invoices, bills, payments, expenses | Built and tested |
| Inventory, costing, stock ledger, subledger-to-GL tie-out | Built and tested |
| Financial statements, general ledger, journals, exports | Built and tested |
| Multi-client shell, onboarding, backups, deployment | Built and tested |
| **VAT return (2550Q), withholding (2307/0619-E/1601-EQ/1604-E), columnar books of accounts, SLSP and alphalist data files** | **Not built — waiting on this document** |

---

## 4. How to answer

Each question ends with an answer block:

> **Your answer:** ☐ Confirm as drafted  ☐ Change to: ______________  ☐ Needs tax counsel
> **Notes:**

For most items, ticking *Confirm as drafted* is the expected outcome and takes seconds. The items where we genuinely do not know the answer are marked **P1** in the table below.

**Priority key**

- **P1 — blocks the build.** We cannot write the code without your answer.
- **P2 — blocks go-live.** We can build against our draft, but it must be right before a real filing or a BIR registration.
- **P3 — confirm or correct.** A defensible default is already in place; we want a professional's nod.

---

## 5. Question index

| # | Topic | Priority |
|---|---|---|
| **Part A — computation and returns** | | |
| Q1 | VAT rounding method and level | **P1** |
| Q2 | The ATC ↔ rate table (withholding cannot be computed without it) | **P1** |
| Q3 | Sworn declaration handling and the 5% / 10% individual rate | **P1** |
| Q4 | Top Withholding Agent thresholds and effective-date mechanics | **P1** |
| Q5 | VAT recognition timing on services (output vs deferred output) | **P1** |
| Q6 | Withholding timing when the client is on the cash basis | **P1** |
| Q7 | Customer advances and deposits received before invoicing | **P1** |
| Q8 | Credit notes — VAT effect, serial series, and return treatment | **P1** |
| Q9 | Percentage-tax and 8%-election clients | P2 |
| **Part B — books, registration and controls** | | |
| Q10 | Retention period: five years or ten | P2 |
| Q11 | Do our controls satisfy a CAS audit-trail review? | P2 |
| Q12 | Book codes and serial formats vs the BIR-registered series | P2 |
| Q13 | "Gapless" numbering and the ACCN on the document face | P2 |
| Q14 | Chart of accounts and the BIR field mappings | P2 |
| Q15 | Post-EOPT section numbering and citation hygiene | P3 |
| **Part C — accounting policy** | | |
| Q16 | Year-end close mechanics | P3 |
| Q17 | Opening Balance Equity and the cutover | P3 |
| Q18 | Cash-basis book structure | P2 |
| Q19 | Inventory: costing declaration and spoilage documentation | P3 |
| Q20 | Found stock — which account is credited | P3 |
| Q21 | Cash-flow activity classification (deferred feature) | P3 |
| **Part D** | | |
| Q22 | The worked fixtures | P2 |
| **For counsel, not CPA** | | |
| Q23 | Data-privacy minimisation vs BIR retention | P2 |

---

# Part A — Computation and returns

These drive numbers that get filed. Every one is **P1** unless noted.

### Q1 — VAT rounding method and level

**Question.** When VAT is computed on an invoice, do we round **per line** or **per invoice**, and do we round **half-up** or **half-to-even (bankers')**? Where an invoice is priced VAT-inclusive, is it acceptable to derive the net and take VAT as the remainder so the pieces sum exactly to the stated gross?

**Why it matters.** Rounding at a different level changes the VAT figure by a centavo or two per invoice, and those centavos accumulate into the quarterly 2550Q. A per-line/per-invoice mismatch also makes the sum of line VAT differ from the invoice VAT, which is exactly the sort of discrepancy an examiner picks at.

**What we do today.** VAT is computed **per line, half-up**, to the centavo: `VAT = (base × rate + 0.005) truncated to centavos`. Where a price is VAT-inclusive, we compute the net and then set **VAT = gross − net**, so the two always reconstitute the stated gross exactly. Invoice totals are the sum of the already-rounded line values. The rate is stored as a date-effective data row (12% from 2024-01-01), never hard-coded.

**What it rests on.** VAT must be computed and shown to the centavo as a separate item (Sec. 113 NIRC; RR 16-2005). ⚠️ **No BIR issuance we could find prescribes half-up over bankers', or per-line over per-invoice.** This is a genuine gap, not an oversight in our reading.

**What changes.** Rounding is isolated in one class with a swappable policy, so changing the method is a small, well-tested change *now*. Once invoices are posted, changing it means historical documents and re-generated returns disagree with what was filed — so this must be settled before the first client goes live.

> **Your answer:** ☐ Confirm as drafted (per line, half-up, inclusive-VAT as remainder)  ☐ Change to: ______________
> **Notes:**

---

### Q2 — The ATC ↔ rate table

**Question.** Please provide or validate the complete, current table of Alphanumeric Tax Codes we should seed, with for each: code, rate, payee type (individual vs juridical), any threshold or sworn-declaration condition, legal basis, and effective date.

**Why it matters.** This table *is* the withholding engine. Every 2307, 0619-E, 1601-EQ, QAP and 1604-E alphalist we produce is generated from it. A wrong code fails alphalist validation; a wrong rate under-remits.

**What we do today.** Nothing — deliberately. The `atc_rates` table exists with the right shape (code, payee type, rate, effective-from/to) and is **empty**. When asked for a rate the system raises *"No effective ATC rate for [code]/[payee type] on [date]"* and refuses to post rather than guess. This is the single largest blocker in this document.

**Our working draft, for you to correct:**

| Payment | Rate | ATC (individual / juridical) | Condition |
|---|---|---|---|
| Professional / talent / consultancy | 5% or 10% | WI010 / WI011 | 5% if current-year gross ≤ ₱3M **and** a valid sworn declaration is on file; else 10% (RR 11-2018 as amended by RR 14-2018) |
| Professional fees (juridical) | 10% or 15% | WC010 / WC011 | 10% if gross ≤ ₱720,000; else 15% |
| Rentals | 5% | WI100 / WC100 | RR 2-98 §2.57.2(C) |
| Contractors / subcontractors | 2% | WI120 / WC120 | RR 2-98 §2.57.2 |
| TWA → local supplier, goods | 1% | WI158 / WC158 | Only if the payor is a designated TWA |
| TWA → local supplier, services | 2% | WI160 / WC160 | Only if the payor is a designated TWA |
| Commissions / brokerage | 5% or 10% (ind.), 10% or 15% (corp.) | ⚠️ WI139/WI140, WC139/WC140 | **Code numbers unverified — please confirm** |

⚠️ We have also noted a trap we want confirmed: **WC157/WI157 and WC640/WI640 are government-payment codes** and must not be auto-assigned to ordinary private-sector purchases.

**What changes.** Nothing about withholding can be built until this table is seeded. Ideally we seed it directly from the current eBIRForms/eFPS ATC library rather than from a secondary source — if you can point us at the authoritative list, that is the fastest path.

> **Your answer:** ☐ Table above confirmed  ☐ Corrections attached  ☐ Use this authoritative source instead: ______________
> **Notes:**

---

### Q3 — Sworn declaration handling

**Question.** The 5% individual professional rate depends on an annual *Income Payee's Sworn Declaration of Gross Receipts/Sales* plus the Certificate of Registration. What is the correct behaviour when the declaration is **absent**, **expired**, or **filed mid-year**? Does the higher rate apply prospectively from expiry, or is it retroactive to the start of the year?

**Why it matters.** It decides whether a stored declaration date changes the rate on tomorrow's bill only, or requires us to revisit bills already posted this year.

**What we do today.** Each vendor carries a `sworn_declaration_valid_until` date. If it is absent or has passed on the payment/booking date, we resolve the **higher** rate. We do **not** retroactively re-rate documents already posted.

**What it rests on.** RR 11-2018 as amended by RR 14-2018.

**What changes.** If the answer is retroactive, we need an additional mechanism: a way to identify affected posted documents and generate correcting entries, plus a policy on who is notified. That is a meaningful amount of extra work and we would rather know now.

> **Your answer:** ☐ Confirm as drafted (prospective, default to higher rate)  ☐ Retroactive — see notes  ☐ Needs tax counsel
> **Notes:**

---

### Q4 — Top Withholding Agent thresholds and effective dates

**Question.** Confirm the TWA criteria and — more importantly — the mechanics: a taxpayer becomes a TWA when **published or notified by the BIR**, not by self-assessment. From what date must the 1% / 2% rates be applied, and until what date do they continue after de-listing?

**Why it matters.** The 1% goods / 2% services withholding applies only if our client is a designated TWA. Applying it when they are not, or failing to when they are, are both filing errors.

**What we do today.** The client's profile carries an `is_TWA` flag and a `twa_effective_date`. The goods/services ATCs are gated behind that flag and only apply to documents dated on or after the effective date. We have **not** built any automatic threshold detection, on the reasoning that TWA status is conferred, not computed.

**What it rests on.** RR 31-2020 (18 December 2020) sets criteria of ₱12M gross sales/receipts/purchases for Groups A and B and ₱5M for Groups C, D and E. ⚠️ We have not verified those groupings or the exact publication mechanics.

**What changes.** If de-listing works differently from listing (e.g. continues to the end of a quarter or year), we need a second date field and a different rule. If a threshold should trigger a warning to the client, we would add a soft advisory — but never an automatic status change.

> **Your answer:** ☐ Confirm as drafted (manual flag + effective date, no auto-detection)  ☐ Change to: ______________
> **Notes:**

---

### Q5 — VAT recognition timing on services

**Question.** Post-EOPT, for a **services** invoice: is output VAT recognised in full at invoicing, or is any part deferred until collection? Should we maintain a *Deferred Output VAT* account at all? And symmetrically — when may a buyer claim input VAT on a services purchase: at the supplier's invoice date, or on payment?

**Why it matters.** This is the single largest structural question in the VAT engine. It decides whether a services invoice posts one output-VAT line or two, and it determines which quarter the VAT lands in.

**What we do today.** We recognise output VAT **at invoice date** for both goods and services, on the reading that EOPT (RA 11976, implemented by RR 7-2024) made the Invoice the single principal document and moved services onto an invoice basis, replacing the old collection-based treatment for services. There is a single *Output VAT* liability account and **no Deferred Output VAT account at all** — if any deferral survives, we have to add one.

**What it rests on.** RA 11976 (Ease of Paying Taxes); RR 7-2024. We are confident in the direction of the change but want the timing confirmed by someone who has filed under it.

**What changes.** If any deferral survives, the sales posting rule gains a second VAT line and a release mechanism tied to collection, and the 2550Q must draw from both accounts. That is a substantial difference in the return generator and needs to be known before it is written.

> **Your answer:** ☐ Confirm as drafted (output VAT at invoice date, no deferral)  ☐ Change to: ______________
> **Notes:**

---

### Q6 — Withholding timing when the client is on the cash basis

**Question.** RR 4-2024 accrues expanded withholding tax at the **booking** date rather than at payment. A client keeping cash-basis books does not record the expense until payment. When the withholding return is prepared for a cash-basis client, should it report withholding by **booking date** (diverging from their own books) or by **payment date**?

**Why it matters.** This is the one place where a correct GL and a correct return disagree, and we must know which one bends.

**What we do today.** Accrual-basis clients book *Withholding Tax Payable* at the bill date, per RR 4-2024. For cash-basis clients the GL naturally defers to payment. Our drafted intent is that the **return data is drawn on the booking date regardless of basis**, so the 0619-E and 1601-EQ are correct even where they do not tie line-for-line to a cash-basis general ledger — with a reconciliation report explaining the difference.

**What it rests on.** RR 4-2024 (accrual of withholding). The interaction with cash-basis bookkeeping is not addressed by any issuance we found.

**What changes.** If the return should follow payment date for cash-basis clients, the withholding extract becomes basis-aware and the reconciliation report is unnecessary. If it follows booking date, we must build a withholding sub-ledger that is independent of the GL for cash-basis clients — noticeably more work, and worth confirming before starting.

> **Your answer:** ☐ Booking date regardless of basis (as drafted)  ☐ Follow the client's basis  ☐ Needs tax counsel
> **Notes:**

---

### Q7 — Customer advances and deposits

**Question.** A customer pays a deposit before any invoice is issued. Under the post-EOPT invoice-based rules, when is output VAT due — on receipt of the deposit, or on issue of the invoice? And what document should the system produce for the deposit itself?

**Why it matters.** Deposits are routine for the SMEs we serve (construction, events, made-to-order goods). Getting this wrong misstates VAT for a whole quarter.

**What we do today.** A *Customer Deposits* liability account exists in the default chart, but **no deposit flow, document or VAT treatment is built**. We need the answer before writing it.

**What it rests on.** Unresolved. The pre-EOPT treatment turned on receipt for services; the post-EOPT invoice-based rule points at invoicing, but we could not find an issuance that squarely addresses deposits.

**What changes.** Either (a) the deposit receipt triggers an invoice and output VAT immediately, or (b) the deposit posts as a liability with no VAT effect and VAT arises on the later invoice, with the deposit applied. These are materially different flows and different documents.

> **Your answer:** ☐ VAT on receipt of deposit  ☐ VAT on invoice; deposit is a liability  ☐ Needs tax counsel
> **Notes:**

---

### Q8 — Credit notes

**Question.** For a credit note (sales return, discount, or price correction after invoicing): (a) does it reduce output VAT in the period of the credit note or amend the original period; (b) must it carry its own registered serial series; (c) how does it appear in the 2550Q and in the Summary List of Sales?

**Why it matters.** Credit notes are already built and posting, because they are the only correct way to fix a document in a closed period. Their VAT and return treatment is not yet built.

**What we do today.** Credit notes exist as documents with their own continuous serial series, post reversing entries, and cannot be edited or deleted. We have not yet decided their VAT-return treatment.

**What it rests on.** Sec. 113 NIRC and the invoicing rules generally; the specific return mechanics are unconfirmed for us.

**What changes.** If credits amend the original period, the return generator must support amended 2550Q filings and we must track which returns have already been filed. If they land in the current period, the generator is materially simpler. This is a meaningful design fork.

> **Your answer:** ☐ Current period  ☐ Amend original period  ☐ Needs tax counsel
> **Notes:**

---

### Q9 — Percentage-tax and 8%-election clients *(P2)*

**Question.** For a non-VAT client on percentage tax, or one who has elected the 8% income-tax option: what does the system need to produce, and what must appear on the invoice? Does the 8% election affect anything we generate, given that we do not file income-tax returns?

**Why it matters.** A meaningful share of our target SMEs are below the ₱3M threshold.

**What we do today.** The client profile carries a VAT-registered flag, a percentage-tax rate, and a date-effective regime record (VAT / percentage tax / 8%). Invoices for non-VAT clients omit the VAT breakdown. We generate no percentage-tax return (2551Q) at present.

**What changes.** If 2551Q generation is expected, it joins the Phase 4 build. If the 8% election changes invoice content or the books, we need to know precisely how.

> **Your answer:** ☐ Confirm as drafted  ☐ Add 2551Q  ☐ Other: ______________
> **Notes:**

---

# Part B — Books, registration and controls

### Q10 — Retention: five years or ten *(P2)*

**Question.** Which is the operative retention obligation for computerised books and their supporting records — the **five years** in RR 7-2024, or the **ten years** (five hard copy plus five electronic) still stated in RMC 5-2021 Annex B item 7 and RR 17-2013 / RR 5-2014?

**Why it matters.** It sets how long we must keep every client's books online and how long our backup retention must run — a real cost, and a real exposure if it is short.

**What we do today.** We retain conservatively on a **ten-year** horizon, with a **legal-hold flag** that suspends any purge while a protest, refund claim or case is open. Retention is a configuration value, not a hard-coded number.

**What it rests on.** RR 7-2024 sets the statutory period at five years under Sec. 235 as amended by EOPT. Older issuances say ten. This is a live conflict, not an ambiguity in our reading.

**What changes.** Confirming ten costs nothing (it is what we do). Confirming five would let us reduce storage and simplify the archive, but we would not make that change without it in writing.

> **Your answer:** ☐ Ten years (conservative, as built)  ☐ Five years is sufficient  ☐ Needs tax counsel
> **Notes:**

---

### Q11 — Do our controls satisfy a CAS audit-trail review? *(P2)*

**Question.** RMC 5-2021 Annex B requires that posted transactions can be voided but not modified, that users cannot edit data inside system-generated reports, that every record is stamped with the creating user, and that the audit trail is protected from modification or destruction. Below is exactly what we do. **In your experience of BIR CAS reviews, is this sufficient, and is there anything an examiner routinely asks for that is missing?**

**What we do today.**

1. Posted journal entries and their lines are immutable. Database triggers reject any `UPDATE` or `DELETE` on a posted entry; the only permitted transition is posted → void.
2. Documents are voided, never edited or deleted. A void keeps the document's serial number.
3. Corrections are reversing entries, linked bidirectionally to the entry they reverse.
4. Every create and void writes an audit record — user, timestamp, action, and the before/after values — inside the same database transaction as the change itself, so a change cannot be recorded without its audit row.
5. The audit log is **hash-chained**: each row carries a hash of the previous row plus its own content, so removing or altering any row breaks the chain detectably.
6. The application's database account holds only `INSERT` and `SELECT` on the audit log. It has no privilege to `UPDATE` or `DELETE` audit rows — a compromised application still cannot rewrite history.
7. A nightly job re-derives every balance from the journal and walks the entire hash chain; a break is an alert, not a log line.
8. Serial numbers are drawn under a row lock inside the posting transaction: a failed save consumes no number, so gaps cannot arise from ordinary use.
9. Every generated book and report carries the mandatory header block — registered name, address, TIN and branch code, software name and version, the generating user, and the date-time of generation.
10. There is no training mode, no hidden delete, and no reset-to-zero facility anywhere in the system.

**What it rests on.** RMC 5-2021 Annex B items 4, 8, 10 and 11; NIRC Sec. 264-B on suppression devices.

**What changes.** If an examiner expects something we do not produce — a particular printable audit-trail format, a specific report, a user-access log we are not keeping — we would much rather add it now than during a review.

> **Your answer:** ☐ Sufficient as described  ☐ Also required: ______________
> **Notes:**

---

### Q12 — Book codes and serial formats *(P2)*

**Question.** We maintain separate numbered series per book — general journal, sales journal, purchase journal, cash receipts, cash disbursements — plus per-document series for invoices, credit notes and the like. **Do these series need to correspond to what is declared in the CAS registration, and if so, what format and starting number should they take?** For a client migrating from another system, we continue the existing series rather than restarting at 1 — please confirm that is right.

**Why it matters.** Serial numbering is a primary audit red flag, and the series we generate must match what the client registered.

**What we do today.** Each series is continuous, per branch, never resets at year end, and supports a configurable starting number so a migrating client's series carries on unbroken. Voided documents keep their number.

**What it rests on.** RR 18-2012 and RMC 77-2024 (system-generated, sequential, unique, non-reusable serials; continuation on migration; configurable starting serial).

**What changes.** If the registered series must follow a prescribed format (a prefix convention, a branch code embedded in the number), we need to know before any client registers, because the format becomes fixed for that client for good.

> **Your answer:** ☐ Confirm as drafted  ☐ Required format: ______________
> **Notes:**

---

### Q13 — "Gapless" numbering and the ACCN on the document face *(P2)*

**Question.** Two pieces of common practitioner wording we could not source to any issuance: (a) that serial numbers must be "gapless", and (b) that the Acknowledgement Certificate control number must appear **on the face** of issued documents. Are both real requirements? If the ACCN must appear, exactly where and in what form?

**Why it matters.** We are about to turn these into hard validation rules. A rule that is not actually required but blocks a client from issuing an invoice is worse than no rule.

**What we do today.** We enforce gapless numbering as an internal design principle (it is the right engineering behaviour regardless). We store the ACCN and its issue date, and we **block go-live until an ACCN is recorded**, but we do not currently print it on invoices.

**What it rests on.** ⚠️ **No issuance we found uses the word "gapless"**, and the "on the face" wording is unverified. Both are practitioner conventions as far as we can tell.

**What changes.** If the ACCN must be printed, it goes into the invoice template and the mandatory header block — a small change, but one that affects every document already designed.

> **Your answer:** ☐ Gapless: real ☐ / convention ☐  ☐ ACCN on face: required ☐ / not required ☐  Placement: ______________
> **Notes:**

---

### Q14 — Chart of accounts and BIR field mappings *(P2)*

**Question.** Please review the default chart of accounts we ship to new clients, and confirm the BIR attributes we attach to accounts: tax type, default ATC code, and the financial-statement line each account maps to.

**Why it matters.** The default chart is what most SMEs will actually use, unchanged. If it is wrong, it is wrong everywhere at once. The account-to-FS-line mapping also drives the generated statements.

**What we do today.** We seed a conventional SME chart with system-bound accounts for A/R, A/P, output VAT, input VAT, creditable withholding tax (the 2307 asset), withholding tax payable, customer deposits, inventory, goods-received-not-invoiced, COGS, shrinkage, retained earnings, income summary, and opening balance equity. The BIR attributes exist as columns on every account but are largely unpopulated.

**What changes.** Your corrections go straight into the seed, so every client provisioned afterwards gets them. Clients provisioned before would need a migration — another reason to settle this before the first live client.

> **Your answer:** ☐ Chart reviewed, corrections attached  ☐ Confirm as drafted
> **Notes:**

---

### Q15 — Post-EOPT section numbering and citation hygiene *(P3)*

**Question.** Our internal reference cites the NIRC and several regulations by section. EOPT renumbered parts of the NIRC and we flagged a handful of citations we could not verify to the digit — for example Sec. 109(CC) vs (BB), Sec. 110(D), and the internal subsection lettering of RR 3-2024 and RR 7-2024. Could you confirm these against the consolidated post-EOPT text?

**Why it matters.** These citations appear in our engineering documentation and could end up in client-facing help text. A wrong citation undermines confidence in everything around it.

**What we do today.** Every uncertain citation is marked ⚠️ in our reference and is excluded from any rule that drives a computation. Nothing load-bearing rests on an unverified cite.

**What changes.** Documentation only. No code depends on this.

> **Your answer:** ☐ Reviewed, corrections attached  ☐ No changes needed
> **Notes:**

---

# Part C — Accounting policy

### Q16 — Year-end close mechanics *(P3)*

**Question.** Confirm our year-end close: income and expense accounts are zeroed through an **Income Summary** account, and the result is transferred to **Retained Earnings**. Should we instead close directly to Retained Earnings? For a sole proprietorship, should the closing entry route through a Drawings account? Do we need to accommodate book-versus-tax differences (MCIT, NOLCO) in the close, or do those live outside the books?

**What we do today.** A posted closing journal entry zeroes income and expense to Income Summary, then Income Summary to Retained Earnings. The fiscal year is then marked closed and the posting lock advances. No book-tax difference handling exists — we treat that as the client's CPA's work at ITR time.

**What changes.** Any of these is a change to one posting rule. Low cost now, higher cost after clients have closed a year.

> **Your answer:** ☐ Confirm as drafted  ☐ Change to: ______________
> **Notes:**

---

### Q17 — Opening Balance Equity and the cutover *(P3)*

**Question.** When a client migrates in, we enter opening balances account by account with the difference absorbed by an **Opening Balance Equity** account, which should then clear to zero once everything is entered. Confirm this is the right approach, and tell us what the correct cutover date convention is — the first day of a fiscal year, or any period start?

**What we do today.** Opening Balance Equity absorbs the plug; the system reports a non-zero balance in it as an unfinished cutover. We allow the cutover at any period start.

**What changes.** If the cutover must fall on a fiscal-year boundary, we add a validation. If Opening Balance Equity should clear to something other than Retained Earnings, we change one rule.

> **Your answer:** ☐ Confirm as drafted  ☐ Change to: ______________
> **Notes:**

---

### Q18 — Cash-basis book structure *(P2)*

**Question.** For a client keeping cash-basis books, we take the position that the general ledger legitimately has **no Accounts Receivable or Accounts Payable control accounts** — receivables and payables are tracked in the document subledger for aging purposes, but never posted to the GL. Is that the correct presentation for BIR purposes, and does anything about the columnar books change for a cash-basis client?

**Why it matters.** It determines the shape of every cash-basis client's books of accounts, which is what an examiner looks at.

**What we do today.** As described: cash-basis clients post revenue on collection and expense on payment; aging comes from documents, not from the GL.

**What changes.** If cash-basis books are still expected to carry control accounts, the cash-basis posting rules change substantially.

> **Your answer:** ☐ Confirm as drafted  ☐ Change to: ______________
> **Notes:**

---

### Q19 — Inventory: costing declaration and spoilage *(P3)*

**Question.** (a) We use **moving weighted average**, applied perpetually. Does this need to be declared to the BIR, and is there a consistency requirement that would prevent a client changing method later? (b) For spoilage and shrinkage write-offs to be deductible, what documentation is required — is BIR notice or inspection needed before disposal?

**Why it matters.** Part (b) determines whether the software must produce a specific form or record before a write-off is allowed, or merely record the reason.

**What we do today.** Moving weighted average, computed to six decimal places and rounded only at the point it hits the ledger. Adjustments are reason-coded (shrinkage, spoilage, damage, found, correction) and a shrinkage-trend report subtotals spoilage separately. We do not produce any notice document.

**What changes.** If notice or inspection is required, we add a pre-write-off record with the required fields, and potentially block the write-off until it exists.

> **Your answer:** ☐ Confirm as drafted  ☐ Documentation required: ______________
> **Notes:**

---

### Q20 — Found stock *(P3)*

**Question.** A physical count finds **more** stock than the books show. We debit Inventory — what should we credit: the same **Inventory Shrinkage** expense account that absorbs shortages (treating it as a contra recovery), or **Other Income**?

**Why it matters.** It changes where a count variance lands in the P&L, and whether net shrinkage over a year reads as a single figure or as gross expense plus gross income.

**What we do today.** We credit **Inventory Shrinkage**, so the account carries net variance for the period. Individual items can override this with their own adjustment account. This is our draft, chosen because it makes the shrinkage-trend report meaningful; we have no strong view.

**What changes.** One line in one posting rule.

> **Your answer:** ☐ Shrinkage (net presentation, as drafted)  ☐ Other Income  ☐ Depends — see notes
> **Notes:**

---

### Q21 — Cash-flow activity classification *(P3, future feature)*

**Question.** We have deferred the Statement of Cash Flows because it requires every account to be tagged **operating**, **investing** or **financing**, and that mapping is a judgement call we should not make. When we build it, would you be willing to supply that classification for the default chart? And would the **indirect** method be the expected presentation for an SME?

**What we do today.** We render no cash flow statement at all, on the principle that no statement is better than a plausible wrong one. Cash movement is fully visible through the general ledger on each cash account, the bank reconciliation, and the dashboard cash position.

**What changes.** Nothing today. This is flagged so it is on your radar for the next version rather than arriving as a surprise.

> **Your answer:** ☐ Will supply classification later  ☐ Indirect method confirmed  ☐ Other: ______________
> **Notes:**

---

# Part D — The worked examples

### Q22 — Fixtures *(P2)*

**Question.** We maintain a set of hand-worked scenarios with their exact expected debits and credits, which are asserted by the automated test suite on every change. **They encode tax treatment, so they are only as right as the treatment behind them.** Please review the debits and credits in each.

The scenarios are in [`docs/fixtures/scenarios.md`](fixtures/scenarios.md). They cover:

| | Scenario |
|---|---|
| S1 | Opening balances at go-live (accrual) |
| S2 | VATable sales invoice, accrual — net ₱10,000 + ₱1,200 VAT |
| S3 | The same invoice on the cash basis |
| S4 | Customer collection with 2% creditable withholding — a TWA customer, services, ATC WC160 (the 2307 case) |
| S5 | Vendor bill with 2% expanded withholding, accrual, withholding accrued at booking per RR 4-2024 |
| S6 | Manual adjusting entry (depreciation) |
| S7 | Reversal of S6 |
| S8 | Year-end close, nominal accounts to Retained Earnings |
| S9 | Month close |
| S10 | Inventory received, sold, and counted — perpetual weighted average |

Note that **S4 depends on your answer to Q2 and Q4** (the ATC code and the TWA gating) and **S5 depends on Q6** (withholding timing). If those answers change, these fixtures change with them.

**What changes.** A correction to any fixture is a correction to the posting rule behind it, caught immediately by the test suite. This is the cheapest possible place to find an error in our accounting.

> **Your answer:** ☐ All reviewed and correct  ☐ Corrections attached
> **Notes:**

---

# For counsel rather than CPA

### Q23 — Data privacy vs BIR retention *(P2)*

**Question.** The Data Privacy Act requires personal data to be retained no longer than necessary; the BIR requires books and their supporting records to be preserved for years. Client records contain personal data (names, addresses, TINs of individual customers and vendors). **We rely on the "required by law" ground to justify retaining personal data for the BIR retention period. Please confirm that reasoning, and confirm whether a data subject's request for erasure can be refused on that basis for records that form part of the books.**

**What we do today.** Retention is set by the BIR horizon, not by a privacy-minimisation horizon. We register the client's data protection officer details, and we treat the books as records that cannot be erased on request.

**What changes.** If erasure must be honoured for some categories, we need a mechanism that removes personal data without breaking the ledger or its hash chain — a genuinely difficult problem that we would want to design deliberately, not retrofit.

> **Your answer:** ☐ Reasoning confirmed  ☐ Qualified — see notes
> **Notes:**

---

## 6. What we are *not* asking you to do

To be clear about scope, so this stays a two-hour job:

- We are not asking you to design a chart of accounts from scratch — only to correct ours.
- We are not asking for a formal written tax opinion.
- We are not asking about payroll, income-tax returns, or any filing we have already excluded from the product.
- We are not asking you to review source code.
- We are not asking you to accept responsibility for the software's output. Your answers configure it; we remain responsible for building it correctly, and every computation is covered by tests.

## 7. Sign-off

Answers to Part A questions marked **P1** unblock the build. Everything else can proceed in parallel.

| | |
|---|---|
| Reviewed by | |
| PRC / CPA licence no. | |
| Firm | |
| Date | |
| Signature | |

**Items referred to tax counsel:** ______________________________________________

---

*Source references for every citation in this document, with links to the BIR issuances, are in [`docs/specs/03-bir-accreditation.md`](specs/03-bir-accreditation.md). The engineering decision log that these answers feed into is [`docs/specs/06-decisions.md`](specs/06-decisions.md).*
