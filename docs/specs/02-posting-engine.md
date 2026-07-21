# 02 — Posting Engine & Posting-Rules Layer

Scope: how business documents become balanced journal entries. Lives in `App\Domain\Ledger\`. Depends on the schema in [`01-ledger-data-model.md`](01-ledger-data-model.md); tax specifics in [`03-bir-accreditation.md`](03-bir-accreditation.md). Money is integer centavos; tenancy is DB-per-tenant (no `tenant_id` plumbing).

## 0. Invariants
- The **journal is the only balance-bearing store.** Documents (invoice, bill, payment, expense) are subledger rows that *reference* the entry they produced; they never carry a running balance.
- **Single choke point:** nothing writes `journal_entries`/`journal_lines` except `PostingService`. Enforced structurally (models don't expose `create`/`update` to app code) and by the DB triggers in `01`.
- Posted entries are immutable; corrections are reversing entries; documents are voided, never edited/deleted.

## 1. `PostingService` — the choke point

### Value objects (immutable)
```php
namespace App\Domain\Ledger\Posting;

final class JournalDraft {
    /** @param JournalLineDraft[] $lines */
    public function __construct(
        public readonly string          $journalBook,      // 'general'|'sales'|'purchase'|'cash_receipts'|'cash_disbursements'|...
        public readonly CarbonImmutable $entryDate,
        public readonly string          $memo,
        public readonly SourceRef       $source,           // (type, id) of originating document
        public readonly string          $idempotencyKey,   // deterministic, caller-supplied
        public readonly array           $lines,
        public readonly ?ReversalOf     $reverses = null,
    ) {}
    public function isEmpty(): bool { return $this->lines === []; }
    public function totalDebit(): int  { return array_sum(array_map(fn($l)=>$l->debitCentavos,  $this->lines)); }
    public function totalCredit(): int { return array_sum(array_map(fn($l)=>$l->creditCentavos, $this->lines)); }
    public function isBalanced(): bool { return $this->totalDebit() === $this->totalCredit(); }
}

final class JournalLineDraft {
    public function __construct(
        public readonly int     $accountId,
        public readonly int     $debitCentavos  = 0,   // >= 0 ; exactly one of debit/credit > 0
        public readonly int     $creditCentavos = 0,
        public readonly ?string $memo = null,
        public readonly ?int    $taxCodeId = null,
        public readonly ?int    $taxBaseCentavos = null,
        public readonly ?string $atcCode = null,       // BIR ATC, e.g. WC160
        public readonly ?PartyRef $party = null,       // customer/vendor
        public readonly ?string $sourceLineRef = null,
    ) {}
}
```
Tax/party fields are the "carry the BIR data now, wire the returns later" hook (see `03`). They are set at post time and never recomputed downstream.

### Contract
```php
interface PostingService {
    /** Idempotent. Returns null for an intentionally empty draft (e.g. a cash-basis invoice). */
    public function post(JournalDraft $draft): ?JournalEntry;
    public function reverse(JournalEntry $original, string $reason, ?CarbonImmutable $date = null): JournalEntry;
    public function void(JournalEntry $original, string $reason): JournalEntry;
    /** Pure — runs rules + rounding, returns the balanced draft, writes nothing. For UI preview. */
    public function preview(JournalDraft $draft): JournalDraft;
}
```

### `post()` algorithm
```
post(draft):
  if draft.isEmpty(): return null                       # cash-basis no-op documents

  # A. pure structural validation (no DB)
  assert draft.lines.count() >= 2
  assert every line: debit>=0 && credit>=0 && (debit>0) XOR (credit>0)
  assert >=1 debit line AND >=1 credit line
  assert draft.isBalanced()                             # rounding already resolved by the rule (§3)

  return DB::transaction(function() use (draft):
    # B. idempotency CLAIM first (before any sequence work)
    try: header = JournalEntry::create({status:'draft', idempotency_key:draft.key, source, entry_date, period, created_by})
    catch UniqueConstraint(idempotency_key): return JournalEntry::firstWhere(idempotency_key)   # duplicate → return winner, NO double-post

    # C. account + period validity
    accounts = Account::whereIn(ids)->lockForShare()->get(); assert each active & postable & PHP
    period = Period::coveringDate(draft.entryDate)->sharedLock(); assert period.status=='open' && draft.entryDate > settings.posting_lock_date

    # D. materialize lines FIRST (so the sequence lock below is held minimally — order matches 01 §2.1)
    insert draft.lines -> journal_lines (line_no 1..n)

    # E. gapless number LAST (lock held only across the flip) — see 01 §3
    seq = DocumentSequence::where(book_series, fiscal_year, branch)->lockForUpdate(); header.entry_number = format(seq.last_value+1); seq.increment('last_value')
    header.update(status:'posted', posted_at, posted_by, totals, posting_hash)   # fires trigger T4 (balance check)
    upsert account_period_balances (01 §4); insert audit_log('entry.posted', before/after)
    return header
  )
