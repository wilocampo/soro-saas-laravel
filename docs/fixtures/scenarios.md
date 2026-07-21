# Fixture scenarios (draft — CPA to verify)

VAT = 12%. Amounts in pesos. Each entry must balance (Σ debit = Σ credit).

## S1 — Opening balances (accrual), go-live 2026-01-01
Inputs (opening trial balance): Cash 200,000; Accounts Receivable 50,000; Equipment 300,000; Accounts Payable 80,000; Owner's Equity 470,000.
Expected opening JE (`journal_book=opening_balance`, dated 2025-12-31):
| Account | Debit | Credit |
|---|--:|--:|
| Cash in Bank | 200,000 | |
| Accounts Receivable | 50,000 | |
| Equipment | 300,000 | |
| Accounts Payable | | 80,000 |
| Owner's Equity (or Opening Balance Equity → reconciled) | | 470,000 |
Trial balance after: debits 550,000 = credits 550,000. ✔

## S2 — VATable sales invoice, **accrual** (net 10,000 + VAT 1,200)
| Account | Debit | Credit |
|---|--:|--:|
| Accounts Receivable | 11,200 | |
| Sales Revenue | | 10,000 |
| Output VAT | | 1,200 |
TB effect: +11,200 debit / +11,200 credit. ✔

## S3 — Same invoice, **cash basis**
`build()` returns an empty draft → `post()` returns `null` → **no journal entry**. The invoice is recorded in the subledger (with a VAT snapshot for later recognition) and appears in A/R aging only. Recognition occurs at collection (S4 cash-basis rows).

## S4 — Customer collection with 2% creditable EWT (TWA customer, services — ATC WC160)
Customer pays the S2 invoice, withholding 2% of net (200). Cash received = 11,000.
**Accrual** (`cash_receipts`):
| Account | Debit | Credit |
|---|--:|--:|
| Cash in Bank | 11,000 | |
| Creditable Withholding Tax (2307 asset) | 200 | |
| Accounts Receivable | | 11,200 |
**Cash basis** (recognizes revenue + VAT now):
| Account | Debit | Credit |
|---|--:|--:|
| Cash in Bank | 11,000 | |
| Creditable Withholding Tax | 200 | |
| Sales Revenue | | 10,000 |
| Output VAT | | 1,200 |

## S5 — Vendor bill/expense with 2% EWT (accrual; EWT accrues at booking, RR 4-2024)
Net 5,000; Input VAT 600; EWT 2% of net = 100; A/P = 5,500.
| Account | Debit | Credit |
|---|--:|--:|
| Operating Expense | 5,000 | |
| Input VAT | 600 | |
| Accounts Payable | | 5,500 |
| Withholding Tax Payable | | 100 |

## S6 — Manual adjusting entry (depreciation)
| Account | Debit | Credit |
|---|--:|--:|
| Depreciation Expense | 2,000 | |
| Accumulated Depreciation | | 2,000 |

## S7 — Reversal of S6
`reverse(S6, "correction")` → mirror entry: Dr Accumulated Depreciation 2,000 / Cr Depreciation Expense 2,000. Net of S6+S7 on every account = 0. ✔

## S8 — Year-end close (nominal → Retained Earnings)
Given period totals Sales Revenue 10,000 (cr) and total Expenses 7,000 (dr), net income 3,000. Closing JE (`year_end_close`, dated FY end):
| Account | Debit | Credit |
|---|--:|--:|
| Sales Revenue | 10,000 | |
| Income Summary | | 10,000 |
| Income Summary | 7,000 | |
| Expenses (each) | | 7,000 |
| Income Summary | 3,000 | |
| Retained Earnings | | 3,000 |
After: income/expense accounts = 0 going into the new year; Retained Earnings +3,000. *(Income-Summary-vs-direct-to-RE is a CPA decision — see `06`.)*

## S9 — Month close
Post S2 + S5 in January; close January (period → `closed`, `posting_lock_date` advances to Jan 31, balances roll forward). Assert: (a) a new entry dated Jan 15 is **rejected** (`PeriodClosed`); (b) February opening balances = January closing balances per account; (c) trial balance as of Jan 31 reads from the cache and equals a fresh recompute.

## S10 — Inventory receive → sell → count (perpetual, weighted average; see `../specs/08-inventory-module.md`)
Receive 100 units @ ₱50 (Dr Inventory 5,000 / Cr GRNI or A/P 5,000; avg cost = 50). Receive 100 more @ ₱60 → avg cost = 55. Sell 50 @ ₱100 (revenue entry per S2 pattern **+ Dr COGS 2,750 / Cr Inventory 2,750**). Count finds 148 on hand (system: 150) → variance −2 @ 55 = −110 → adjustment: Dr Shrinkage 110 / Cr Inventory 110. Assert: inventory GL balance = 150−2 = 148 × 55 = 8,140 = Σ(movements × cost) — subledger ties to GL to the centavo.

---
**Rounding check (S-round):** an invoice where VAT produces a fractional centavo must still balance — the control line (A/R) is defined as `net + VAT` after each component is rounded half-up, so Σdebit = Σcredit exactly (see `../specs/02-posting-engine.md` §3).
