# BIR Accreditation & Compliance — Engineering Reference

*Purpose: a build-facing reference consolidating the BIR registration, invoicing, VAT, withholding, books, returns, and penalty rules a Philippine accounting-SaaS must satisfy, current as of 2026 (EOPT era, RA 11976). Every rule is tagged with its issuance (RR/RMC/RMO number + date, or NIRC section) after adversarial fact-checking; unverified claims are marked ⚠️.*

**⚠️ This is NOT legal or tax advice.** It is an engineering digest of fast-moving BIR issuances assembled from primary and advisory sources. Several figures/dates carry live conflicts (flagged inline and in "Open items"). **A Philippine CPA / tax lawyer MUST confirm every rule that drives a computation, deadline, or compliance gate before go-live.**

---

## TL;DR — what the software MUST do to be BIR-registrable

1. **Register the system, don't seek a permit.** Ship a one-submission "registration pack" (sworn statement, system narrative, sample invoices, sample books) so the customer gets an **Acknowledgement Certificate (AC)** — the Permit-to-Use is dead (RMC 5-2021, RMO 9-2021). Store the **AC control number (ACCN)** as a required field and block/loudly-warn on production use until it's recorded.
2. **Print "Invoice" as the single principal VAT document** for both goods and services; never emit "Official Receipt" as a principal/VAT document — OR is supplementary-only (RR 7-2024).
3. **Append-only ledger.** Posted transactions can be **voided but never modified or hard-deleted**; corrections are reversing/contra entries (RMC 5-2021 Annex B item 10).
4. **Tamper-evident, printable audit trail** capturing user ID + timestamp + activity for every create/void, protected from modification and destruction (RMC 5-2021 Annex B items 8, 11(i), 11(s)).
5. **Gap-free, unique, monotonic serial numbers** per document type per branch/machine; voids retain the burned number; migration continues the prior series (no reset) (RR 18-2012; RMC 77-2024).
6. **Mandatory report header/footer** on every book, FS, and report: registered name, address, VAT/NON-VAT TIN + branch code, **software name + version**, generating user, date-time stamp (RMC 5-2021 Annex B item 4).
7. **Generate the standard books** (General/Sales/Purchase journals, General Ledger, Inventory book) with BIR-mandated columns, exportable to **`.csv`/`.dat`** (RR 16-2006) AND print-faithful PDF.
8. **Two VAT ledgers** (output/input), net per quarter to **2550Q** only (no mandatory monthly 2550M), carry forward excess input VAT; rate is a **date-effective config** (12%, never hard-coded).
9. **EWT accrual at "payable" (booking/invoice) date, not payment**; drive rates from a **versioned ATC-rate table**; generate 2307, 0619E, 1601EQ+QAP, 1604E+alphalist.
10. **Emit BIR e-files as byproducts**: SLSP RELIEF `.dat`, alphalist `.dat`, and (for EIS-mandated taxpayers) structured e-invoice/sales transmission to the BIR EIS.
11. **Data residency**: keep a Philippines-resident readable backup copy; immutable/WORM backups with restore-testing (RR 9-2009 §6).
12. **Retention with legal hold**: preserve system-generated books electronically for **≥5 years** (statutory, RR 7-2024) — engineer conservatively (see retention conflict) with a hold flag that suspends purge during any protest/refund/case.
13. **RBAC + session control**: single active session per user, 30-day alphanumeric password rotation, lockout, least privilege, TLS remote access (RMC 5-2021 Annex B item 11).
14. **No suppression features**: no "training mode," hidden delete, or reset-to-zero that could erase recorded sales (NIRC Sec. 264-B).
15. **Version the tax engine**: flag releases touching tax computation / invoice content / financial output as **"major enhancement"** so tenants know to surrender the old AC and re-register.

---

## 1. CAS Registration / Accreditation

- **Permit-to-Use (PTU) is abolished; registration yields an Acknowledgement Certificate (AC).** RMC 10-2020 (6 Feb 2020) suspended the PTU; the customer now submits documents and receives an AC — no system demo, no pre-evaluation. (RMC 10-2020; RMC 5-2021.)
  **Build implication:** No "await BIR system demo/approval" gate; the deliverable your customer receives is an AC with a control number.
- **The AC is not an approval of your software's correctness** — it only acknowledges registration; the taxpayer stays fully liable.
  **Build implication:** Market as "BIR-registrable/compliant," not "BIR-accredited/approved"; bake compliance in — there is no BIR pre-approval catching errors.
- **Core policy — RMC 5-2021 (dated 28 Dec 2020, circularized Jan 2021):** users of CAS, Computerized Books of Accounts (CBA), or components (e-storage, middleware) need no PTU; they register by submitting the Checklist of Documentary Requirements (CDR) to their RDO/LT Office; **AC issued within 3 working days** of complete documents.
  **Build implication:** Build an export that assembles the full CDR in one bundle (PDF-uploadable) so the customer clears the 3-day window in one submission.
- **Operational RMO — RMO 9-2021 (issued 19 Feb 2021):** procedures for processing/issuing the AC; "no system demonstration nor pre-evaluation"; defines **major vs minor enhancement**; documentary requirements in Annexes A (CDR), B (functional/technical requirements), C/C-1 (sworn statement + system description).
- **Large-Taxpayer CAS mandate — RR 9-2009 (23 Dec 2009):** it is **mandatory for Large Taxpayers (classified under RR 1-98) to adopt a CAS** (original registration deadline 31 Dec 2009). Non-Large taxpayers may voluntarily choose any of the four modes.
  **Build implication:** Default Large Taxpayers to CAS; keep a CBA-only mode possible for SMEs.
- **Major enhancement (functionality change with direct financial effect) → surrender the old AC and secure a new one; minor enhancement → written notification to the RDO only.** (RMO 9-2021; RMC 5-2021.)
  **Build implication:** Version the engine; flag releases that touch tax computation, invoice content, or financial output as "major" and prompt affected tenants to re-register; keep per-tenant AC re-issuance history.
- **EOPT (RA 11976 — signed 5 Jan 2024, published OG 7 Jan 2024, effective 22 Jan 2024)** collapsed the OR/Sales-Invoice split into a single "Invoice" (RR 3-2024, 11 Apr 2024; RR 7-2024, posted 12 Apr 2024; transitory RR 11-2024, 13 Jun 2024). This qualified as a **major system enhancement** requiring re-registration: surrender the prior AC/PTU and obtain a new AC, with reconfiguration/re-registration **on or before 31 Dec 2024** (RR 11-2024), plus a discretionary **6-month extension to ~30 Jun 2025** with Regional Director / ACIR-LTS approval. The EOPT re-registration mechanic is confirmed by RR 11-2024 and **RMC 91-2024 (issued 14 Aug 2024)**.
  **Build implication:** Ship EOPT-compliant invoicing as a clearly delineated "major" release with attachable release notes; treat the deadlines as historical but keep the AC/registration-date fields editable.
- **₱500 Annual Registration Fee (ARF) abolished, effective 22 Jan 2024** (RR 7-2024 implementing amended NIRC Sec. 236).
  **Build implication:** Remove any ARF / BIR Form 0605 workflow and reminders.
- **Books preservation:** system-generated (computerized) books kept in **electronic copy**; statutory period is **5 years** under Sec. 235 as amended by EOPT (RR 7-2024) — see the retention conflict in Open items.
  **Build implication:** Enforce an immutable electronic archive with a legal-hold flag that suspends purge when a case is flagged.
