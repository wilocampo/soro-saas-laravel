# End-to-end test plan

A module-by-module manual walkthrough of everything built so far, with sample entries and the exact journal lines each must produce.

**Every expected figure here was produced by running the real posting engine against a freshly provisioned tenant and reading back the journal.** They are observed, not hand-calculated. Where something is not built or not enforced it is marked, rather than left to be discovered.

Shareable rendering: <https://claude.ai/code/artifact/7c6a4356-bd8f-4756-97c5-8baf8ad4387c>

---

## 00 — Set up the demo tenant

```bash
php artisan migrate --force        # landlord: adds the billing tables
php artisan tenants:demo --fresh   # drops & rebuilds the demo tenant
php artisan serve                  # http://soro.local:8000
```

`soro.local` already resolves to 127.0.0.1 in `/etc/hosts`, and the subdomain `soro` is what routes into the tenant database. No nginx or hosts changes needed.

**URL:** <http://soro.local:8000> — **Password:** `password` (all four logins)

| Login | Role | Permissions granted (spec 04) |
|---|---|---|
| `owner@soro.local` | owner | Everything, incl. billing, period close, users |
| `accountant@soro.local` | accountant | Post, void, reverse, close periods, reports |
| `bookkeeper@soro.local` | bookkeeper | `documents.create` + `journal.draft` — not post |
| `auditor@soro.local` | auditor | View + `reports.export` only |

> **Known gap — RBAC is seeded but NOT enforced.** The permissions above are correct in the database, but no route or controller consults them: writes are gated on the *subscription* (`can-post`), not the role. Logged in as `auditor` you can still reach a write endpoint. Deliberate for now — spec 06 still lists "bookkeeper posting rights: post-but-not-close vs draft-only" as an open decision, and wiring enforcement would bake in an unresolved policy.

---

## 01 — Onboarding & company profile

*Proves the BIR registration details that print on every document are captured, and that go-live is gated on them.*

Sign in as owner → sidebar **Get started → Onboarding & BIR profile**. Enter registered name, address, TIN + branch, the ACCN and its issue date, then the NPC/DPA attestation and a named DPO.

> Pull the latest and run `npm run build` before testing. Until commit `ac0cabb` the sidebar was the original SaaS-starter menu — it had no link to onboarding or any accounting module, and its "Tenants" entry 500'd from inside a tenant.

Sample: `Demo Trading Corp.` · TIN `123-456-789` / branch `000` · classification Small · ACCN `ACCN-2026-000123` · DPO `dpo@demo.ph`

**Expected**

- The go-live checklist turns green only once **company profile**, **ACCN** and **NPC registration** are present — those three block, the rest advise.
- Saving writes an **audit row with before/after values**.
- A TIN that is not nine digits is rejected (branch code is its own field).
- The ACCN must then appear **on the face** of the printed invoice (module 03).

---

## 02 — Partners

*Everything downstream needs these.*

| | |
|---|---|
| Customer | `C-001` · Acme Trading Corp. · TIN 123456789 · VAT-registered |
| Vendor | `V-001` · Supplier Inc. · TIN 987654321 · VAT-registered |

**Expected** — removing a partner **deactivates** it; posted documents reference it and the books are append-only.

> **Statement of Account** needs a customer — with none on file it renders an empty state pointing back here. (It used to 404: acceptable when the only route there was a typed URL, wrong once it became a menu item.)

---

## 03 — Sales invoice (services, VAT)

*The core accrual posting: revenue and output VAT at invoice date, A/R as the sum of the parts.*

Sample: Acme Trading Corp., today, one line "Consulting services" — net ₱10,000.00, VAT ₱1,200.00, total ₱11,200.00.

**Expected — `INV-000001`, book `SJ-2026-000001`**

| Code | Account | Debit | Credit |
|---|---|--:|--:|
| 1100 | Accounts Receivable | 11,200.00 | |
| 4000 | Sales Revenue | | 10,000.00 |
| 2100 | Output VAT | | 1,200.00 |
| | **Total** | **11,200.00** | **11,200.00** |

**Also check**

- First invoice is `INV-000001`. Numbers are drawn inside the posting transaction — a failed save consumes none.
- **PDF**: the eleven mandatory VAT-invoice fields, VAT as a separate line, and the **ACCN on the face**.
- **Cancel, don't delete**: cancelling voids in place and **keeps the serial**; it stays visible at zero.

---

