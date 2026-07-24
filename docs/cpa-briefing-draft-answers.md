# Soro — CPA Briefing: Pre-Review Draft Answers

**Status:** DRAFT — prepared by AI-assisted research, **not** the answers of a licensed Philippine CPA.
This document pre-fills every answer block in [`cpa-briefing.md`](cpa-briefing.md) so the engaged practitioner can *confirm* rather than *compose*. It does not replace the sign-off in §7 of the briefing. Items the licensed reviewer must still genuinely decide are listed in [§4](#4-items-the-licensed-reviewer-must-still-decide).

**Prepared:** 2026-07-24
**Method:** each drafted treatment was answered from Philippine tax practice and verified, where flagged uncertain, against the primary issuances (RR 3-2024, RR 4-2024, RR 7-2024, RR 31-2020, RMC 5-2021, RR 11-2018, NIRC as amended by EOPT) and reputable practitioner commentary. Full source list in [§5](#5-sources).

---

## 1. Executive summary — what survives and what doesn't

Most drafted treatments are **confirmed**, several with citations the briefing was missing. The material corrections:

1. **Q13 — the ACCN must be printed on the face of invoices.** Not practitioner folklore: under RMC 5-2021 the Acknowledgement Certificate Control Number is to be *indicated on the face of the system-generated principal and/or supplementary receipts/invoices*. Add it to the invoice template and the mandatory header/footer block.
2. **Q2 — the commissions ATC guess (WI139/WI140, WC139/WC140) is wrong.** The 10% commissions pair is **WI515/WC515**, and it is specifically for independent/exclusive distributors, medical/technical and sales representatives, and marketing agents of multi-level-marketing companies. Generic commissions paid to a non-employee agent fall under the professional-fees codes (WI010/WI011). Do **not** seed the guessed codes.
3. **Q7 — deposits: the answer is "both," split by nature.** A refundable **security deposit** is a liability with no VAT until applied or forfeited. An **advance payment / downpayment** is part of gross sales — invoice it, output VAT on receipt. Two flows, two documents.
4. **Q8 — credit notes: current period, and it is statutory.** NIRC Sec. 106(D): returns and allowances are deducted from gross sales *in the quarter in which a refund is made or a credit memorandum or refund is issued*. No amended-return machinery is needed.
5. **Q10 — the operative retention period is now five years** (RR 7-2024, counted from the day following the filing deadline), extended while a protest or refund claim is pending — which the legal-hold flag already models. Ten years remains a legitimate conservative product policy (Sec. 222 fraud assessments run 10 years *from discovery*), but the legal floor is five.
6. **The S3/S4 cash-basis fixtures contradict the Q5 answer.** Post-EOPT, VAT is invoice-basis *by law*, regardless of the client's bookkeeping basis. A VAT-registered cash-basis client's 2550Q must draw output VAT at **invoice date**. The fixture that credits Output VAT at collection is right for the GL only if the return generator ignores the GL and reads the invoice-date VAT snapshot. Make that invariant explicit or the cash-basis 2550Q will be filed a quarter late.
7. **Q18 — cash basis + inventory is not a permissible combination for income tax.** Where inventories are an income-determining factor, accrual is required. Block (or hard-warn on) enabling the inventory module for a cash-basis client.
8. **Q19(b) — spoilage deductibility has a real paper trail: RMO 21-2020.** Application for destruction/disposal filed *before* the fact, BIR witnessing (physical or virtual) or an authorized third party, then a Certificate of Deductibility. Record those fields on the write-off; warn when absent.

One briefing citation checked out better than expected: **RR 4-2024 is primarily the filing-and-payment regulation** (removal of the 25% wrong-venue surcharge, file-and-pay-anywhere), but its "other matters" portion **does** contain the withholding-timing amendment — the obligation to withhold arises when the income **becomes payable** (due, demandable or legally enforceable). So the Q6/S5 citation is substantively correct, with a refinement noted under Q6 below.

---

## 2. Verdict index

| Q | Topic | Verdict | One-liner |
|---|---|---|---|
| Q1 | VAT rounding | ✅ Confirm as drafted | Per-line half-up, inclusive-as-remainder; no issuance prescribes a method — consistency is what gets audited |
| Q2 | ATC table | ✏️ Corrections | Table mostly right; fix commissions (WI515/WC515); seed the full eBIRForms ATC library |
| Q3 | Sworn declaration | ✅ Confirm as drafted | Prospective, default to higher rate; good-faith reliance is the design of RR 11-2018 |
| Q4 | TWA mechanics | ✅ Confirm + 1 field | Obligation starts the 1st of the month after publication; delisting also by publication → add `twa_effective_to` |
| Q5 | VAT timing, services | ✅ Confirm + 2 notes | Invoice-date output VAT for goods **and** services (RR 3-2024); transitional pre-EOPT service receivables; new uncollected-receivables output-VAT credit |
| Q6 | Cash-basis withholding | ✅ Confirm, refined | Return follows the *payable* date (RR 4-2024) regardless of basis; extract from the bills subledger, not the GL |
| Q7 | Deposits | ✏️ Both flows | Security deposit = liability, no VAT; advance payment = invoice + VAT on receipt |
| Q8 | Credit notes | ✅ Current period | Sec. 106(D); own registered series (supplementary doc); 2550Q deduction; SLSP adjustment |
| Q9 | PT / 8% clients | ✏️ Add 2551Q | PT clients need 2551Q; 8% electors get **no** 2551Q; non-VAT invoices carry the "not valid for claim of input tax" legend |
| Q10 | Retention | ✅ Keep ten as policy | Legal floor is five (RR 7-2024); pending-case extension confirmed; legal-hold matches the rule |
| Q11 | CAS controls | ✏️ Additions | Add user-access/activity log, printable audit-trail report, registration documentation package, documented backup/restore |
| Q12 | Book codes / serials | ✅ Confirm as drafted | Series must match the sworn statement filed at AC registration; per-branch; continuation on migration correct |
| Q13 | Gapless / ACCN | ✏️ Print ACCN | Gapless = convention (rule is sequential/unique/non-reusable — keep the behavior); ACCN on the face = **required** |
| Q14 | Chart of accounts | ✏️ Corrections | Split withholding payables by type; split Input VAT for 2550Q lines; add Percentage Tax Payable; no Deferred Input VAT needed |
| Q15 | Citations | ✏️ Verify as flagged | Threshold likely Sec. 109(CC) post-CREATE; 110(D) plausibly the EOPT uncollected-receivables insert — documentation only |
| Q16 | Year-end close | ✅ Confirm as drafted | Income Summary → RE fine; sole props close Drawings to Owner's Capital; book-tax differences outside the books |
| Q17 | OBE / cutover | ✅ Confirm as drafted | OBE-as-plug standard; FY-start cutover strongly preferred, period-start allowed with warning |
| Q18 | Cash-basis books | ⚠️ Qualified confirm | No AR/AP control accounts fine for genuine cash-basis *service* business; VAT stays invoice-basis regardless; inventory clients cannot be cash basis |
| Q19 | Costing / spoilage | ✏️ (b) changes | (a) weighted average fine, disclosed in FS/ITR, change needs prior BIR consent; (b) RMO 21-2020 destruction process required for deductibility |
| Q20 | Found stock | ✅ Confirm as drafted | Net-to-shrinkage is standard and keeps the trend report honest |
| Q21 | Cash flow | ✅ Confirm | Indirect method for SMEs; classification supplied with the chart review |
| Q22 | Fixtures | ✏️ See §3 | Entries arithmetically correct; cash-basis VAT rows need the invariant from finding #6 |
| Q23 | DPA vs retention | ✅ Reasoning sound | DPA Sec. 12(c) "legal obligation" ground; erasure refusable for mandated records — still route to counsel |

---

## Part A — Computation and returns

### Q1 — VAT rounding method and level

**Answer: ✅ Confirm as drafted** (per line, half-up, inclusive-VAT as remainder).

- The briefing's finding is correct and verified: **no BIR issuance prescribes half-up over bankers', or per-line over per-invoice.** The binding requirements are only that VAT be shown as a separate item on the invoice (Sec. 113 NIRC) and computed to the centavo.
- Per-line half-up is the de facto standard in Philippine practice and in every major accounting package used locally. eBIRForms/eFPS accept centavo amounts; SLSP validation compares invoice-level totals — which your "invoice VAT = Σ rounded line VAT" rule keeps internally consistent.
- The VAT-inclusive treatment (net = gross ÷ 1.12 rounded; VAT = gross − net) is accepted practice and has the virtue that the document face always reconstitutes exactly.
- What an examiner actually picks at is *inconsistency* — a method that changes between documents or between the invoice and the return. Keep the policy in one class, document it, never change it mid-client.

**Software impact:** none. Freeze the policy before first go-live as planned.

---

### Q2 — The ATC ↔ rate table

**Answer: ✏️ Corrections attached; seed from the eBIRForms ATC library.**

Line-by-line on the working draft:

| Payment | Draft | Verdict |
|---|---|---|
| Professional/talent/consultancy, individual — 5%/10% — WI010/WI011 | ✅ Correct per RR 11-2018 as amended by RR 14-2018 (5% requires ≤₱3M gross **and** valid sworn declaration; else 10%) |
| Professional fees, juridical — 10%/15% — WC010/WC011 | ✅ Correct (₱720,000 threshold) |
| Rentals — 5% — WI100/WC100 | ✅ Correct (real property; RR 2-98 §2.57.2) |
| Contractors/subcontractors — 2% — WI120/WC120 | ✅ Correct |
| TWA → local supplier, goods — 1% — WI158/WC158 | ✅ Correct (gated on payor TWA status) |
| TWA → local supplier, services — 2% — WI160/WC160 | ✅ Correct |
| Commissions — WI139/WI140, WC139/WC140 | ❌ **Wrong — do not seed.** The 10% commissions pair is **WI515/WC515**, scoped to independent/exclusive distributors, medical/technical & sales representatives and marketing agents of **multi-level marketing companies**. Commissions to an ordinary non-employee agent or broker are treated as professional fees → WI010/WI011 (individual) at 5%/10% |

- The **government-payment trap** (WI157/WC157 goods 1%, WI640/WC640 services 2% — government payors only) is consistent with the ATC annexes. Keep the guard; have the reviewer verify while confirming the seed.
- **Authoritative source:** the ATC library bundled in the current **eBIRForms package**, mirrored in the ATC annexes of BIR Forms 2307 and 1601-EQ. Seed the *entire* library, not a hand-picked subset — clients will hit codes you did not anticipate, and an unseeded code should degrade to the existing "no effective rate" refusal, not a crash.

**Software impact:** seed the full library with effective-date rows; keep the refuse-to-guess behavior for anything not seeded.

---

### Q3 — Sworn declaration handling

**Answer: ✅ Confirm as drafted** (prospective, default to higher rate).

- RR 11-2018's mechanism is built on the payor's **good-faith reliance** on the declaration on file: the payee submits the sworn declaration + COR before the initial payment (or before January 15 each year); absent or lapsed, the payor withholds at the **higher** rate. That is exactly the drafted behavior.
- A declaration filed mid-year takes effect **prospectively from receipt**. Prior payments withheld at 10% are not re-rated by the payor; the payee recovers the difference through their own income-tax return (the CWT is creditable either way).
- **One nuance for the licensed reviewer:** when the payee's cumulative gross *exceeds* ₱3M mid-year, the higher rate applies to subsequent payments, and practice varies on whether the payor applies catch-up withholding on the next payment. Flag this specific behavior for confirmation; the prospective default is right either way.

**Software impact:** none beyond the drafted `sworn_declaration_valid_until` mechanism. No retroactive re-rating machinery needed.

---

### Q4 — Top Withholding Agent thresholds and effective dates

**Answer: ✅ Confirm as drafted, plus one field.**

Verified against RR 31-2020 and its digest:

- **Criteria confirmed:** gross sales/receipts **or gross purchases** in the preceding taxable year of at least **₱12M for RDO Groups A and B**, **₱5M for Groups C, D and E** (groupings per RMO 13-2018).
- **Listing mechanics confirmed:** TWA status is conferred by **publication** (newspaper of general circulation, and/or the BIR website). The obligation to withhold the 1%/2% **commences on the first day of the month following the month of publication.** Your `twa_effective_date` should be set to exactly that date.
- **Delisting confirmed:** a listed taxpayer **remains a TWA until published as delisted.** So delisting is also publication-driven, effective on the same first-of-following-month convention.
- No auto-detection is correct — status is conferred, not computed. A soft advisory when a client's volume crosses the threshold ("you may be listed in the next publication — watch for it") is reasonable; automatic status change is not.

**Software impact:** add a **`twa_effective_to`** date (set when delisting is published); gate the WI158/WC158/WI160/WC160 codes on `twa_effective_date ≤ document date < twa_effective_to`.

---

### Q5 — VAT recognition timing on services

**Answer: ✅ Confirm as drafted** (output VAT at invoice date, no deferral for new transactions) — **with two additions**.

Verified against RR 3-2024:

- **Core treatment confirmed.** EOPT adopted the accrual basis for both goods and services: all references to "gross receipts" became **"gross sales"**, and the **Invoice** is the single principal document. Output VAT on a services invoice is recognized in full at invoice date. A single Output VAT account, no Deferred Output VAT — correct for all post-effectivity transactions.
- **Input VAT symmetric:** the buyer claims input VAT on a services purchase at the supplier's **invoice date**; post-EOPT the invoice substantiates input VAT for both goods and services.
- **Addition 1 — transitional rule.** For services **rendered before RR 3-2024's effectivity** that were billed but uncollected, output VAT is declared **upon collection** (old rule rides out). This only matters for a migrating client carrying pre-EOPT service receivables. Since historical migration is out of scope and only opening balances carry in, note it in the onboarding checklist: a client with pre-2024 uncollected service billings has residual collection-basis VAT that lives outside Soro.
- **Addition 2 — output-VAT credit on uncollected receivables.** EOPT introduced (implemented as §4.110-9 of RR 3-2024) a seller's right to **deduct output VAT on uncollected receivables in the quarter following the lapse of the agreed payment period**, with an **add-back upon subsequent recovery**. The 2550Q generator should eventually support this. Build the tracking fields now (agreed credit period per invoice; claimed-credit flag; recovery add-back), even if the return line ships later.

**Software impact:** none to the posting rule. Two data additions: onboarding note for transitional receivables; per-invoice credit-period tracking for the §4.110-9 mechanism.

---

### Q6 — Withholding timing when the client is on the cash basis

**Answer: ✅ Booking date regardless of basis (as drafted) — refined to the "payable" trigger.**

- The briefing's citation is substantively correct. RR 4-2024 is primarily the filing-and-payment regulation, but its "other matters affecting the declaration of taxable income" portion carries the EOPT withholding-timing change: **the obligation to deduct and withhold arises at the time the income becomes payable**, "payable" meaning the date the obligation becomes **due, demandable or legally enforceable**.
- That trigger is a property of the *transaction*, not of the client's bookkeeping basis. So for a cash-basis client the 0619-E/1601-EQ must still be drawn by the payable/booking date — the drafted answer — even where the GL records the expense only at payment.
- **The build is smaller than the briefing fears.** No parallel withholding ledger is needed: the withholding extract reads the **vendor bills subledger** (which exists for both bases, since it drives A/P aging) rather than the GL. The bill already carries the booking date and due date; the extract keys off those. Keep the planned reconciliation report explaining GL-vs-return differences for cash-basis clients.
- Refinement: capture the bill's **due date** and prefer it as the "payable" proxy where it differs from the booking date; the licensed reviewer can confirm which proxy their filing practice uses.

**Software impact:** withholding extract sources from documents, not journal lines; add due-date capture; reconciliation report as drafted.

---

### Q7 — Customer advances and deposits

**Answer: ✏️ Neither option alone — both flows, split by the deposit's nature.**

This is the classic practitioner distinction, and it survives EOPT:

| Nature | Treatment | Document |
|---|---|---|
| **Security deposit** — refundable, held as guarantee, not applied to the price | Liability (Customer Deposits). **No VAT** until applied to a billing or forfeited, at which point it enters gross sales | Non-VAT acknowledgment/collection receipt (supplementary document) |
| **Advance payment / downpayment** — part of the contract price (construction progress billings, made-to-order goods, event deposits) | Part of **gross sales**: issue an **invoice** for the advance, **output VAT on receipt** | Invoice (principal document) |

- BIR practice has long treated advances and prepaid amounts as taxable when received (they are consideration, merely early); post-EOPT the mechanics align because the advance is *invoiced* when received, which puts the VAT in the receipt quarter under the invoice basis anyway.
- The later final billing invoices only the **balance** (or invoices the full amount and applies the deposit with the advance-invoice cross-referenced — the reviewer should pick the presentation, but net-balance billing is cleaner and avoids double-counting in SLSP).
- The system therefore needs a deposit-type field at capture time, defaulting to "advance payment" (the common SME case), with security deposits explicitly chosen.

**Software impact:** two flows as described; application of deposits to invoices; forfeiture path for security deposits (liability → gross sales + VAT).

---

### Q8 — Credit notes

**Answer: ✅ Current period** — statutory, not a design preference.

- **(a) Period:** NIRC **Sec. 106(D)**: the value of goods returned or allowances granted may be deducted from gross sales **"for the quarter in which a refund is made or a credit memorandum or refund is issued."** Credit notes reduce output VAT in the credit note's own quarter. No amended 2550Q, no filed-return tracking needed for this purpose.
- **(b) Serial series:** yes — the credit memo is a **supplementary document** post-EOPT (RR 7-2024) and carries its own registered, continuous series, declared alongside the others at AC registration. Already built correctly.
- **(c) Returns presentation:** on the 2550Q the credit appears as a **deduction from gross sales** (the sales-returns/allowances line) in the quarter issued. In the **SLSP**, practice is to report the adjustment against the customer in the period of the credit note (netted or as a negative adjustment line, depending on the validation module's tolerance — have the reviewer confirm the current SLSP module's preferred presentation, which is a file-format question, not a treatment question).
- Sales **discounts** nuance for the reviewer: discounts granted *at the time of sale* and shown on the invoice are excluded from gross sales up front; only post-invoice discounts ride the credit-note path.

**Software impact:** the simpler design fork wins. Credit notes feed the current-period 2550Q; no amended-return generator required for them.

---

### Q9 — Percentage-tax and 8%-election clients

**Answer: ✏️ Add 2551Q; 8% electors are the exception that gets no 2551Q.**

- **Percentage-tax clients:** yes, **2551Q generation is expected** — it is a quarterly filing every non-VAT PT client must make, and a bookkeeping product that produces the 2550Q but not the 2551Q is half a product for the sub-₱3M market. Rate is a data row like the VAT rate (3%; note the CREATE-era 1% window ended 30 June 2023 — date-effective rows handle this).
- **8% electors:** the 8% option is **in lieu of** both the graduated income tax *and* the 3% percentage tax. So for an 8% elector: **no 2551Q**, no VAT, and — since income-tax returns are out of scope — nothing else for Soro to file. The regime record (VAT / PT / 8%) drives 2551Q suppression.
- **Invoice content for both:** the invoice shows no VAT breakdown, is marked **"NON-VAT"**, and post-EOPT (RR 7-2024) must carry the legend **"This document is not valid for claim of input tax."** The 8% election itself does not change invoice content beyond that.
- Regime **transitions** (PT client crosses ₱3M mid-year and becomes VAT-liable) are the sharp edge: the date-effective regime record is the right design; add a warning when a PT client's rolling 12-month gross approaches ₱3M.

**Software impact:** 2551Q joins the Phase 4 build; regime record suppresses it for 8% electors; non-VAT invoice legend verified into the template; threshold advisory.

---

## Part B — Books, registration and controls

### Q10 — Retention: five years or ten

**Answer: ✅ Ten years as product policy is fine — but the operative legal obligation is five.**

Verified against RR 7-2024 and practitioner summaries:

- **RR 7-2024 (11 April 2024)** implements Sec. 235 as amended by EOPT: books and records are preserved for **five (5) years** from the day following the deadline for filing the return (or the actual filing date if late) for the taxable year of the last entry. For computerized books, preservation is in **electronic copies** — the old hard-copy-then-electronic split is gone. The ten-year framework of RR 17-2013/RR 5-2014 is superseded on this point; RMC 5-2021 Annex B's item 7 simply predates EOPT.
- **The pending-case extension is confirmed and is exactly your legal-hold flag:** where a protest or claim for credit/refund is pending and the records are material, preservation continues until final resolution, beyond the five years.
- Why keep ten anyway: **Sec. 222** allows assessment within 10 years *from discovery* in fraud/no-return cases (so even ten is not a hard ceiling), storage is cheap for you, and a SaaS purging client books at year five creates commercial risk no client will thank you for. Keep ten as the configurable default; five is the floor you may contractually promise against.

**Software impact:** none — current design (configurable horizon + legal hold) matches the rule. Optionally document "statutory minimum 5y / product default 10y" in the ops spec.

---

### Q11 — Do the controls satisfy a CAS audit-trail review?

**Answer: ✏️ Sufficient on transaction integrity — with four additions examiners routinely ask for.**

The ten listed controls exceed RMC 5-2021 Annex B on the posting/void/audit-chain front; nothing in the list needs weakening. What is missing is around the edges:

1. **User access matrix and activity log.** Examiners ask for user-level access control evidence and an activity log beyond posting events: logins, failed logins, role/permission changes, user creation/deactivation. The audit chain covers *ledger* events; add an authentication/authorization log (it need not be hash-chained, but it must be append-only and reportable).
2. **The audit trail as a printable system report.** Annex B contemplates the audit trail being *reviewable*; in practice the examiner wants a generated report (filterable by user/date/document) with the mandatory header block, not read-only access to a table. Cheap to build from the existing audit rows.
3. **The registration documentation package.** The AC application is evaluated on documents: system description, process/system flow, sworn statement listing the books, reports and serial ranges the system generates. Prepare this package once as part of the product (it is per-client-registration collateral), so onboarding a client does not require writing it fresh.
4. **Documented backup/restore procedure.** Asked for in reviews; you have the capability (per-tenant backups in the ops spec) — write the client-facing one-pager.

One clarification worth making in the reviewer's copy: a separate **demo/sandbox tenant** used for BIR evaluation or user training is *not* a "training mode" suppression feature — it is a distinct database with no production data. Say so explicitly, because "no training mode" wording can confuse an examiner who expects to be given a demo environment.

**Software impact:** items 1–2 are new (modest); items 3–4 are documentation.

---

### Q12 — Book codes and serial formats

**Answer: ✅ Confirm as drafted.**

- Yes, the series must correspond to what is declared: the sworn statement filed at **AC registration** lists the books, documents and their serial ranges/formats. What the system generates must match that declaration — but the *format itself is not prescribed* by issuance. Choose it, declare it, never deviate.
- **Per-branch series is required** in substance: invoices are registered per head office/branch, and embedding or associating the branch code with the series is universal practice. The current per-branch design is right.
- **Continuation on migration is correct** (RMC 77-2024 territory: system-generated, sequential, unique, non-reusable; configurable starting serial so a migrating client's series carries on). Declare the starting number in the registration papers.
- Never resetting at year end: correct. Voids retaining their number: correct.

**Software impact:** none. Process note: the registration sworn statement must be generated from the *actual* configured series so declaration and behavior cannot drift.

---

### Q13 — "Gapless" numbering and the ACCN on the document face

**Answer: ✏️ Gapless = convention (keep the behavior). ACCN on the face = REQUIRED — print it.**

- **Gapless:** confirmed that no issuance uses the word. The actual requirements are **sequential, unique, non-reusable** serials with every number accounted for — which voided-but-retained numbers satisfy. Gapless generation remains the right engineering behavior (a gap you cannot explain is an audit finding even if "gapless" is nobody's rule). Keep it as a design principle; do not present it to clients as a legal requirement.
- **ACCN:** this one is real. Under RMC 5-2021, the Acknowledgement Certificate bears a control number that is **indicated on the face of the system-generated principal and supplementary receipts/invoices** (the same convention as the old PTU number). Print it on every invoice, credit note and supplementary document the system issues.
- **Placement:** no prescribed position; universal practice is the **footer block**, alongside the software name/version line the mandatory header/footer already carries — e.g. `Acknowledgement Certificate No. ____ dated ____`. Since go-live is already gated on the ACCN being recorded, the template can safely require it.

**Software impact:** add ACCN + issue date to the document templates and the mandatory header/footer block. Small, but touches every document design — do it before first registration.

---

### Q14 — Chart of accounts and BIR field mappings

**Answer: ✏️ Corrections to the seed; the account-by-account review still belongs to the licensed reviewer.**

Structural corrections to make regardless:

1. **Split the withholding payable accounts by type.** At minimum rename/scope the existing account to **Expanded Withholding Tax Payable**; add siblings only as needed (final withholding, compensation) — even with payroll out of scope, a conflated "withholding payable" account produces returns that cannot be tied to a single form.
2. **Split Input VAT into the 2550Q's substreams** — purchases of goods, purchases of services, importations, capital goods — either as sub-accounts or as a transaction-level tag that the return generator aggregates. The 2550Q asks for the split; a single Input VAT balance cannot produce it. (Same logic as the line-level tagging below.)
3. **Add Percentage Tax Payable** for PT clients (pairs with the Q9 decision to generate the 2551Q).
4. **No Deferred Input VAT account is needed for new activity**: the amortization of input VAT on capital goods over ₱1M applies only to purchases made on or before 31 Dec 2021 (TRAIN sunset); later purchases claim outright. Edge case: a migrating client still amortizing a pre-2022 balance carries it in as an opening-balance item — handle in onboarding, not the chart.
5. **Zero-rated and exempt sales must be tagged at the transaction line, not the account level.** The 2550Q and SLSP need per-transaction VAT classification (12% / zero-rated / exempt); a chart-level mapping cannot express a client who makes all three kinds of sales from one revenue account.
6. Populate the currently-empty BIR attribute columns (tax type, default ATC, FS line) as part of the reviewer's chart sign-off — that is precisely the review the briefing requests and it cannot be done in the abstract.

**Software impact:** seed changes (1)–(4); (5) is a posting-line attribute if not already present; (6) waits on the reviewer.

---

### Q15 — Post-EOPT section numbering and citation hygiene

**Answer: ✏️ Reviewed; two probable resolutions, confirm against the consolidated text. Documentation only.**

- The ₱3M VAT-exempt threshold: TRAIN placed it at Sec. 109(1)(BB); CREATE's insertions shifted the lettering, and post-CREATE consolidations commonly cite **Sec. 109(CC)**. Your ⚠️ on (CC) vs (BB) is probably resolved in favor of (CC), but letter-level citation should be checked against the consolidated post-EOPT NIRC text, not secondary sources.
- **Sec. 110(D):** consistent with being the EOPT-inserted **output-VAT credit on uncollected receivables** provision (implemented by §4.110-9 of RR 3-2024 — see Q5). Same instruction: verify the letter against the consolidated text.
- The practice of excluding ⚠️-flagged citations from anything computation-driving is exactly right; nothing here changes code.

**Software impact:** none. Documentation corrections only.

---

## Part C — Accounting policy

### Q16 — Year-end close mechanics

**Answer: ✅ Confirm as drafted**, with entity-type naming.

- Income Summary → Retained Earnings is textbook and gives a clean single-figure closing trail; closing directly to RE is equally acceptable but there is no reason to change a built, tested rule.
- **Sole proprietorships:** the concepts are the same but the names are not — close **Drawings to Owner's Capital**, and Income Summary to **Owner's Capital** (not "Retained Earnings"). Make the equity account naming entity-type-aware in the seed; the posting rule itself is unchanged.
- **Book-tax differences (MCIT, NOLCO):** correctly outside the books. They are ITR-time computations kept in the tax working papers; only if a client adopts full PFRS with deferred taxes would any of it enter the GL, and that is their CPA's manual entry, not a system rule.

**Software impact:** entity-type-aware equity account names in the chart seed. Posting rule unchanged.

---

### Q17 — Opening Balance Equity and the cutover

**Answer: ✅ Confirm as drafted.**

- OBE-as-plug with a must-clear-to-zero check is the standard, correct cutover mechanism; the "non-zero OBE = unfinished cutover" warning is exactly the right control. Residual clears to Retained Earnings / Owner's Capital.
- **Cutover date:** any period start is *permissible* (no issuance forbids a mid-year system change; the prior system remains the record for its own periods). But make **fiscal-year start the strongly recommended default** with a warning on mid-year cutovers: a mid-year cutover means that year's books span two systems, which is explainable in an audit but is friction the client should choose knowingly.

**Software impact:** none required; optionally add the mid-year-cutover warning text.

---

### Q18 — Cash-basis book structure

**Answer: ⚠️ Qualified confirm — right for the target case, with two hard caveats.**

- **The core position is confirmed:** a genuine cash-basis **service** business legitimately keeps a GL without AR/AP control accounts; BIR prescribes no GL account structure, and aging from the document subledger is fine. The columnar books for such a client are dominated by the cash receipts and cash disbursements journals; that shape is normal and recognizable to an examiner.
- **Caveat 1 — VAT does not follow the client's basis.** Post-EOPT, a VAT-registered client's output VAT is **invoice-basis by law** (Q5) even if their income recognition is cash-basis. The 2550Q, sales journal and SLSP must draw from the **document subledger by invoice date**, never from a cash-basis GL. (This is the same invariant as fixture finding #6 — see §3.) A VAT-registered cash-basis client therefore lives with a permanent, explainable GL-vs-VAT-return divergence; most such clients are better advised onto accrual, which the reviewer may want the onboarding flow to say.
- **Caveat 2 — inventory clients cannot be cash basis.** Where inventories are an income-determining factor, the accrual method is required for purchases and sales for income-tax purposes. The system should **block (or at minimum hard-warn on) enabling the inventory module for a cash-basis client.** This combination check does not currently exist and should.

**Software impact:** the basis × VAT-registration × inventory compatibility matrix becomes explicit: cash+inventory blocked; cash+VAT allowed with the subledger-driven 2550Q and an advisory.

---

### Q19 — Inventory: costing declaration and spoilage

**Answer: (a) ✅ Confirm. (b) ✏️ Documentation IS required — RMO 21-2020.**

- **(a) Costing method:** moving weighted average is an accepted method; it is *disclosed* (in the FS notes and reflected in the ITR) rather than separately "declared" to the BIR. The consistency requirement is real: NIRC Sec. 41 requires the inventory method to conform to best accounting practice and be **consistently applied**, and a change of method requires **prior BIR consent**. Product consequence: per-client costing method is fixed once transactions exist; a change is an application-to-BIR event, not a settings toggle — which matches how the system already behaves.
- **(b) Spoilage/destruction:** for a write-off to be **deductible**, the RMO 21-2020 process applies: an **application for destruction/disposal filed with the BIR before the fact** (the RMO's notice window — verify the current day-count with the reviewer), destruction **witnessed** by a BIR officer or an authorized third party (virtual witnessing is provided for), and a **Certificate of Deductibility** issued afterward. Reason-coding alone does not make the deduction safe.
- Recommended behavior: do **not** hard-block the write-off (the stock reality must be bookable regardless); instead attach a destruction-documentation record to spoilage/damage write-offs — application date/reference, witnessing details, certificate number — and warn prominently when a spoilage write-off lacks it, plus a year-end exception report of undocumented write-offs for the client's CPA.

**Software impact:** destruction-documentation fields + warning + exception report. Costing behavior unchanged.

---

### Q20 — Found stock

**Answer: ✅ Confirm as drafted** (credit Inventory Shrinkage — net presentation).

- Netting count variances through a single shrinkage account is standard SME practice, keeps the shrinkage-trend report meaningful, and presents one honest net figure rather than gross expense plus gross "income" that invites questions about what the income was.
- Other Income is defensible only for genuinely unusual, material discoveries — which in practice are almost always **cutoff or receiving errors**, not windfalls. Sensible refinement: flag unusually large positive variances for investigation before posting (they usually mean an unrecorded receipt, and the correct fix is the missing document, not a count adjustment).
- The per-item override already built covers the exceptions.

**Software impact:** none; optional large-variance advisory.

---

### Q21 — Cash-flow activity classification

**Answer: ✅ Deferring was right; indirect method confirmed for the eventual build.**

- The **indirect method** is the expected SME presentation (it is what PFRS for SMEs preparers and their auditors produce in practice; the direct method is rare outside regulated entities).
- The operating/investing/financing tagging belongs with the **chart-of-accounts review (Q14)** — supply both in the same sitting, since the classification is a property of the same seed. Tag it in the seed data now-empty columns when the reviewer signs the chart, so the future feature is a rendering task, not a data-collection task.
- "No statement is better than a plausible wrong one" is the correct product principle and worth keeping in the reviewer-facing material.

**Software impact:** none today; add the activity-classification column to the chart seed schema when convenient.

---

## 3. Part D — Q22: fixture-by-fixture review

**Answer: ✏️ Arithmetically and structurally correct throughout; two treatment notes, one invariant to make explicit.**

| Fixture | Verdict | Notes |
|---|---|---|
| S1 — opening balances | ✅ | Balanced (550,000 = 550,000); dating the opening JE 2025-12-31 for a 2026-01-01 go-live is fine; ties to the Q17 OBE mechanism |
| S2 — VATable sale, accrual | ✅ | Dr A/R 11,200 / Cr Sales 10,000 / Cr Output VAT 1,200 — correct |
| S3 — same invoice, cash basis | ⚠️ | No JE at invoicing is right for cash-basis *income*. But the "VAT snapshot for later recognition" wording must not mean the *return* waits: post-EOPT the 2550Q draws output VAT at **invoice date** from the snapshot (see invariant below) |
| S4 — collection with 2% CWT (accrual) | ✅ | Dr Cash 11,000 / Dr CWT asset 200 / Cr A/R 11,200 — correct. EWT base is correctly the **VAT-exclusive** ₱10,000 (2% × 10,000 = 200) — state in the fixture that this is by rule, not convenience. Premise (customer withholds WC160 at 2%) holds only while the *customer* is a TWA — fine as written since the fixture says so |
| S4 — cash-basis rows | ⚠️ | Correct as **GL** entries (revenue + output VAT recognized at collection in cash-basis books). Same invariant applies: the 2550Q must NOT read these GL dates |
| S5 — vendor bill with 2% EWT | ✅ | Dr Expense 5,000 / Dr Input VAT 600 / Cr A/P 5,500 / Cr WTP 100 — correct; EWT on net base correct; accrual at booking per the (verified) RR 4-2024 payable-date rule. Citation in the fixture header is fine as-is |
| S6/S7 — adjusting entry + reversal | ✅ | Mirror-reversal netting to zero on every account — correct |
| S8 — year-end close | ✅ | Income Summary mechanics correct; the CPA-decision cross-reference to `06` is appropriate; add the sole-prop naming nuance from Q16 when the seed changes |
| S9 — month close | ✅ | Period-lock rejection, roll-forward, cache-equals-recompute assertions are the right invariants |
| S10 — inventory cycle | ✅ | Weighted average ties exactly: (100×50 + 100×60)/200 = 55; COGS 50×55 = 2,750; ending 150×55 = 8,250; variance −2×55 = 110; GL 148×55 = **8,140** ✔; net-to-shrinkage consistent with Q20 |
| S-round | ✅ | Control-line-as-sum construction guarantees balance under any rounding policy — correct |

**The invariant to add to the fixture file (finding #6):** *for VAT-registered clients, the 2550Q, sales journal and SLSP are generated from document-subledger VAT data by invoice date, regardless of the client's bookkeeping basis; cash-basis GL entries (S3, S4 cash rows) never drive VAT-return periods.* Without this stated, an implementer will wire the return generator to the GL and every cash-basis client's VAT lands a quarter late.

---

## For counsel — Q23: data privacy vs BIR retention

**Answer: ✅ Reasoning confirmed as drafted — route to counsel for the formal nod, with one qualification.**

- The "required by law" ground is the correct basis: DPA Sec. 12(c) permits processing necessary for **compliance with a legal obligation**, and the NIRC preservation duty (Sec. 235, RR 7-2024) is squarely such an obligation. Retention set by the BIR horizon rather than a minimization horizon is right for records that form part of the books.
- The **right to erasure is not absolute**: it does not defeat processing required by law, and NPC practice recognizes refusing erasure of records retained under a statutory mandate. A data subject's erasure request for names/addresses/TINs embedded in invoices, journals and audit records can be refused for the retention period, with the legal basis stated in the response.
- **Qualification counsel should confirm:** the refusal covers data *forming part of the mandated records* — it does not cover personal data held outside them (marketing contacts, prospect lists, portal accounts of former users). Honor erasure there. Practical hygiene: the privacy notice should state the BIR-retention ground and period explicitly, and after the retention period lapses (absent legal hold) the purge obligation flips — the DPA then *requires* disposal, which the configurable retention + legal-hold design already supports.
- The hash-chain concern (removing personal data breaks the chain) is precisely why the correct posture is refusal-during-retention rather than redaction-in-place. No crypto-erasure mechanism needs to be built now; note it as a designed-deliberately-later item exactly as the briefing does.

---

## 4. Items the licensed reviewer must still decide

Even with everything above, these need a practitioner's signature rather than research:

1. **Q2 — the seeded ATC table itself.** Filed-output-critical; must be confirmed against the current eBIRForms library by someone who files with it, including the commissions correction and the government-code guard.
2. **Q14 — the chart-of-accounts review.** Judgment on the actual seed, account by account, plus the BIR attribute columns and (per Q21) the cash-flow activity tags.
3. **Q3 — mid-year ₱3M threshold breach.** Whether the payor applies catch-up withholding on the next payment or purely prospective rates; practice varies.
4. **Q8(c) — SLSP presentation of credit notes.** Netted vs negative-line is a file-format behavior of the current validation module; confirm with someone who submits SLSPs today.
5. **Q19(b) — the current RMO 21-2020 notice window and witnessing practice** (day counts and virtual-witnessing acceptance drift with RMO updates).
6. **Q23 — counsel sign-off** on the erasure-refusal position, as already planned.

Everything else in this document should be a confirm-tick for the reviewer — which is what the briefing was designed to achieve.

---

## 5. Sources

Primary issuances:

- [RR 3-2024 — VAT and percentage-tax amendments under EOPT (BIR)](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%203-2024%20(final).pdf)
- [RR 7-2024 — registration, invoicing, preservation of books (BIR)](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%207-2024.pdf)
- [RR 31-2020 — TWA criteria (BIR)](https://bir-cdn.bir.gov.ph/local/pdf/RR%20No.%2031-2020.pdf) and [NTRC digest](https://www.ntrc.gov.ph/images/BIR/RR/2020/RR%2031-2020%20digest.pdf)

Practitioner commentary used for verification:

- [Grant Thornton — EOPT amendments on VAT and percentage tax (RR 3-2024)](https://www.grantthornton.com.ph/alerts-and-publications/technical-alerts/tax-alert/2024/implementation-of-the-amendments-introduced-by-eopt-act-on-vat-and-percentage-tax/)
- [Grant Thornton — output VAT credit on uncollected receivables](https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/eopt-is-here-bir-clarifies-availment-of-output-vat-credit-on-uncollected-receivables/)
- [Grant Thornton — EOPT preservation of books and registration changes](https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/eopt-is-here-updates-on-the-preservation-of-book-of-accounts-and-changes-in-taxpayer-registration/)
- [MPM Consulting — RR 4-2024 amendments on filing and paying taxes](https://mpm.ph/rr-4-2024/)
- [KPMG PH — At Ease: RR 4-24 and its impact](https://kpmg.com/ph/en/home/insights/2024/06/at-ease-rr-4-24-and-its-impact-on-individual-taxpayers.html)
- [PwC PH Tax Alert No. 8 — RMC 5-2021 (Acknowledgement Certificate / ACCN)](https://www.pwc.com/ph/en/tax/tax-publications/tax-alerts/2021-tax-alerts/tax-alert-8.html)
- [CloudCFO — What happens when you become a Top Withholding Agent](https://cloudcfo.ph/blog/what-happens-when-you-become-a-top-withholding-agent)
- [Tax and Accounting Center — Tax on deposits and advances](https://taxacctgcenter.ph/tax-on-deposits-and-advances-to-firms-in-philippines/)
- [Respicio & Co. — VAT computation on return and refund transactions (Sec. 106(D))](https://www.respicio.ph/commentaries/vat-computation-on-return-and-refund-transactions-in-the-philippines)
- [eBIRForms ATC list — 1601-EQ annex (reference copy)](https://www.scribd.com/document/401684512/ATC-Alphanumeric-Tax-Codes-ebirforms-v7-2-1601EQ)

Cross-references within this repo: [`cpa-briefing.md`](cpa-briefing.md) (the question set), [`specs/03-bir-accreditation.md`](specs/03-bir-accreditation.md) (citation reference), [`specs/06-decisions.md`](specs/06-decisions.md) (decision log these answers feed), [`fixtures/scenarios.md`](fixtures/scenarios.md) (the fixtures reviewed in §3).
