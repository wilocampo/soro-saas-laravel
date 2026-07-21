# 07 — Glossary (double-entry + PH/BIR)

Shared vocabulary so every session/human uses terms the same way.

## Double-entry / ledger
- **Journal entry** — one balanced transaction: a header (`journal_entries`) + ≥2 lines (`journal_lines`).
- **Debit / Credit** — the two sides of every entry. Each line is exactly one (never both, never neither). An entry balances when Σdebits = Σcredits.
- **Normal balance** — the side that increases an account: Asset/Expense = debit; Liability/Equity/Income = credit. A **contra** account has the opposite normal balance (e.g. Accumulated Depreciation, Sales Returns).
- **Chart of Accounts (CoA)** — the tenant's list of accounts. Only **postable** (leaf) accounts accept lines; parent accounts roll up.
- **Posting** — validating a draft entry and committing it as immutable via `PostingService`. **Draft** entries are mutable; **posted** are not; **void** are cancelled-but-retained.
- **Reversing entry** — a new entry that mirrors an original (debit↔credit swapped) to undo it. Corrections are always reversals — posted entries are never edited.
- **Void** — a same-period reversal that tags both entries `void` (used when the period is still open and not yet in a filed return).
- **Trial balance** — the list of all account balances; must net to zero. **General Ledger (GL)** — all lines per account. **General Journal (GJ)** — all entries chronologically.
- **Opening Balance Equity (OBE)** — a system account that absorbs the plug while a tenant enters starting balances account-by-account, later cleared to Retained Earnings.
- **Retained Earnings** — accumulated net results; year-end close moves income/expense (via **Income Summary**) into it and zeroes the nominal accounts for the new year.
- **Accrual basis** — recognize revenue/expense when earned/incurred (at invoice/bill). **Cash basis** — recognize when cash is received/paid. Set per tenant (`ledger_settings.accounting_basis`).
- **Fiscal year / period** — the reporting calendar; a period can be `open`, `closed`, or `locked`. **Posting lock date** — no posting on/before it.
- **Idempotency key** — a deterministic string identifying "the same post", so retries/double-clicks don't double-post.

## Money
- **Centavo / minor unit** — 1/100 of a peso; all ledger money is `BIGINT` centavos.
- **Largest-remainder (Hamilton)** — the rounding method for splitting an amount across lines so the parts sum to the whole exactly.

## Philippine / BIR
- **BIR** — Bureau of Internal Revenue. **NIRC** — National Internal Revenue Code (the tax code). **TIN** — Taxpayer Identification Number (+ branch code).
- **EOPT** — Ease of Paying Taxes Act (RA 11976, 2024); reshaped invoicing, VAT filing, withholding timing.
- **CAS** — Computerized Accounting System. **CBA** — Computerized Books of Accounts. Registered with BIR; yields an **Acknowledgement Certificate (AC)** with a control number (**ACCN**) — replaced the old **Permit-to-Use (PTU)**.
- **Invoice** — post-EOPT, the single **principal** VAT document for goods *and* services. **Official Receipt (OR)** — now a **supplementary** document.
- **VAT** — Value-Added Tax, 12%. **Output VAT** — on sales. **Input VAT** — on purchases (creditable). **Zero-rated** vs **VAT-exempt** — different treatments (see `03`). VAT threshold ₱3,000,000 gross annual.
- **EWT / CWT** — Expanded/Creditable Withholding Tax. **ATC** — Alphanumeric Tax Code identifying the withholding type/rate. **Form 2307** — Certificate of Creditable Tax Withheld (issued to the payee). **TWA** — Top Withholding Agent (BIR-designated).
- **Withholding Tax Payable** — tax *we* withhold from vendors and remit (liability). **Creditable Withholding Tax (asset)** — tax *customers* withhold from us, claimed via 2307.
- **Books of Accounts** — General Journal, General Ledger, Sales Journal, Purchase Journal, Cash Receipts Book, Cash Disbursements Book (columnar formats in `03`).
- **Returns:** **2550Q** (quarterly VAT; no monthly 2550M post-EOPT), **0619E** (monthly EWT remittance), **1601EQ** (quarterly EWT + QAP), **1604E** (annual). **SLSP** — Summary Lists of Sales & Purchases. **Alphalist** — list of payees/withholding. **`.DAT`** — the BIR file format for SLSP/alphalist. **eBIRForms / eFPS** — BIR filing channels.
- **EIS** — Electronic Invoicing System (BIR e-invoice transmission, mandated for some taxpayers).

See [`03-bir-accreditation.md`](03-bir-accreditation.md) for the authoritative, cited detail.