- **ORUS online registration + QR-Code stamp — RMC 3-2023 (10 Jan 2023).** Books register online via ORUS; proof is a QR stamp (pasted on the first page for manual/loose-leaf; printed and kept on file for computerized). CAS/CBA registration and AC cancellation are being brought into ORUS (BIR Citizen's Charter "External Service").
  **Build implication:** Assume submission is an ORUS web form + document upload; format CDR outputs as uploadable PDFs; support printing/affixing the QR stamp.
- **RMC 65-2025:** new registrants may choose Manual, Loose-leaf (LLBA), or Computerized (CBA); LLBA needs a **PTU-Loose-Leaf**, CBA/CAS needs an **AC secured before use**; these need a TIN first, so they **cannot be issued simultaneously with the TIN**.
  **Build implication:** Onboarding wizard must block "go live on CAS/CBA" until a valid AC number/date is recorded; provide a manual-books fallback for the gap.
- **RMC 4-2026 (issued 15 Jan 2026 — corrected from the "16 Jan" advisory publish date)** extended ORUS deadlines due to technical issues: **loose-leaf/invoices to 31 Jan 2026, CBA to 17 Feb 2026**; manual RDO registration allowed only when ORUS is down (with proof). (Predecessor: RMC 6-2025, dated 16 Jan 2025.)
  **Build implication:** Store the AC/registration date as editable; surface deadline reminders but never hard-code deadlines — they shift by RMC almost yearly.
- **Registration matrix:** Manual → QR stamp before use (no annual re-registration); Loose-leaf → PTU-Loose-Leaf; CBA → AC; CAS → AC. Mandatory CAS for Large Taxpayers.
  **Build implication:** Model registration "mode" per tenant because the required artifact differs (QR stamp vs PTU vs AC).

⚠️ **Do not use in the build:** the "**2 Oct 2025 full ORUS rollout for CAS**" date — unverified secondary-aggregator claim; ORUS handling of CAS AC registration is real, but that specific date is not substantiated.

---

## 2. System / Technical Requirements imposed on the software

Governing stack: **RR 9-2009** (electronic recordkeeping, 23 Dec 2009), **RR 16-2006** (standard export format, 20 Oct 2006), **RR 17-2013 / RR 5-2014** then **RR 7-2024** (retention), and **RMC 5-2021 Annex "B"** (the concrete functional/technical checklist — item citations below were verified against primary text).

### Audit trail, non-alterability, no silent delete/edit
- **Every posting must trace from source document to the summarized accounts; internal controls must prevent unauthorized addition, alteration, or deletion.** (RR 9-2009 §5.3.2, §5.4.2(c).)
  **Build implication:** Append-only ledger; every posting references its source-document ID.
- **Posted transactions may be VOIDED but NOT MODIFIED; users are prevented from editing data inside system-generated reports and from overriding edits; each record is stamped with the creating user ID; the system auto-totals, cross-checks, and flags out-of-balance/incorrect computations.** (RMC 5-2021 Annex B item 10(a)–(h) — confirmed verbatim.)
  **Build implication:** Corrections = reversing/contra entries, never in-place UPDATE/DELETE; stamp `created_by`, immutable `posting_date`; server-side balance validation with out-of-balance guard.
- **The audit trail itself must be protected from modification and destruction; DB record modification must be logged for critical applications.** (RMC 5-2021 Annex B items 11(i), 11(s).)
  **Build implication:** Write-once, tamper-evident (hash-chained or WORM) activity log; no role or admin UI may edit or purge it.
- **The system must produce a printable audit trail / activity log of all transactions.** (RMC 5-2021 Annex B item 8.)
  **Build implication:** Exportable, printable log keyed on timestamp + user + action. *(Note: the finer "before/after values" detail is a reasonable synthesis of items 4 & 10, not verbatim item 8 — treat as design guidance.)*

⚠️ **The "no white-out / superimposition / computer-generated line-delete = prima facie violation" rule** appears only in practitioner guides, is **not** in RR 9-2009, and traces (unconfirmed) to the 1947 Bookkeeping Regulations (RR V-1). **Build implication:** adopt "no destructive edits, everything logged" as the design rule, but do **not** cite RR 9-2009 for that phrasing in product/legal copy without CPA-lawyer confirmation.

### Retention (electronic records)
- **Current statutory period: 5 years** from the day following the filing deadline (or actual late-filing date) for the taxable year of the last entry; computerized books in electronic copies; extended while a protest/refund is pending. (RR 7-2024 implementing Sec. 235 NIRC as amended by RA 11976.)
- **Prior rule (still literally cited in CAS forms): 10 years, 5 hardcopy + 5 electronic** (RR 17-2013, 27 Sep 2013, as amended by RR 5-2014). **RMC 5-2021 Annex B item 7 still says "10 years."** RR 9-2009 §10 floats the period with "whatever Sec. 235 currently says."
  **Build implication:** Make retention a config value tied to the "Sec. 235 period"; default conservative (retain the longer horizon) with a litigation-hold flag. This 5-vs-10 conflict is a CPA question (Open items).
- **Electronic Storage System controls (if you offer archival):** integrity/accuracy/reliability; prevent & detect unauthorized create/add/alter/delete; indexing/retrieval; legible hard-copy reproduction on demand. (RR 5-2014 §2-A.)
  **Build implication:** Archival tier needs checksums, index/search, and a faithful PDF/print path.

### Backup & data residency
- **Back up rewritable media to durable media; do not overwrite prior-period backups/logs; label & log media (records covered, software name + version, retention); periodic restore-testing.** (RR 9-2009 §6.1.2–§6.1.4.)
  **Build implication:** Immutable/WORM (object-lock) backups, a backup catalog with software-version metadata, non-overwriting rotation, scheduled restore-verification.
- **Keep an off-site backup within the Philippines; records accessed from abroad are not treated as "in the Philippines."** (RR 9-2009 §6.2.1–§6.2.3.)
  **Build implication:** Primary + off-site backups in PH regions; foreign-hosted/cloud must still surface a PH-resident readable copy.

### Access control & security (RMC 5-2021 Annex B item 11(a)–(s), confirmed)
- Standard access provisioning/approval; **no same user-ID active on multiple terminals**; lockout/deactivation after failed sign-ons; **passwords changed every 30 days, alphanumeric**; least-privilege; rights revocable on termination/role change; **encrypted remote access**; firewalled/monitored external access; authentication-protected application.
  **Build implication:** RBAC + single-active-session control, account lockout, 30-day password rotation with complexity, TLS everywhere, provisioning/deprovisioning tied to HR status.
- **Maintain exportable business-process documentation:** data flow, internal controls, record layouts, field/code definitions, chart of accounts. (RR 9-2009 §5.4.2–§5.4.3.)

### Books generation & export
- **Generate the standard books with BIR-mandated columns:** General Journal, General Ledger, Sales Journal, Purchase Journal, Inventory Book. (RR 9-2009 §5.4.4; RMC 5-2021 Annex B item 3(a)–(e).) See §3 for column lists.
- **Mandatory report header/footer on every book/FS/report (print + electronic):** registered name; registered address where generated; "VAT REG TIN" / "NON-VAT REG TIN" + 9-digit TIN with **4- or 5-digit branch code**; **software name + version**; generating **user name/ID**; **date-and-time stamp**. (RMC 5-2021 Annex B item 4(a)–(f), verbatim.)
  **Build implication:** One mandatory header/footer component injected into every generated output.
- **Must save books/FS/reports in `.csv` or `.dat`** per RR 16-2006. (RMC 5-2021 Annex B item 5, verbatim.)
- **Standard Audit File for BIR:** convertible to fixed-length flat text, delimited/CSV, or dBase, with **control totals per numeric field**, transaction cut-off date/time, a notarized affidavit of completeness/accuracy, delivered on labeled media; keep the export in a **non-proprietary, commonly-used format**. (RR 16-2006; RR 9-2009 §5.1.2, §7.2.3, §2.1.5c.)
  **Build implication:** Implement a delimited/flat-text exporter with per-field control totals and a machine-readable manifest — **PDF does not satisfy the data-export requirement**; provide both PDF (hard-copy obligation) and `.csv`/`.dat`.
- **e-invoice / sales-data transmission capability** to BIR (EIS under RA 11976). (RMC 5-2021 Annex B item 6.)
- **Produce BIR Forms 2306, 2307, 2316; handle adjustments via serially-numbered supplementary documents.** (RMC 5-2021 Annex B items 9, 12.)

**Supersession note (corrected):** RMC 5-2021 supersedes RMC 10-2020 and inconsistent parts of RMO 29-2002; **RMO 21-2000 and RMO 29-2002 are repealed/superseded by RMO 9-2021**, not directly by RMC 5-2021. *(Exact RR 9-2009 subsection numbers cited above are plausible-but-unconfirmed to the digit — primary full text was unreachable; the underlying requirements are sound.)*

---

## 3. Books of Accounts & Columnar Formats

### Statutory foundation
- **Keep a complete, systematic set of books** (Sec. 232 NIRC). Entities whose **gross annual sales/receipts exceed ₱3,000,000** must have FS audited by an independent CPA + file an AIF with the annual ITR (threshold raised from ₱150,000 by RA 10963 "TRAIN," 19 Dec 2017). **This ₱3M audited-FS threshold (Sec. 232) is distinct from — though numerically equal to — the ₱3M VAT-registration threshold (Sec. 236); both are current in 2026.**
  **Build implication:** Persist a full immutable ledger; a config flag triggers "audited FS + AIF required" once trailing-12-month gross > ₱3M.
- **Subsidiary books, if kept, are part of the official system** and subject to the same rules (Sec. 233). **Books in native language, English, or Spanish; other languages need a certified sworn translation** (Sec. 234). **Preservation per Sec. 235** (see §2 retention — 5-year statutory vs 10-year legacy conflict).

### The books
Non-VAT taxpayers keep **4** (General Journal, General Ledger, Cash Receipts, Cash Disbursements). **VAT taxpayers add the Sales Journal and Purchase Journal**, which double as the **subsidiary sales/purchase journals** required by **Sec. 113(B) NIRC and RR 16-2005 (Consolidated VAT Regs, 19 Oct 2005).** *No single modern RR fixes the exact column grid for the general journals/ledgers — that derives from the old Bookkeeping Regulations + practice; only the VAT subsidiary journals have statutorily-mandated content.*

| Book | Mandated / conventional columns |
|---|---|
| **General Journal** | Date · JE/Reference No. · Account titles Dr/Cr · Particulars · Document reference (SI/check/import-entry no.) · Debit · Credit |
| **General Ledger** | Date · Journal page/JE ref · Particulars · Debit · Credit · Running balance |
| **Cash Receipts** | Date · Invoice/reference no. · Payor/particulars · Dr Cash · credit-distribution cols (Sales, A/R, other); VAT: Taxable base (net) · Output VAT · Zero-rated · Exempt · Gross |
| **Cash Disbursements** | Date · Check/voucher no. · Payee/particulars · Cr Cash · debit-distribution cols; VAT/WH: Input VAT · EWT code · tax base |
| **Sales Journal (VAT subsidiary)** | Date · Invoice/ref no. · Customer name · **Customer TIN** · Address · Description · Gross · Discount · Net/VATable (taxable base) · **Output VAT (12%)** · Zero-rated · Exempt · Total |
| **Purchase Journal (VAT subsidiary)** | Date · Invoice/ref no. · Supplier name · **Supplier TIN** · Address · Description · Gross · Discount · Net/VATable · **Input VAT** · Exempt/zero-rated · EWT code/amount · Total |

**Build implication:** Capture customer/supplier **TIN + address at line level** and segregate 12% / 0% / exempt bases into distinct columns — these journals are the direct source for the VAT return and SLSP.

**Correction (EOPT modernization):** the primary source document on the invoice/journal side is now the **Sales/Service Invoice**, not the Official Receipt. Post-RR 7-2024, the OR is a **supplementary** reference only, and ORs issued after **31 Dec 2024** are not valid input-tax support. Do not model "OR" as a primary source field.

- **Capital-goods input-VAT amortization sub-ledger** (RR 16-2005) is **legacy-only**: TRAIN phased out input-VAT amortization for capital-goods purchases from **1 Jan 2022** (full input VAT creditable outright thereafter).
  **Build implication:** Implement the amortization sub-ledger only for legacy/edge cases.

### Formats, registration & electronic submission
- **Three formats: Manual, Loose-leaf, Computerized (CBA/CAS).** Your SaaS is a CBA/CAS — design to CAS rules (RR 9-2009).
- **No PTU → AC** (RMC 5-2021, 28 Dec 2020; RMO 9-2021). This superseded the PTU approach; **RMC 68-2017** was principally a **loose-leaf** PTU circular that noted CAS PTU applications routed to the National Accreditation Board.
- **ORUS registration + QR stamp** (RMC 3-2023, 10 Jan 2023), clarified under EOPT by **RMC 91-2024 (issued 14 Aug 2024)** implementing RR 7-2024 as amended by RR 11-2024. Deadlines: **Manual** — before use, no annual re-registration; **Loose-leaf** — within **15 days** after close of taxable year; **Computerized (CBA/CAS)** — within **30 days** after close of taxable year (annual).
  **Build implication:** Generate the year's soft-copy book set at year-end and prompt ORUS registration within 30 days of fiscal year-end.
- **Physical submission eliminated** (no transmittal letter + USB) after ORUS registration (RMC 91-2024). 2026 relief: **RMC 4-2026 (15 Jan 2026)** extended loose-leaf → 31 Jan 2026, computerized → 17 Feb 2026.
  **Build implication:** Drop any "burn to DVD/USB and mail to RDO" flow; retain soft copies internally for the retention period and rely on ORUS + QR stamp.
- **SLSP** (quarterly Summary Lists of Sales/Purchases) in **`.DAT`/RELIEF format**, sourced from the Sales/Purchase Journals; **mandatory for all VAT taxpayers per RR 1-2012 (effective 1 Jan 2012)**, amending Sec. 4.114-3 of RR 16-2005.
  **Build implication:** Capture invoice-level TIN/address/VAT at entry so the `.DAT` export is a journal byproduct.

---

## 4. Invoicing & Receipting (Post-EOPT)

- **The Invoice (Sales/Service Invoice) is the single principal document evidencing a sale of BOTH goods and services**; EOPT deleted "receipt" as primary proof for services (amending NIRC Secs. 113, 235, 237, 238). (RA 11976; RR 7-2024, issued 11 Apr 2024, effective 27 Apr 2024.)
  **Build implication:** One principal document type ("Invoice") drives output/input VAT and revenue recognition for every line — do not gate VAT logic on a goods-vs-services document split.
- **The Official Receipt is reclassified as a supplementary document** (proof of collection, not of sale) and is **NOT valid to support input VAT**. (RR 7-2024; RR 11-2024; RMC 77-2024, issued 11 Jul 2024.)
  **Build implication:** Treat ORs/Collection Receipts as non-tax "payment receipt" records; never let a supplementary document post VAT or feed VAT returns.
- **Output tax accrues when the invoice is issued, regardless of collection** (services moved from cash/collection to accrual/"billed" basis). *Attribute the accrual/VAT mechanics to **RR 3-2024** (11 Apr 2024), the VAT/percentage-tax regulation — not RR 7-2024.*
  **Build implication:** Recognize Output VAT at invoice date for services (Dr A/R, Cr Sales, Cr Output VAT) — do not defer to payment/OR.

### Transition rules (historical, window closed by 2026)
- Unused ORs could (1) continue as **supplementary** — stamped **"THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX"** — or (2) be **converted to invoices** (strike "Official Receipt," stamp "Invoice"/"Cash/Charge/Credit/Billing/Service Invoice"). (RR 7-2024; RMC 77-2024.)
- Manual/loose-leaf converted ORs are valid **"until fully consumed"** — **RR 11-2024 (13 Jun 2024) removed the original 31 Dec 2024 hard cutoff.** Inventory Report of unused ORs was due **31 Jul 2024** (extended from the initial 30-day window). CRM/POS/CAS reconfiguration deadline was **31 Dec 2024, extendable ~6 months (to ~30 Jun 2025)** with RD/ACIR-LTS approval.
  **Build implication:** Do not hard-code a 31 Dec 2024 expiry for converted docs; a compliant 2026 CAS/POS must never emit "Official Receipt" as a principal/VAT document.

### Mandatory fields of a valid VAT invoice (NIRC Secs. 113(B) & 237 as amended; RR 7-2024 Sec. 3(B)/Sec. 6)
1. Statement that the seller is **VAT-registered**, followed by the seller's **TIN incl. branch code**.
2. Seller's **registered name**.
3. Seller's **registered business address**.
4. **Date** of transaction.
5. **Invoice serial number** (printed prominently).
6. **Quantity, unit cost, description** of goods/nature of service.
7. **Breakdown into VATable / VAT-exempt / zero-rated** components, where applicable.
8. **VAT amount as a separate line item.**
9. **Total amount payable, with indication that it includes VAT.**
10. The words **"VAT-exempt sale"** or **"zero-rated sale"** printed prominently for such transactions.
11. For sales **≥ ₱1,000 to a VAT-registered buyer**: the buyer's **registered name, address, and TIN**.

**Build implication:** Enforce as non-skippable validation; conditionally require buyer TIN/name/address when `buyer_is_VAT_registered AND amount >= 1000`.

- **"Business Style" is NO LONGER a required field** (RR 7-2024; RMC 77-2024). **Build implication:** Make trade/store name optional and non-validated.
- **Issuance threshold raised from ₱100 to ₱500, CPI-indexed every 3 years** (RA 11976 amending Sec. 237; RR 7-2024/11-2024). Non-VAT sellers issue when a single sale ≥ ₱500 (or on request); **VAT sellers must issue for every sale regardless of amount.** **Build implication:** Parameterize the ₱500 threshold for non-VAT sellers; always require an invoice for VAT sellers.
- **Five "input-tax-fatal" omissions** — the buyer LOSES the input VAT claim if any is missing: **(a) amount of sales, (b) VAT amount, (c) registered name and TIN of BOTH buyer and seller, (d) description of goods / nature of service, (e) date of transaction** (RR 7-2024 Secs. 3(B)/6; the report's "Sec. 3(D)(3)" internal cite is doubtful — confirm subsection lettering). **Build implication:** Flag invoices missing any of these five as "input-tax-invalid" for the buyer and hard-block issuance for the seller; other missing fields are warnings.

### Serial numbering
- Every invoice carries a prominently-printed serial number; ATP-printed (manual/loose-leaf) invoices are pre-numbered by BIR-accredited printers (BIR Form 1906). (RR 18-2012; RMO 12-2013.)
  ⚠️ **Correction — SUPERSEDED:** the old "printed sets valid for 5 years" ATP expiry was **removed by RR 6-2022 (issued 27 Jun 2022, effective 16 Jul 2022), clarified by RMC 123-2022.** **Do NOT build a 5-year ATP-expiry warning** — printed invoices/receipts no longer expire after 5 years.
- **On migration, invoice serial numbering must CONTINUE the prior OR series (no reset to 1)**; file a notice of the new starting serial within 30 days of reconfiguration (or by 31 Dec 2024). (RMC 77-2024; RR 11-2024.)
- **CAS/POS numbering is system-generated, sequential/consecutive, unique** (no duplicates, no gaps). The **AC Control Number (ACCN)** should appear on system-generated documents (RMC 5-2021; RMO 9-2021).
  **Build implication:** Generate strictly monotonic, gap-free, non-reusable numbers per registered branch/machine; support a configurable starting serial on migration; persist and print the ACCN; make numbering append-only so voids retain the number.
  ⚠️ No single issuance uses the literal word **"gapless,"** and the exact **"ACCN must appear on the face"** wording is unverified — treat both as design principles pending confirmation before hard-coding as validation rules.

### Principal vs supplementary classification (RR 18-2012 / RMO 12-2013, survives EOPT)
- **Principal** = the Invoice (source document for VAT / input-tax). **Supplementary (not valid for input tax)** = Official Receipt, Collection Receipt, Billing Statement/SOA, Delivery Receipt, Order Slip, Debit/Credit Memo, Purchase Order, Job Order, Provisional Receipt, Acknowledgement Receipt, Bill of Lading, and similar. Both classes still require BIR registration (ATP via Form 1906, or CAS/AC).
  **Build implication:** Keep a `document_class` enum (`principal`/`supplementary`) driving VAT eligibility; register document templates with their ATP/AC metadata even for supplementary types.

### Forward-looking — e-Invoicing / EIS (already in force for 2026)
- **RR 11-2025 (issued 27 Feb 2025 — corrected from "25 Feb")** implements the Electronic Invoicing System (EIS) and electronic sales reporting. **Legal basis is NIRC Secs. 237 & 237-A as amended by RA 12066 (CREATE MORE) — not RA 11976 (EOPT)** (corrected). Initial scope: large taxpayers, LTS taxpayers, e-commerce/digital sellers, exporters, CAS users.
  ⚠️ **Timeline corrected:** **RR 26-2025 (dated 5 Sep 2025) extended the EIS mandatory-compliance deadline to 31 December 2026** (not the ~March 2026 originally circulated).
  **Build implication:** Architect the invoice module to emit structured electronic invoices and near-real-time sales transmission (API/JSON) to BIR EIS, with retry/queue, even for tenants not yet in scope.

⚠️ **Do not cite as authority:** "Ibex Global CTA Case No. 11075" — the docket number/caption is unverified. The underlying principle (missing mandatory invoice fields defeat input-VAT claims) is well-supported by the CTA line of cases, but pull the actual decision before citing.

---

## 5. VAT Computation

- **Standard rate 12%** on gross sales/value of taxable goods, properties, services, lease, and importation. (RR 16-2005; 10%→12% authorized by RA 9337, implemented by RMC 7-2006 effective 1 Feb 2006.)
  **Build implication:** Store the rate as a **date-effective config parameter** — never hard-code 12.
- **VAT payable = Output VAT − creditable Input VAT; excess input VAT carries forward** (NIRC Secs. 110–111; RR 16-2005).
  **Build implication:** Maintain two VAT ledgers per period, net per quarter, carry forward excess input VAT as a running balance.
- **VAT-inclusive fraction:** VAT = total × **12/112**; net = total × **100/112** (also applied when VAT is not separately billed).
  **Build implication:** Support both VAT-exclusive and VAT-inclusive line entry.
- **VAT-registration threshold: ₱3,000,000** gross annual sales/receipts (Sec. 236(G)); at/below = exempt small taxpayer (Sec. 109(CC)). Raised from ₱1,919,500 by TRAIN, effective 1 Jan 2018.
  ⚠️ **Correction:** the **3-year CPI indexation of the ₱3M threshold is a TRAIN (RA 10963) provision, NOT an EOPT/RR 3-2024 one** — RR 3-2024 merely restates it. In practice the scheduled Jan-2020 CPI adjustment was **never implemented**, so ₱3M has stood unchanged.
  **Build implication:** Make the threshold a date-effective config; treat a 3-year revision as *possible*, not automatic/scheduled.
- **3% percentage tax (Sec. 116)** for below-threshold non-VAT persons; **RA 11534 (CREATE) cut it to 1% from 1 Jul 2020 to 30 Jun 2023, reverting to 3% on 1 Jul 2023** (current).
  **Build implication:** Percentage-tax rate is date-effective (1% for the 2020–2023 window, else 3%); model VAT-vs-percentage regime as a per-period attribute.
- **8% optional flat tax (TRAIN; RMO 23-2018):** self-employed/professional under ₱3M and non-VAT may elect 8% on gross sales/receipts + non-operating income over ₱250,000, **in lieu of both** graduated income tax and the 3% percentage tax.
  **Build implication:** If elected, suppress percentage-tax computation entirely; capture the election at the first-quarter return.
- **Zero-rated (0%) vs VAT-exempt — the single most error-prone rule.**
  - **Zero-rated (Secs. 106(A)(2), 108(B)):** taxable at 0%, no VAT passed to buyer, **but the seller's related input VAT stays creditable/refundable** (exports; services paid in foreign currency to nonresidents; qualified sales to registered export enterprises — RR 21-2021, RR 3-2023, RR 10-2025 (issued 27 Feb 2025)).
  - **VAT-exempt (Sec. 109):** outside VAT — no output VAT and **related input VAT is NOT creditable** (becomes cost/expense).
  **Build implication:** Model transaction type as an explicit enum (not a VAT/no-VAT boolean); for exempt lines exclude attributable input VAT from credit. For mixed transactions, implement a three-way input-VAT classifier (attributable-to-taxable / attributable-to-exempt / common) with ratable allocation of common input VAT (RR 16-2005).
- **Recognition timing (EOPT accrual shift):** goods were always accrual (output VAT at sale/delivery, Sec. 106). **Services shifted from collection basis (gross receipts) to accrual/"billed" basis** — output VAT now accrues on billing/invoice regardless of collection. (RA 11976; **RR 3-2024**, issued 11 Apr 2024, effective ~27 Apr 2024.)
  **Build implication:** Recognize service output VAT on invoice/billing date; keep a date-effective switch for pre-27-Apr-2024 periods.
- **Output VAT credit on uncollected receivables — new NIRC Sec. 110(D)** (added by RA 11976, implemented by RR 3-2024, clarified by **RMC 65-2024, dated 14 Jun 2024**). A VAT seller who already remitted output VAT on a sale on account may deduct it in a later quarter once the agreed credit period lapses uncollected. Cumulative conditions: sale after RR 3-2024 effectivity (~27 Apr 2024); on credit with a written credit term + VAT shown separately on the invoice; output VAT already reported in the 2550Q for the period of sale; agreed period lapsed unpaid; not already claimed as a Sec. 34(E) bad debt; itemized (not "various") in the Summary List of Sales; invoice stamped **"Claimed Output VAT Credit"** with a copy to the customer. **Recovery:** if later collected, add the output VAT back in the quarter of collection (invoice stamped **"Recovered"**).
  **Build implication:** Track receivable aging vs. the invoice's credit term; auto-surface eligible uncollected VATable receivables for an output-VAT-credit entry the quarter after lapse; guard against double benefit with the Sec. 34(E) path; auto-reverse on later collection; keep the ~27-Apr-2024 effectivity cutoff in the logic.
- **Rounding:** compute/present VAT to the **centavo (2 decimals)**; VAT shown as a separate item (Sec. 113 / RR 16-2005). **Build implication:** Round per line/invoice to 2 decimals with a consistent policy and carry rounded values into totals so per-line VAT sums reconcile to the return. ⚠️ **No BIR issuance prescribes a specific rounding method** (half-up vs bankers') — Open item.

---

## 6. Withholding Tax (Expanded / Creditable — EWT)

- **EWT is a creditable advance income tax collected at source.** The payor withholds, remits, issues the payee **BIR Form 2307**, and the payee credits it against its own income tax. (NIRC Secs. 57(B) & 58; RR 2-98, 17 Apr 1998, effective 1 Jan 1998.)
  **Build implication:** On the payor side EWT is a liability (withheld-not-remitted); on the payee side a creditable asset (prepaid income tax tied to a 2307). Both roles must be first-class — one tenant is often both.
- **Creditable ≠ final.** Tag every withholding line by regime (**EWT / FWT / WTC / VAT-WH**) — different forms, ATCs, and crediting logic (RR 2-98 §2.57).
- **Timing change (the big EOPT shift):** **RR 4-2024 (issued 11 Apr 2024 — corrected from PwC's "22 March 2024"; posted 12 Apr, effective 27 Apr 2024)** removed the "at time of payment" trigger. The duty to withhold now arises when income **becomes payable** = accrued/recorded as an expense or asset in the payor's books, **OR** upon issuance of the sales invoice, **whichever comes first** ("payable" = due, demandable, or legally enforceable). (Implements RA 11976.)
  **Build implication:** Trigger EWT accrual off the **booking event (bill/invoice recorded)**, not cash disbursement; withholding date = the earlier of book-recording date and invoice date (default accrual). A bill entered one period but paid later withholds in the earlier period.
- **Withholding no longer a condition for deductibility:** EOPT **repealed NIRC Sec. 34(K)** (the repeal was effected by RA 11976 itself; RR 4-2024 implements it by repealing RR 2-98 §2.58.5). The duty to withhold/remit stands, but a deduction is no longer forfeited solely for a withholding lapse.
  **Build implication:** Don't hard-block expense posting on missing withholding — flag/warn and surface penalty exposure separately.

### Common ATC codes & rates (individuals = WI, juridical = WC)
| Payment | Rate | ATC | Basis / condition |
|---|---|---|---|
| Professional/talent/consultancy (individual) | 5% / 10% | WI010 / WI011 | 5% if current-yr gross ≤ ₱3M **and** valid sworn declaration on file; else 10% (RR 11-2018 as amended by RR 14-2018) |
| Professional fees (juridical) | 10% / 15% | WC010 / WC011 | 10% if gross ≤ ₱720,000; else 15% (RR 11-2018) |
| Rentals (real/personal property) | 5% | WC100 / WI100 | RR 2-98 §2.57.2(C) (personal-property rental above the de-minimis floor) |
| Contractors/subcontractors | 2% | WC120 / WI120 | RR 2-98 §2.57.2 |
| TWA → local supplier, **goods** | 1% | WC158 / WI158 | Only if payor is a designated Top Withholding Agent |
| TWA → local supplier, **services** | 2% | WC160 / WI160 | Only if payor is a designated TWA |
| Commissions/brokerage (non-employee) | 5%/10% (ind.), 10%/15% (corp.) | WI139/WI140, WC139/WC140 | ⚠️ code numbers unverified in detail — validate before seeding |

- **Sworn declaration is stateful** (the 5% individual rate depends on the annual "Income Payee's Sworn Declaration of Gross Receipts/Sales" + COR). **Build implication:** Store declaration status + validity window per vendor; default to the higher rate when absent/expired.
- **TWA status is set by BIR's published list, not self-assessment.** RR 31-2020 (issued 18 Dec 2020) sets criteria (₱12M gross sales/receipts/purchases for Groups A/B; ₱5M for Groups C/D/E), but a taxpayer becomes a TWA only when published/notified. **Build implication:** Gate the 1%/2% goods-vs-services ATCs behind a per-tenant "is TWA (effective date X)" flag.
- ⚠️ **Naming trap:** **WC157/WI157** and **WC640/WI640** are **government-payment** ATCs — do not auto-assign them to private-company purchases (misfiling causes alphalist validation failures). **Build implication:** Ship a versioned **ATC-rate reference table** (code → rate → payee-type → threshold → sworn-declaration dependency → legal basis → effective date), seeded from the eBIRForms/eFPS ATC library — never hard-code rates inline.

### Form 2307 & remittance
- **BIR Form 2307 fields** (RR 2-98 §2.58(B) as amended): payee & payor registered name/TIN/address; period covered; one row **per ATC** with the **3 monthly income-payment buckets + quarterly total + tax withheld**; signatures of both parties. Issue **within 20 days of quarter close**, or **simultaneously with payment on request**.
  **Build implication:** Generate 2307 from posted withholding lines grouped by (payee × ATC × quarter) with the monthly-within-quarter structure preserved; support batch and on-demand issuance; on the payee side build a 2307 inbox feeding SAWT and the ITR's "creditable tax withheld" line.
- **Remittance cadence (RR 11-2018):** **0619-E** monthly for the first two months of each quarter, due the **10th** of the following month (eFPS staggered ~11th–15th); **1601-EQ** quarterly, due the **last day of the month after the quarter** (Apr 30 / Jul 31 / Oct 31 / Jan 31), with the **Quarterly Alphalist of Payees (QAP)**; **1604-E** annual, on/before **1 March**, with the annual alphalist.
  **Build implication:** Two monthly 0619-E + one quarterly 1601-EQ (net out the two remittances) + annual 1604-E; generate QAP/alphalist directly in BIR `.DAT` format; maintain a filing calendar with the 10th / last-day / March-1 deadlines and eFPS staggered dates.
- **EOPT did not collapse the 0619-E + 1601-EQ structure.** RR 4-2024's headline changes: timing (accrual), Sec. 34(K) repeal, **file/pay anywhere** (removal of the 25% wrong-venue surcharge), e-filing default.

---

## 7. Returns & Reports the System Must Generate

**Framing correction up front:** the removal of the **mandatory monthly VAT return (2550M)** came from **TRAIN (RA 10963, Sec. 114(A)), operative 1 Jan 2023** — **NOT** from EOPT/RR 3-2024. Implemented by transitory **RMC 5-2023 (3 Jan 2023)**.

### VAT returns
- **2550Q is the sole mandatory VAT return; due within 25 days after quarter close** (Sec. 114(A) as amended by TRAIN).
  **Build implication:** Generate 2550Q per quarter (due quarter-end + 25 days); schedule no mandatory monthly VAT return.
- **Monthly 2550M survives only as an optional early-payment form** for the first two months of a quarter (RMC 52-2023, 10 May 2023); switching monthly↔quarterly carries no penalty, and there is **no prescribed deadline** for the optional monthly filing.
  **Build implication:** Treat 2550M as an optional feature flag, never a compliance blocker; default to quarterly-only.
- **2550Q was revised for EOPT with four new line items — RMC 68-2024 (19 Jun 2024):** Item 35 (Output VAT on Uncollected Receivables), Item 36 (Output VAT on Recovered Uncollected Receivables Previously Deducted), Item 55 (Input VAT on Unpaid Payables), Item 58 (Input VAT on Settled Unpaid Payables Previously Deducted). Offline eBIRForms **v7.9.4.2**; returns hitting items 35/36/55/58 had to be filed on the **manual PDF** until platforms updated.
  **Build implication:** Model these four fields; track BIR form/package versions; flag affected returns for possible manual filing.
- **EOPT VAT mechanics — RR 3-2024 (11 Apr 2024):** uniform "Gross Sales" terminology, single "Invoice," accrual basis, ₱3M threshold CPI-reindex language, mandatory e-filing. (Detailed OR-conversion transitory mechanics live in RR 7-2024.)

### Withholding returns
- **0619E / 1601EQ (+QAP) / 1604E (+alphalist)** — the EWT series created by **RR 11-2018** (see §6 for cadence). Parallel series exist: **1601C / 1604C** (compensation) and **0619F / 1601FQ / 1604F** (final tax) — same cadence logic.
- **EOPT withholding changes:** obligation at "payable" date; **Sec. 34(K) repealed** (deductibility de-linked from withholding).

### Summary Lists of Sales & Purchases (SLSP)
- **All VAT taxpayers file quarterly SLSP within 25 days of quarter close** (aligns with 2550Q); even zero-transaction taxpayers file a "Nil" list. (RR 1-2012 amending Sec. 4.114-3 of RR 16-2005.)
- **SLSP is a validated `.dat` (RELIEF) file** transmitted to **esubmission@bir.gov.ph** (or as an eFPS attachment); keep the system-generated Validation/Acknowledgment Receipt as proof of filing.
  **Build implication:** Emit RELIEF-compliant `.dat` (per-buyer sales; per-supplier purchases with TIN, address, gross, exempt, zero-rated, input VAT), run through the validator, store the acknowledgment.
  ⚠️ Open item: whether RR 1-2012 fully removed the old **₱1M-quarterly-purchases threshold** gating the SLP.

### Alphalists & DAT tooling
- **QAP (with 1601EQ) and the annual Alphalist of Payees (with 1604E) are `.dat` files** from the **Alphalist Data Entry & Validation Module** (v7.0 announced in RMC 7-2021, released 28 Dec 2020; covers 1604-C/E/F, 1600-VT, 1600-PT). Per-payee fields: period, name, TIN, address, ATC, nature of payment, amount, rate, tax withheld.
  **Build implication:** Implement a `.dat` exporter matching the module's schema/field order per form; validate before submit; **verify the current module version at build time** — versions rotate frequently.
- **SAWT (Summary Alphalist of Withholding Taxes)** on the recipient side, generated from received 2307s, attached wherever CWT is claimed.

### Filing channels & "file/pay anywhere"
- **RR 4-2024 (11 Apr 2024, effective ~27 Apr 2024), clarified by RMC 87-2024 (7 Aug 2024):** file and pay **anywhere** — electronically on any platform, or manually to any AAB/RCO regardless of RDO. The **25% wrong-venue surcharge is abolished** (repeal of Sec. 248(A)(2)).
  **Build implication:** Don't tie filing/payment to a specific RDO/AAB; surface all valid e-pay gateways + manual fallbacks; remove the wrong-venue surcharge from the penalty engine (retain late-filing/late-payment surcharge, interest, compromise).
- **Channel eligibility:** eFPS-enrolled keep eFPS; mandated-but-unenrolled use eBIRForms; manual allowed when platforms are down (RR 9-2001; RR 6-2014). ⚠️ **RMC 4-2021** as the eBIRForms/eFPS-mandate authority is **unverified** — confirm before relying on it.
- **EOPT 4-tier classification — RR 8-2024 (effective 27 Apr 2024):** **Micro <₱3M · Small ₱3M–<₱20M · Medium ₱20M–<₱1B · Large ≥₱1B** (gross sales, net of VAT).
  **Build implication:** Store a per-tenant classification attribute (recompute annually) feeding the penalty calculator.

### Deadlines quick-reference (manual/eBIRForms baseline; eFPS staggered later by industry group)
| Return | Frequency | Statutory deadline |
|---|---|---|
| 2550Q (VAT) | Quarterly | 25th day after close of quarter |
| 2550M (VAT) | Optional monthly | **No prescribed deadline (voluntary prepayment)** |
| SLSP (SLS/SLP) | Quarterly | 25th day after close of quarter |
| 0619E (EWT remit) | Monthly (months 1–2 of qtr) | 10th of following month |
| 1601EQ (EWT qtrly) + QAP | Quarterly | Last day of month after quarter (Apr 30 / Jul 31 / Oct 31 / Jan 31) |
| 1604E + Alphalist of Payees | Annual | 1 March of following year |

*(eFPS filers get staggered e-filing dates by industry grouping, historically per RR 26-2002 — confirm the current calendar before hard-coding per-group offsets.)*

---

## 8. Penalties & Common BIR Audit Findings

*Scope: enforcement angle. NIRC = RA 8424 as amended by TRAIN/RA 10963 and EOPT/RA 11976. Statutory criminal fines are far higher than the "compromise" amounts (paid in lieu of prosecution under RMO 7-2015).*

- **Using an unregistered CAS / no AC:** statutory hooks are **NIRC Sec. 255** (failure to comply; fine ≥₱10,000 + imprisonment 1–10 years) and catch-all **Sec. 275** (fine ≤₱1,000 / imprisonment ≤6 months). Major enhancement without re-registration is a finding.
  ⚠️ The commonly-cited **₱25,000 (1st) / ₱50,000 (2nd) compromise penalty** for unregistered CAS is **unverified** — advisory tax-mapping tables only, no numbered primary. Do not present it as fact. **Build implication:** Block/loudly-warn on production use until an AC is recorded; make "registered/not" a first-class tenant compliance state.
- **Broken/absent audit trail:** RR 9-2009 requires audit-trail preservation + internal controls; violations prosecuted under **Sec. 255**. An absent/tamperable trail is treated as inability to substantiate returns → deficiency assessment + **Sec. 248 surcharge + Sec. 249 interest**.
  **Build implication:** Immutable append-only log; corrections as reversing/adjusting entries; lock periods after filing.
- **Gaps/duplicates in serial numbers** are primary audit red flags. **Sec. 264(a):** fine **₱1,000–₱50,000** + imprisonment **2–4 years** (double/multiple invoices). **Sec. 264(b):** fine **₱500,000–₱10,000,000** + imprisonment **6–10 years** (printing without ATP, double/multiple sets, unnumbered documents).
  **Build implication:** Single monotonic gap-free sequence per document type/branch at the DB level; voids retain the burned number + reason; expose a "serial continuity" report; make it structurally impossible to emit unnumbered/manually-numbered official documents.
- **Unregistered receipts/invoices:** statutory **Sec. 264(a)** and **Sec. 237** (advisory compromise ~₱10,000 1st / ₱20,000 2nd — advisory figure, treat cautiously). **OR→Invoice enforcement:** since RR 7-2024 (effective 27 Apr 2024), issuing an OR for a sale is "tantamount to failure to issue" the required Invoice; unused-OR inventory deadline was **31 Jul 2024** (corrected from the initial "30 days"), and converted ORs were valid for input tax until **31 Dec 2024**.
  **Build implication:** Bind every issued document to the registered TIN/branch, ATP/AC reference, and registered series; refuse to print if unregistered; default document type = "Invoice."
- **Failure to register books / late info-return submission:** compromise tiered by gross sales (advisory schedules cite ~₱3,000–₱50,000, from RMO 1-90/56-2000/19-2007 consolidated into **RMO 7-2015**). **Sec. 250:** **₱1,000 per failure to file an information return, aggregate ≤₱25,000 per calendar year** (SLSP, alphalist, eSales). **eSales** (monthly CRM/POS gross-sales report due by the 10th) — RR 5-2005 (17 Feb 2005), with escalating machine sanctions after 3 consecutive misses.
  **Build implication:** Automate `.DAT`/RELIEF generation for SLSP/alphalist/eSales with due-date reminders and a per-period submission log.
- **Failure to transmit sales data to the BIR EIS (mandated e-invoicing taxpayers): Sec. 264-A** (added by TRAIN, implemented by **RR 13-2021**, issued 23 Jun 2021; framework **RR 8-2022 → RR 11-2025**, issued 27 Feb 2025). Penalty: **per day of violation, 1/10 of 1% of annual net income (2nd prior year AFS) or ₱10,000, whichever is higher; permanent closure if >180 days in a taxable year.** EIS mandatory-compliance deadline extended to **31 Dec 2026 by RR 26-2025 (dated 5 Sep 2025).**
  **Build implication:** For EIS-mandated taxpayers, build near-real-time JSON transmission with retry/queue and a per-day failure alarm well before the 180-day cliff.
- **Sales-suppression software ("zappers/phantomware"): Sec. 264-B** (RR 13-2021): fine **₱500,000–₱10,000,000** + imprisonment **2–4 years**.
  **Build implication:** No "training mode," hidden delete, or reset-to-zero feature that could erase recorded sales; document that none exists.
- **General penalty structure:**
  - **Surcharge — Sec. 248:** 25% (late filing/payment/wrong venue) or **50%** (willful neglect / false or fraudulent return).
  - **Interest — Sec. 249 (TRAIN):** **double the BSP legal rate** (currently 6% → **12% p.a.**); no simultaneous deficiency + delinquency double-count. **Build implication:** parameterize the rate (BSP-driven), don't hard-code 12%.
  - **Sec. 255:** fine **≥₱10,000** + imprisonment **1–10 years** — the main penal hook for books/CAS/records non-compliance.
  - **RMO 7-2015 (issued 23 Mar 2015 — corrected from "effective 22 Jan 2015," which is the adoption date):** the Revised Consolidated Schedule of Compromise Penalties (Annex A); compromise is *in lieu of* prosecution; fraud is excluded and referred for criminal action.
  - **EOPT reduced penalties — RR 6-2024 (effective 27 Apr 2024), for micro & small only (correction: NOT a uniform 50%):** **surcharge reduced to 10%** (from 25%); **interest reduced by 50%** (12%→6%); **compromise reduced by 50%** of the RMO 7-2015 schedule; **flat ₱500** administrative fine for late/failed information-return submission (advisory: annual cap ~₱12,500).
  - **ARF removed** (RA 11976 / RR 7-2024) — old ARF-based penalties are moot going forward.
  **Build implication:** Store each tenant's EOPT classification and apply the correct surcharge/interest/compromise rule in any penalty estimator; label surfaced figures as compromise amounts (statutory criminal fines are much higher).

---

## Data the schema must capture from day one

To be compliant later, the software must persist these from first entry — reconstructing them at audit time is not acceptable:

- **Entity identity:** taxpayer registered name, registered address (per branch where reports are generated), **9-digit TIN + 4-/5-digit branch code**, VAT vs NON-VAT status, EOPT taxpayer classification (Micro/Small/Medium/Large), TWA status + effective date, 8%-election flag, VAT-vs-percentage regime per period.
- **Registration artifacts:** **AC control number (ACCN) + date**, registration mode (Manual/Loose-leaf/CBA/CAS), ORUS QR-stamp reference per book set, ATP number/printer/serial range (manual/loose-leaf), PTU-Loose-Leaf number, per-tenant major-vs-minor enhancement / AC re-issuance history, **software name + version** (stamped on every output).
- **Counterparties:** customer/vendor registered name, **TIN + branch code**, registered address — captured at transaction/invoice level (required for VAT journals, SLSP, QAP, 2307).
- **Per-line tax attributes:** VAT transaction type enum (VATable-12% / zero-rated-0% / exempt), input-VAT attribution class (taxable / exempt / common), **tax-type + ATC code per withholding line** with tax base, EWT rate resolved from a versioned ATC table, sworn-declaration status/validity per vendor, credit terms + agreed payment period (for Sec. 110(D) uncollected-receivable VAT credit).
- **Invoice fields:** VAT-registration statement, seller TIN+branch, date, **serial number**, quantity/unit cost/description, VATable/exempt/zero-rated breakdown, VAT as a separate line, VAT-inclusive total, "VAT-exempt/zero-rated sale" labels, conditional buyer name/address/TIN (≥₱1,000 to VAT-registered buyer), `document_class` (principal/supplementary), document status (issued/void + reason + retained serial).
- **Audit-trail fields (per record/event):** creating user ID, immutable posting date + system date-time stamp, activity/action, void/reversal linkage, before/after values (design guidance), tamper-evident (hash-chain/WORM) storage — protected from modification and destruction.
- **Books & reports:** mandatory header/footer block on every generated book/FS/report; `.csv`/`.dat` (RELIEF/Standard-Audit-File) export with per-numeric-field control totals; print-faithful PDF.
- **Retention & residency:** immutable electronic archive with a retention counter tied to the "Sec. 235 period," a **legal-hold flag** suspending purge for open protests/refunds/cases, PH-resident readable backup copy, backup catalog with software-version metadata + restore-test log.

---

## Open items needing a PH CPA / tax lawyer

- **Retention period 5 vs 10 years (live conflict):** RR 7-2024 (EOPT) sets the statutory period at **5 years**, but **RMC 5-2021 Annex B item 7** and RR 17-2013/RR 5-2014 still literally say **10 years** (5 hardcopy + 5 electronic). Confirm the operative CAS obligation; engineer conservatively meanwhile.
- **⚠️ "2 Oct 2025 full ORUS-for-CAS rollout" date** — unverified; confirm the exact issuance that opened CAS AC registration in ORUS.
- **Exact CDR / RMO 9-2021 Annex-A line items** and current Citizen's-Charter wording — confirm against the live CDR-2025 PDF and ORUS form.
- **⚠️ White-out / "computer-generated line-delete = prima facie violation"** — not in RR 9-2009; source unconfirmed (likely the 1947 Bookkeeping Regs). Don't cite RR 9-2009 for it.
- **Exact RR 9-2009 subsection numbers** cited in §2 (primary full text was unreachable) — verify to the digit.
- **VAT rounding method** — no BIR issuance prescribes half-up vs bankers', per-line vs per-invoice; confirm against current 2550Q/eBIRForms behavior.
- **Post-EOPT NIRC renumbering** (e.g., Sec. 109(CC) vs (BB); Sec. 110(D)) and RR 3-2024 internal section numbering (e.g., the "Sec. 4.110-9" cited by one advisory) — verify against the consolidated post-EOPT NIRC.
- **⚠️ Five "input-tax-fatal" omissions internal cite ("Sec. 3(D)(3)")** — likely RR 7-2024 Sec. 3(B)/Sec. 6; confirm the subsection lettering. Substance is confirmed.
- **⚠️ "Gapless" serial wording and "ACCN must appear on the face"** — no single issuance uses these literal terms; confirm before hard-coding as validation.
- **⚠️ "Ibex Global CTA Case No. 11075"** — docket/caption unverified; don't cite as authority.
- **Full current ATC↔rate mapping** (esp. commissions/brokerage WI139/WI140, WC139/WC140) — validate the complete table against the current eBIRForms/eFPS ATC library before seeding.
- **RR 31-2020 TWA thresholds/groupings** (₱12M vs ₱5M by group) and the exact "published list" effective-date mechanics.
- **RR 4-2024 exact effectivity** (posting 12 Apr 2024 → ~27 Apr 2024) and **RR 3-2024 effectivity** — confirm precise dates if load-bearing.
- **SLSP purchases-list threshold** — whether RR 1-2012 fully removed the ₱1M-quarterly-purchases threshold gating the SLP.
- **⚠️ RMC 4-2021** as the eBIRForms/eFPS-mandate authority — unverified; confirm the correct issuance.
- **eFPS staggered filing calendar** (RR 26-2002) — confirm it still applies unchanged in the EOPT era.
- **RR 6-2024 detail** — whether the ₱500 Sec. 250 fine + ~₱12,500 annual cap and the reduced surcharge/interest apply across all deficiency scenarios or only specific ones.
- **⚠️ ₱25k/₱50k unregistered-CAS compromise** and the **books/receipts compromise amounts** (RMO 7-2015 Annex A) — advisory-table figures only; verify against primary and whether the micro/small 50% reduction applies.
- **Sec. 249 interest rate** — verify the prevailing BSP legal rate at time of assessment (drives the 12% figure).
- **EIS covered-taxpayer list & enforcement during the transition to 31 Dec 2026** — confirm scope and technical schema before building the transmission layer.
- **CREATE-era zero-rating/exemption master data** (RR 21-2021, RR 3-2023, RR 10-2025; residential-lease caps; RBE rules) — run a fresh current-year verification pass before shipping.

---

## Sources

### Primary — bir.gov.ph & government (statute / e-Library / NTRC / gazette)
- BIR CDN — RMC 5-2021 (CAS/AC): https://bir-cdn.bir.gov.ph/local/pdf/RMC%20No.%205-2021%20(1).pdf
- BIR CDN — RMO 9-2021 digest: https://bir-cdn.bir.gov.ph/local/pdf/RMO%20No.%209-2021_Digest.pdf
- BIR CDN — RMC 3-2023: https://bir-cdn.bir.gov.ph/local/pdf/RMC%20No.%203-2023.pdf
- BIR CDN — RMC 91-2024 digest: https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2091-2024%20Digest.pdf
- BIR CDN — RMC 65-2025: https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2065-2025.pdf
- BIR CDN — RMC 4-2026 digest: https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%204-2026%20Digest.pdf
- BIR CDN — RR 7-2024: https://bir-cdn.bir.gov.ph/BIR/pdf/RR%207-2024.pdf
- BIR CDN — RR 11-2024: https://bir-cdn.bir.gov.ph/BIR/pdf/RR%2011-2024.pdf
- BIR CDN — RMC 77-2024: https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2077-2024.pdf
- BIR CDN — RR 3-2024: https://bir-cdn.bir.gov.ph/BIR/pdf/RR%203-2024%20(final).pdf
- BIR CDN — RR 6-2024: https://bir-cdn.bir.gov.ph/BIR/pdf/RR%206-2024%20(final).pdf
- BIR CDN — RR 10-2025: https://bir-cdn.bir.gov.ph/BIR/pdf/RR%2010-2025.pdf
- BIR CDN — RMC 68-2024 digest: https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2068-2024%20Digest.pdf
- BIR CDN — RMC 87-2024 digest: https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2087-2024%20Digest.pdf
- BIR CDN — RR 11-2018 digest: https://bir-cdn.bir.gov.ph/local/pdf/Digest%20RR%2011-2018.pdf
- BIR CDN — RR 31-2020: https://bir-cdn.bir.gov.ph/local/pdf/RR%20No.%2031-2020.pdf
- BIR CDN — RR 17-2013: https://bir-cdn.bir.gov.ph/BIR/pdf/75723rr13_17-revised.pdf
- BIR CDN — Annex C Schedule of Compromise Penalties: https://bir-cdn.bir.gov.ph/local/pdf/ANNEX%20C_SCHEDULE%20OF%20COMPROMISE.pdf
- BIR CDN — EOPT flyer: https://bir-cdn.bir.gov.ph/BIR/pdf/flyer-eopt.pdf
- BIR CDN — Citizen's Charter (2026 ed.): https://bir-cdn.bir.gov.ph/BIR/pdf/BIR%20Citizen's%20Charter%20(2026%20Edition)%20final.pdf
- BIR CDN — CDR 2025: https://bir-cdn.bir.gov.ph/BIR/pdf/CDR%20-%202025%20(1).pdf
- BIR — Penalties page: https://www.bir.gov.ph/penalties
- BIR — EIS certification portal: https://eis-cert.bir.gov.ph/
- BIR — 1604E official PDF: https://bir-cdn.bir.gov.ph/local/pdf/1604E%20Jan%202018%20ENCS%20Final2.pdf
- SC e-Library — RR 9-2009: https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/10/48854
- SC e-Library — RR 16-2005: https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/10/52210
- SC e-Library — RR 1-2012 (SLSP): https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/10/50215
- SC e-Library — RR 5-2014: https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/10/78650
- SC e-Library — RR 14-2018: https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/90308
- SC e-Library — RMO 12-2013 (ATP/serials): https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/10/70263
- SC e-Library — RMO 23-2018 (8% option): https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/10/90318
- SC e-Library — RA 10963 (TRAIN): https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/80559
- SC e-Library — RA 11534 (CREATE): https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/93191
- Lawphil — RA 10963 (TRAIN): https://lawphil.net/statutes/repacts/ra2017/ra_10963_2017.html
- Lawphil — RMO 29-2002: https://lawphil.net/administ/bir/rmo/rmo29_02.pdf
- NTRC — TRAIN primer: https://ntrc.gov.ph/images/train/Tax-Changes-You-Need-to-Know-under-RA-10963.pdf
- NTRC — RR 13-2021 (Sec. 264-A/264-B): https://www.ntrc.gov.ph/images/BIR/RR/2021/RR%2013-2021%20RGA.pdf
- KPMG-hosted primary mirror — RMO 9-2021 PDF: https://assets.kpmg.com/content/dam/kpmg/ph/pdf/InTAX/2021/RMO%20No.%209-2021.pdf

### Advisory / practitioner (secondary corroboration)
- Grant Thornton PH — EOPT on CAS (new AC): https://www.grantthornton.com.ph/insights/articles-and-updates1/lets-talk-tax/eopt-on-computerized-accounting-system-cas-to-secure-or-not-to-secure-new-acknowledgment-certificate/
- Grant Thornton PH — EOPT preservation of books / registration: https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/eopt-is-here-updates-on-the-preservation-of-book-of-accounts-and-changes-in-taxpayer-registration/
- Grant Thornton PH — Clarifications on registration procedures (RMC 91-2024): https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/clarifications-on-the-registration-procedures-under-eopt-act/
- Grant Thornton PH — Invoicing requirements under EOPT: https://www.grantthornton.com.ph/insights/articles-and-updates1/lets-talk-tax/invoicing-requirements-under-the-eopt-act/
- Grant Thornton PH — Tiny errors sink big VAT claims: https://www.grantthornton.com.ph/insights/articles-and-updates1/lets-talk-tax/the-invoice-how-tiny-errors-sink-big-vat-claims/
- Grant Thornton PH — RR 3-2024 (VAT/percentage): https://www.grantthornton.com.ph/alerts-and-publications/technical-alerts/tax-alert/2024/implementation-of-the-amendments-introduced-by-eopt-act-on-vat-and-percentage-tax/
- Grant Thornton PH — Output VAT credit on uncollected receivables (RMC 65-2024): https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/eopt-is-here-bir-clarifies-availment-of-output-vat-credit-on-uncollected-receivables/
- Grant Thornton PH — Revised tax filing/payment (RR 4-2024): https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/eopt-is-here-revised-rules-on-tax-filing-and-payment-and-matters-affecting-the-declaration-of-taxable-income/
- Grant Thornton PH — Optional monthly VAT (RMC 52-2023): https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/optional-filing-and-payment-of-monthly-vat-returns-now-allowed/
- Grant Thornton PH — Taxpayer classification / reduced penalties (RR 6-2024/8-2024): https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/eopt-is-here-revised-rules-on-taxpayer-classification-and-reduced-penalties-for-micro-and-small-taxpayers/
- PwC PH — RMC 5-2021 Tax Alert No. 8: https://www.pwc.com/ph/en/tax/tax-publications/tax-alerts/2021-tax-alerts/tax-alert-8.html
- PwC PH — RR 3-2024 Tax Alert No. 24: https://www.pwc.com/ph/en/tax/tax-publications/tax-alerts/2024/tax-alert-24.html
- PwC PH — RR 4-2024 Tax Alert No. 22: https://www.pwc.com/ph/en/tax/tax-publications/tax-alerts/2024/tax-alert-22.html
- PwC PH — EoPT invoicing, clarified: https://www.pwc.com/ph/en/tax/tax-publications/taxwise-or-otherwise/2024/compliance-change-on-invoicing-under-eopt.html
- PwC PH — Navigating VAT recovery under EoPT: https://www.pwc.com/ph/en/tax/tax-publications/taxwise-or-otherwise/2024/navigating-vat-recovery-under-the-eopt-act.html
- PwC PH — New taxpayer classifications (RR 8-2024): https://www.pwc.com/ph/en/tax/tax-publications/taxwise-or-otherwise/2024/new-taxpayer-classifications-under-the-eopt-law.html
- PwC PH — Paperless invoicing & sales reporting (EIS): https://www.pwc.com/ph/en/tax/tax-publications/taxwise-or-otherwise/2025/paperles-invoicing-and-sales-reporting.html
- PwC PH — RMC 5-2023 Tax Alert No. 4: https://www.pwc.com/ph/en/tax/tax-publications/tax-alerts/2023/tax-alert-4.html
- KPMG PH — Registration/invoicing update (RR 7-2024): https://kpmg.com/ph/en/home/insights/2024/07/compliance-an-update-to-registration-procedures-and-invoicing-requirements.html
- KPMG PH — Ease of Paying VAT?: https://kpmg.com/ph/en/home/insights/2024/05/ease-of-paying-value-added-tax.html
- Ocampo & Suralvo — Registration & invoicing under EOPT (RR 7-2024): https://www.ocamposuralvo.com/2024/05/01/implementing-the-registration-procedures-and-invoicing-requirements-under-the-ease-of-paying-taxes-act/
- Ocampo & Suralvo — RR 11-2024 transitory: https://www.ocamposuralvo.com/2024/10/11/bir-issues-revenue-regulations-no-11-2024-amending-transitory-provisions-on-deadlines-for-compliance-with-the-invoicing-requirements/
- Ocampo & Suralvo — RMC 5-2023 quarterly VAT: https://www.ocamposuralvo.com/2023/01/16/bir-releases-transitory-provisions-for-the-implementation-of-the-quarterly-filing-of-vat-returns-starting-1-january-2023/
- Reyes Tacandong — RR 3-2024: https://www.reyestacandong.com/bir-issuances-rr-3-2024/
- Reyes Tacandong — RR 4-2024: https://www.reyestacandong.com/bir-issuances-rr-4-2024/
- Forvis Mazars PH — RMC 68-2024 (revised 2550Q): https://www.forvismazars.com/ph/en/insights/tax-alerts/bir-rmc-68-2024
- Forvis Mazars PH — RMC 52-2023: https://www.forvismazars.com/ph/en/insights/tax-alerts/bir-rmc-52-2023
- Forvis Mazars PH — Tax mapping violations guide: https://www.forvismazars.com/ph/en/insights/tax-alerts/forvis-mazars-tax-mapping-violations-guide
- Forvis Mazars PH — RMC 09-2021 CAS/PTU: https://www.mazars.ph/Home/Insights/Tax-Alerts/BIR-RM-09-2021
- Roque Law — Simplified CAS/CBA guidelines (RMO 9-2021): https://roquelaw.com.ph/simplified-guidelines-and-procedures-on-the-use-of-cas-cba-and-its-components/
- Machica Group — RMC 5-2021 w/ Annexes A–C: https://machicagroup.com/wp-content/uploads/2021/08/RMC-No.-5-2021_w_attachments.pdf
- Tax & Accounting Center — RR 7-2024: https://taxacctgcenter.ph/revenue-regulations-no-7-2024/
- Tax & Accounting Center — NIRC Title IX (Secs. 232–235): https://taxacctgcenter.ph/title-ix-chapter-i-keeping-books-of-accounts-records-nirc-philippines/
- Tax & Accounting Center — NIRC Title X (Secs. 250/255/264/264-A/264-B): https://taxacctgcenter.ph/title-x-chapter-ii-crimes-other-offenses-forfeitures-nirc-philippines/
- BDB Law — Top withholding agents redefined (RR 31-2020): https://bdblaw.com.ph/index.php/newsroom/articles/tax-law-for-business/708-top-withholding-agents-redefined
- MPM Consulting — RR 3-2024: https://mpm.ph/rr-3-2024-ease-of-paying-taxes-act/; RMC 65-2024: https://mpm.ph/rmc-65-2024/; RR 11-2024: https://mpm.ph/rr-11-2024/; 0619E: https://mpm.ph/bir-form-0619e/; 1601EQ: https://mpm.ph/bir-form-1601eq/; RR 6-2024: https://mpm.ph/rr-6-2024/
- 8box — RMC 91-2024: https://8box.solutions/rmc-no-91-2024/
- CloudCFO — RMC 4-2026 deadline extension: https://cloudcfo.ph/blog/bir-books-of-accounts-deadline-extension/; RR 6-2024: https://cloudcfo.ph/blog/eopta-revenue-regulations-no-6-2024-reduced-interest-and-penalty-rates-for-micro-and-small-taxpayers/
- IGD & Associates — RMC 4-2026: https://igd-associates.com/2026/01/16/rmc-4-2026-clarification-on-orus-registration-requirements-deadline-extension/
- QNE — Registration of Books via ORUS: https://qne.cloud/ph/registration-of-books-of-accounts-orus-ph/
- Respicio & Co. / lawyer-philippines — required entries for BIR-stamped books: https://www.lawyer-philippines.com/articles/required-entries-for-bir-stamped-books-of-accounts-philippines; SLSP rules: https://www.lawyer-philippines.com/articles/latest-bir-rules-on-submission-of-the-summary-list-of-sales-and-purchases
- Taxumo — ATC list: https://www.taxumo.com/blog/list-of-bir-atc-for-income-tax-filing-and-withholding-tax/; EWT forms: https://www.taxumo.com/blog/making-sense-expanded-withholding-tax-forms-2307-0619e-1601eq-qap/
- JuanTax — RMC 7-2021 Alphalist DEV Module v7.0: https://juan.tax/blog/rmc-no-7-2021/
- juan.ac — Formats of books of accounts: https://www.juan.ac/blog/formats-books-account-explained
- FilipiKnow — Books of Accounts: https://filipiknow.net/books-of-accounts-bir/
- jur.ph — RMO 7-2015 summary: https://jur.ph/law/summary/revised-schedule-compromise-penalties-nirc-violations
- ClearTax PH — e-invoicing penalties: https://www.cleartax.com/ph/bir-e-invoicing-penalties-philippines
- ANSI — BIR CAS compliance overview: https://ansi.ph/everything-you-need-to-know-about-being-bir-cas-compliant/
- RR 16-2006 reprint: http://philtax.blogspot.com/2006/10/revenue-regulations-no-16-2006.html
- RR 5-2014 full text (BDP Law): https://www.bdplaw.com.ph/sites/default/files/RR%205-2014.pdf

---

*Compiled from 8 independently fact-checked research reports (topics: CAS registration, system/technical requirements, books of accounts, invoicing post-EOPT, VAT computation, withholding tax, returns & reports, penalties & audit findings). All CONFIRMED claims retained; all CORRECTIONS applied with corrected facts/citations; UNVERIFIED/likely-fabricated claims dropped or marked ⚠️.*