## 04 — Collection with creditable withholding (2307)

Sample: received ₱11,000.00 cash, EWT ₱200.00 (2% of the ₱10,000 net — the base is VAT-exclusive **by rule**), applied to `INV-000001` for ₱11,200.00.

**Expected — `CRJ-2026-000001`**

| Code | Account | Debit | Credit |
|---|---|--:|--:|
| 1000 | Cash in Bank | 11,000.00 | |
| 1150 | Creditable Withholding Tax (2307 asset) | 200.00 | |
| 1100 | Accounts Receivable | | 11,200.00 |
| | **Total** | **11,200.00** | **11,200.00** |

A/R for this customer nets to **0.00**.

---

## 05 — Vendor bill (input VAT + EWT)

*You are the withholding agent, so the liability accrues at booking date (RR 4-2024), not at payment.*

Sample: Supplier Inc., their invoice `SI-77123`, one line "Office rent" net ₱5,000.00 with tax code `IV12` → input VAT ₱600.00; EWT ₱100.00; payable ₱5,500.00.

**Expected — `PJ-2026-000001`**

| Code | Account | Debit | Credit |
|---|---|--:|--:|
| 5000 | Operating Expense | 5,000.00 | |
| 1200 | Input VAT | 600.00 | |
| 2000 | Accounts Payable | | 5,500.00 |
| 2150 | Withholding Tax Payable | | 100.00 |
| | **Total** | **5,600.00** | **5,600.00** |

> If Input VAT is missing, the tax code was not set on the **line**. VAT is computed per line from the code's basis-point rate; the header total is derived, never typed.

---

## 06 — Inventory: item + goods receipt

*Stock capitalises to Inventory against GRNI (not A/P — no bill has arrived), and the UoM cascade converts cases to pieces.*

Sample: item `ITM-001` "Canned Sardines 155g", type **inventory**, stock UoM `pc`, purchase UoM `case`, factor **48**. Receipt from Supplier Inc., ref `DR-4471`, **2 cases @ ₱12.50/pc** → 96 pcs, ₱1,200.00.

**Expected — `GR-000001`, `PJ-2026-000002`**

| Code | Account | Debit | Credit |
|---|---|--:|--:|
| 1400 | Inventory | 1,200.00 | |
| 2050 | Goods Received Not Invoiced | | 1,200.00 |
| | **Total** | **1,200.00** | **1,200.00** |

On hand **96.00000 pcs**, moving average **12.500000**.

---

## 07 — Stocked sale (perpetual COGS)

Sample: Acme, one line — Canned Sardines 155g, **20 pcs @ ₱30.00** → net ₱600.00 + VAT ₱72.00 = ₱672.00.

**Expected — `INV-000002`, `SJ-2026-000002`**

| Code | Account | Debit | Credit |
|---|---|--:|--:|
| 1100 | Accounts Receivable | 672.00 | |
| 4000 | Sales Revenue | | 600.00 |
| 2100 | Output VAT | | 72.00 |
| 5200 | Cost of Goods Sold | 250.00 | |
| 1400 | Inventory | | 250.00 |
| | **Total** | **922.00** | **922.00** |

COGS = 20 × ₱12.50 = **₱250.00**. Stock falls to **76.00000 pcs**, valued **₱950.00**.

> Try to oversell: invoice more than 76 pcs and the posting is refused — negative stock is blocked by default and nothing is written.

---

## 08 — Reports

*Every statement is a partition of one trial balance, so they cannot disagree with each other or the ledger.*

**Trial balance after modules 03–07**

| Code | Account | Debit | Credit |
|---|---|--:|--:|
| 1000 | Cash in Bank | 11,000.00 | |
| 1100 | Accounts Receivable | 11,872.00 | 11,200.00 |
| 1150 | Creditable Withholding Tax | 200.00 | |
| 1200 | Input VAT | 600.00 | |
| 1400 | Inventory | 1,200.00 | 250.00 |
| 2000 | Accounts Payable | | 5,500.00 |
| 2050 | Goods Received Not Invoiced | | 1,200.00 |
| 2100 | Output VAT | | 1,272.00 |
| 2150 | Withholding Tax Payable | | 100.00 |
| 4000 | Sales Revenue | | 10,600.00 |
| 5000 | Operating Expense | 5,000.00 | |
| 5200 | Cost of Goods Sold | 250.00 | |
| | **Total — must tie** | **30,122.00** | **30,122.00** |