```
**Idempotency key** is caller-supplied, deterministic, human-readable: `"{source_type}:{source_id}:{action}:{revision}"`, e.g. `sales_invoice:123:post:1`. The document service owns key construction so "the same post" is domain-defined (a retried job, a double-clicked button, a re-fired queue message all yield the same key). Claiming the key *before* drawing a number means a duplicate fails before consuming a sequence value → no gap. `revision` lets a legitimate re-post after a correction cycle get a fresh key.

## 2. Posting-rules layer

A `PostingRule` per document type turns a document into a **balanced draft**. Basis selects the recognition path *inside* the rule.
```php
interface PostingRule {
    public function documentType(): string;                    // 'sales_invoice', ...
    public function build(Postable $doc, PostingContext $ctx): JournalDraft;
}
final class PostingContext {
    public AccountingBasis $basis;      // from ledger_settings
    public AccountResolver $accounts;   // role -> account_id (CoA map)
    public TaxResolver     $tax;        // tax_code -> rate/account/atc
    public RoundingPolicy  $rounding;   // §3
}
```
Uniform document workflow:
```php
$draft = $rules->for($doc->type())->build($doc, $ctx);
$entry = $posting->post($draft);        // null for cash-basis no-op documents
$doc->linkJournalEntry($entry?->id);    // subledger <-> journal link (nullable)
```

### Architecture decision (D2): post in the tenant's REGISTERED basis
Two candidates: **(A) basis-selective posting** — the rule emits accrual *or* cash lines per `ledger_settings.accounting_basis`; the GL is physically in the registered basis. **(B) always-accrual + derive cash at report time.** **Choose (A).** Because BIR examines the *books themselves* (a cash-basis registrant's GL/journals must *be* cash-basis and tie to the return with no transformation); basis is fixed per tenant like a registration, so (B)'s only advantage — a live toggle — is unused; and (B) concentrates subtle re-recognition bugs (partial payments, prepayments, credit memos, bad debts) on every report run, whereas (A) confines that logic to the payment rule, run once, then immutable. A cash-basis tenant still gets **A/R & A/P aging from the subledger** (a cash-basis GL legitimately has no A/R/A/P control accounts). **Switching basis is a deliberate restatement event, not a checkbox.**

## 3. Rounding
- All arithmetic in integer centavos; rate as basis points (`rate_bp`; 12% = 1200). VAT on net: `vat = intdiv(base*rate_bp + 5000, 10000)` (half-up). Tax-inclusive: `net = round(gross*10000/(10000+rate_bp))`, then **`vat = gross − net`** so the pieces reconstitute the gross exactly.
- **Balance by construction:** the rule computes the *component* lines (net, VAT) with rounding, then defines the **control/total line as the arithmetic sum** (`A/R = net + VAT`; `Cash = Σ lines`). The draft is balanced before it reaches the engine.
- **Pro-rata splits use largest-remainder (Hamilton):** floor each share, then hand leftover centavos to the largest fractional remainders. Parts sum to the whole exactly.
- A `rounding_gain_loss` account exists only for *legitimate* business rounding (e.g. peso cash tender). `PostingService` still **re-asserts `isBalanced()` and throws** — the rounding account is never a silent plug for an engine defect.
- ⚠️ The exact BIR rounding convention (half-up vs bankers', per-line vs per-invoice) is unconfirmed — see `03` open items. Keep it a `RoundingPolicy` so it's swappable.

## 4. Worked examples (figures in centavos; ₱10,000 net = `1,000,000`)

> Post-EOPT the primary document is the **Invoice** (goods *and* services); "Official Receipt" is supplementary (see `03`). Examples label the journal book.

### 4.1 Sales invoice, VATable (net ₱10,000, VAT 12% ₱1,200, gross ₱11,200)
**Accrual** (`sales`):
| Account | Debit | Credit | tax fields |
|---|--:|--:|---|
| Accounts Receivable | 11,200.00 | | party=customer |
| Sales Revenue | | 10,000.00 | tax_code=OV12, tax_base=10,000.00 |
| Output VAT | | 1,200.00 | tax_code=OV12 |

**Cash basis:** `build()` returns an **empty draft** → `post()` returns `null`. No GL entry; the invoice row + `tax_base` snapshot feed A/R aging from the subledger. *(Sign-off `03`: output VAT on services may be collection-based for some taxpayers → credit **Deferred Output VAT**, reclassed at receipt.)*

### 4.2 Customer payment / collection with 2% creditable EWT (cash received ₱11,000)
Customer is a Top Withholding Agent buying services → **2% EWT on the ₱10,000 net = ₱200, ATC WC160** (rate/ATC pairing corrected per the `03` ATC table — a TWA-goods purchase would be 1% WC158 instead).
**Accrual** (`cash_receipts`):
| Account | Debit | Credit | tax fields |
|---|--:|--:|---|
| Cash in Bank | 11,000.00 | | |
| Creditable Withholding Tax (2307 asset) | 200.00 | | atc=WC160, tax_base=10,000.00 |
| Accounts Receivable | | 11,200.00 | party=customer, source_line_ref=invoice |

**Cash basis** — recognition happens here; the rule expands each applied invoice's stored tax snapshot:
| Account | Debit | Credit | tax fields |
|---|--:|--:|---|
| Cash in Bank | 11,000.00 | | |
| Creditable Withholding Tax | 200.00 | | atc=WC160, tax_base=10,000.00 |
| Sales Revenue | | 10,000.00 | tax_code=OV12, tax_base=10,000.00 |
| Output VAT | | 1,200.00 | tax_code=OV12 |
Mechanism: the payment carries **allocations** (`invoice_id, applied_centavos`); the cash-basis rule walks them and re-derives net/VAT from each invoice's snapshot (pro-rated for partials via largest-remainder, §3). That snapshot is why the invoice recorded `tax_base` even though it posted nothing.

### 4.3 Vendor bill/expense with 2% EWT (net ₱5,000, Input VAT ₱600, EWT ₱100, A/P ₱5,500)
**Accrual** (`purchase`) — note EWT accrues at booking (RR 4-2024):
| Account | Debit | Credit | tax fields |
|---|--:|--:|---|
| Operating Expense | 5,000.00 | | tax_base=5,000.00 |
| Input VAT | 600.00 | | tax_code=IV12 |
| Accounts Payable | | 5,500.00 | party=vendor |
| Withholding Tax Payable (1601EQ) | | 100.00 | atc=WC160, tax_base=5,000.00 |

**Cash basis** — no entry at bill; at payment (`cash_disbursements`): Dr Expense 5,000 / Dr Input VAT 600 / Cr Cash 5,500 / Cr Withholding Tax Payable 100.

> Distinction the CoA map must encode (sign-off `03`/`06`): **Withholding Tax Payable** = tax *we* withhold from vendors and remit (0619E/1601EQ) — a liability; **Creditable Withholding Tax asset** = tax *customers* withhold from us, claimed via 2307 — an asset. Different accounts, different directions.

### 4.4 Manual adjusting entry (`general`)
`ManualJournalPostingRule` is a validating pass-through (user supplies balanced lines). E.g. monthly depreciation: Dr Depreciation Expense 2,000 / Cr Accumulated Depreciation 2,000. A cash-basis tenant's account picker filters to basis-permissible roles (depreciation is allowed; pure-accrual accruals are not).

## 5. Reversal / void / correction
- **Reverse** (`reverse(original, reason, date?)`): build a mirror draft (same accounts/amounts/tax refs, **debit↔credit swapped**), `journal_book='reversal'`, `reverses=original`, `idempotencyKey="journal_entry:{id}:reverse:1"`, `entryDate=date ?? policy`. Post it, then set `original.reversed_by_entry_id=mirror.id` + `reversal_reason` — **in one transaction**. The original **stays `posted`**; "reversed" is represented by `reversed_by_entry_id` being set (T4 in `01` §2.2 explicitly permits exactly this update). Net effect zero. Never mutates the original's lines.
- **Dating policy (sign-off `06`):** default = original date **if its period is still open**, else the first day of the current open period (cannot post into a closed period).
- **Void** (`void(original, reason)`): a same-period reversal — forces `entryDate=original.entry_date`, requires the original's period open and not yet in a filed return, tags both `void`. If closed/reported → refuse; caller must `reverse()` into the current period.
- **Correction = reverse + re-post** (fresh entry, `revision` bumped). All three link via `reverses_entry_id`/`reversed_by_entry_id`. A reversal may not itself be reversed (guard).
- Document layer: cancelling an invoice calls `reverse()`/`void()` and flips the document to `cancelled`; the subledger allocation is unwound so A/R aging self-corrects.

## 6. Concurrency
Fixed lock order everywhere: **accounts (FOR SHARE) → period (FOR SHARE) → sequence (FOR UPDATE)** → no deadlocks.
1. **Sequence:** `document_sequences` row `FOR UPDATE` inside the transaction; drawn last, held briefly; rollback un-consumes it → gapless. Per-book series spread contention.
2. **Idempotency race:** two identical posts both attempt the header insert with the same `idempotency_key`; `UNIQUE` lets one win, the loser rolls back (never reached the sequence → no number consumed) and returns the winner.
3. **Period-lock race:** posting takes the period `FOR SHARE` and asserts `open`; close takes it `FOR UPDATE`. Either the post commits first (close waits, then closes over a consistent set) or close commits first (the post's shared lock waits, re-reads `closed`, rejects `PeriodClosed`). No entry slips into a closed period.

## 7. Accountant sign-off (also in `06-decisions.md`)
VAT recognition timing (Output/Deferred-Output on services; Input-VAT timing on cash-basis); cash-basis book structure (no A/R/A/P control accounts); CoA role mapping + ATC set; rounding convention; reversal/void dating & void-eligibility window; manual-JE / prior-period-adjustment policy for cash-basis tenants; book-series ↔ BIR books mapping.