**The accounting equation, from the same numbers**

| Statement line | Amount | Derivation |
|---|--:|---|
| Total assets | 13,422.00 | `11,000 + 672 + 200 + 600 + 950` |
| Total liabilities | 8,072.00 | `5,500 + 1,200 + 1,272 + 100` |
| Net income → equity | 5,350.00 | `10,600 − (5,000 + 250)` |
| **Liabilities + equity** | **13,422.00** | equals total assets |

**Walk the rest**

- **General Ledger** — account 1100 closes at ₱672.00.
- **General Journal** — all five entries, in book order, each balanced.
- **Aging** — `INV-000002` (₱672) receivable; the vendor bill (₱5,500) payable.
- **Statement of Account** — Acme; charges, credits, brought-forward balance. The page states an SOA is **supplementary** and not valid to support input tax.
- **Export** — PDF / XLSX / CSV from one description, each carrying the mandatory BIR header block (registered name, TIN + branch, software name and version, generating user, timestamp).

---

## 09 — Immutability & compliance guarantees

Each of these must **fail**:

1. **Edit a posted entry** — no route exists; a DB trigger rejects `UPDATE`/`DELETE` on posted journal lines.
2. **Delete an invoice** — only cancel, which voids in place and keeps the serial.
3. **Post into a closed period** — close period 1, then date an invoice into it.
4. **Create a serial gap** — force a failed save; the next document takes the next number, none skipped.
5. **Tamper in SQL** — change an amount in `journal_lines`, then run `ledger:verify`; the hash chain breaks and the command fails.

> Switch the tenant to cash basis in Settings and try to receive stock: refused. A business carrying inventory cannot keep cash-basis books (D38).

---

## 10 — Nightly integrity commands

```bash
php artisan tenants:artisan "ledger:verify"
php artisan tenants:artisan "inventory:verify"
```

**Expected output**

```
ledger:verify clean — balances, hashes, and audit chain all check out.

  inventory: subledger 950.00 vs GL 950.00 (drift 0.00)
inventory:verify clean — stock cache, on-hand and the GL tie-out all check out.
```

76 pcs × ₱12.50 = ₱950.00 in the subledger and the same ₱950.00 in GL 1400 — to the centavo, or the command fails.

Also worth running:

```bash
php artisan tenants:artisan "ledger:trial-balance"    # asserts it ties
php artisan tenants:artisan "ledger:rebuild-balances" # proves the cache is a cache
php artisan app:production-check                      # the go-live gate
php artisan tenants:export                            # the offboarding bundle
```

`app:production-check` is **expected to fail locally** — `APP_DEBUG` on, URL not https, env not production. That failing output is the check working.

---

## 11 — Billing & the read-only lapse gate

The demo tenant is provisioned on a **30-day trial**, which is what lets you post at all. To see the gate:

```bash
php artisan tinker --execute="\App\Models\Tenant::where('subdomain','soro')->first()
  ->forceFill(['trial_ends_at' => now()->subDays(30)])->save();"
```

**Expected** — reads keep working (BIR holds the registrant responsible for producing its books, so we never hide them); writes return **402**. Restore with `php artisan tenants:demo --fresh`.

---

## What you will *not* find, and why

| Area | Status | Why |
|---|---|---|
| **BIR returns & books** (2550Q, 2551Q, 2307, 0619-E, 1601-EQ, SLSP, alphalist) | NOT BUILT | Phase 4. Blocked on a licensed CPA supplying the eBIRForms ATC library and the reviewed chart. These files get filed — building against a guess means building twice. |
| **Role enforcement** | GAP | Permissions seeded per spec 04 but no route checks them; the policy is still an open decision in spec 06. |
| **Withholding rate lookup** | PARTIAL | Fourteen confirmed ATC rows seeded; any code outside them **refuses to resolve** rather than inventing a rate. |
| **Statement of Cash Flows** | DEFERRED | Needs an operating/investing/financing tag per account — a CPA judgement (D23/D40). |
| **Stock transfers & manual adjustments** | NO UI | Services and postings exist and are tested; adjustments reachable via count approval. |
| **Payroll, income-tax returns, multi-currency** | OUT OF SCOPE | Spec 06, D8. |

---

## Starting over

```bash
php artisan tenants:demo --fresh
```

Safe to run as often as you like. It refuses to run in production, because it seeds accounts with a password published in this document.
