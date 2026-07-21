# 01 — Ledger Core: Data Model & Integrity Enforcement (MariaDB 11.8, tenant DB)

Scope: the double-entry ledger core. All tables here are **tenant migrations** (`database/migrations/tenant/`, run on the `tenant` connection) → each tenant DB gets its own copy, so there are **no `tenant_id` columns**. Engine `InnoDB`, `utf8mb4_unicode_ci`. Requires **MariaDB ≥ 10.5** (enforced CHECK constraints; standardized on **MariaDB 11.8**, Laravel `mariadb` driver — D22). Triggers via `DB::unprepared(...)` in `up()`, `DROP TRIGGER IF EXISTS` in `down()`.

Companion specs: posting logic in [`02-posting-engine.md`](02-posting-engine.md); BIR field/format rules in [`03-bir-accreditation.md`](03-bir-accreditation.md).

## 0. Money — `BIGINT` centavos (minor units)

All ledger money is `BIGINT` in **centavos**, never `DECIMAL`/float. Rationale: PHP has no native decimal; DECIMAL round-trips through string/float at the app boundary, whereas integers marshal losslessly through PDO/JSON/PHP `int` (64-bit). Sums, reversals, and aggregation are exact integer adds. Signed `BIGINT` max ≈ ₱9.2×10¹⁶ — far beyond any SME lifetime. Sub-centavo needs (unit prices, FX rates) live in **subledger** tables as `DECIMAL(19,4)`; only the rounded centavo total posts to the journal. Never mix the two in a ledger column. Centralize `/100` display + formatting in a `Money` cast / value object.

## 1. Tables

### 1.1 `account_types` (reference; seeded 5)
```sql
CREATE TABLE account_types (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(24) NOT NULL,                 -- asset|liability|equity|income|expense
  name VARCHAR(64) NOT NULL,
  normal_balance ENUM('debit','credit') NOT NULL,
  statement ENUM('balance_sheet','income_statement') NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), UNIQUE KEY uq_account_types_code (code)
) ENGINE=InnoDB;
```
A reference table (not an ENUM) so BIR FS-grouping/report metadata can extend without `ALTER ... MODIFY ENUM`.

### 1.2 `accounts` (chart of accounts)
```sql
CREATE TABLE accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_type_id TINYINT UNSIGNED NOT NULL,
  code VARCHAR(32) NOT NULL,                  -- account number, e.g. '1010'
  name VARCHAR(255) NOT NULL,
  parent_id BIGINT UNSIGNED NULL,             -- self-FK, sub-account hierarchy
  normal_balance ENUM('debit','credit') NOT NULL,  -- from type; flipped if contra
  is_contra TINYINT(1) NOT NULL DEFAULT 0,
  is_postable TINYINT(1) NOT NULL DEFAULT 1,  -- only leaf accounts accept lines
  is_active TINYINT(1) NOT NULL DEFAULT 1,    -- deactivate, never delete
  is_system TINYINT(1) NOT NULL DEFAULT 0,    -- OBE / Retained Earnings / Income Summary / Rounding
  -- BIR placeholders (nullable, filled in Phase 4; see 03-bir-accreditation.md):
  bir_tax_type VARCHAR(24) NULL,              -- vat|non_vat|exempt|zero_rated
  bir_atc_code VARCHAR(16) NULL,
  bir_fs_line VARCHAR(64) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_code (code),
  KEY idx_accounts_parent (parent_id),
  KEY idx_accounts_active_postable (is_active, is_postable),
  CONSTRAINT fk_accounts_type FOREIGN KEY (account_type_id) REFERENCES account_types(id),
  CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id),
  CONSTRAINT chk_accounts_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id)
) ENGINE=InnoDB;
```
Lines may reference only `is_postable=1 AND is_active=1` accounts (line-insert trigger + app). Parent/rollup accounts are `is_postable=0`. No hard delete — `is_active=0`; `is_system=1` accounts can never be deactivated/deleted.

### 1.3 `fiscal_years`, `fiscal_periods`, `ledger_settings`
```sql
CREATE TABLE fiscal_years (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_label VARCHAR(9) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_at TIMESTAMP NULL, closed_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_fy_label (year_label), UNIQUE KEY uq_fy_range (start_date,end_date)
) ENGINE=InnoDB;

CREATE TABLE fiscal_periods (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fiscal_year_id SMALLINT UNSIGNED NOT NULL,
  period_no TINYINT UNSIGNED NOT NULL,        -- 1..12 (+13 = year-end adjustment)
  start_date DATE NOT NULL, end_date DATE NOT NULL,
  status ENUM('open','closed','locked') NOT NULL DEFAULT 'open',
  closed_at TIMESTAMP NULL, closed_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_period (fiscal_year_id, period_no),
  KEY idx_period_dates (start_date,end_date),
  CONSTRAINT fk_period_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id)
) ENGINE=InnoDB;

CREATE TABLE ledger_settings (                -- singleton
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  accounting_basis ENUM('accrual','cash') NOT NULL DEFAULT 'accrual',
  posting_lock_date DATE NULL,                -- entry_date <= this is rejected
  current_fiscal_year_id SMALLINT UNSIGNED NULL,
  base_currency CHAR(3) NOT NULL DEFAULT 'PHP',
  opening_balance_equity_account_id BIGINT UNSIGNED NULL,
  retained_earnings_account_id BIGINT UNSIGNED NULL,
  income_summary_account_id BIGINT UNSIGNED NULL,
  rounding_account_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), CONSTRAINT chk_ledger_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB;
```
**Period-lock semantics (checked at post):** (a) `entry_date` falls in a period with `status='open'`; (b) `entry_date > posting_lock_date` (or NULL). Monthly close flips the period to `closed` and advances `posting_lock_date`. `locked` = audited/filed period no admin reopens without a settings change + audit event. `accounting_basis` affects **posting rules & reporting only** — the journal is basis-agnostic (see `02`).

### 1.4 `document_sequences` (gapless numbering)
```sql
CREATE TABLE document_sequences (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_type VARCHAR(24) NOT NULL,         -- 'GJ','SJ','PJ','CRJ','CDJ','OB','YEC','REV'
  fiscal_year SMALLINT UNSIGNED NOT NULL,
  prefix VARCHAR(16) NOT NULL DEFAULT '',
  pad_width TINYINT UNSIGNED NOT NULL DEFAULT 6,
  last_value BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- last COMMITTED number
  PRIMARY KEY (id), UNIQUE KEY uq_seq (document_type, fiscal_year)
) ENGINE=InnoDB;
```

### 1.5 `journal_entries` (header)
```sql
CREATE TABLE journal_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_number VARCHAR(40) NULL,              -- assigned at post; unique when set
  entry_date DATE NOT NULL,
  fiscal_period_id SMALLINT UNSIGNED NOT NULL,
  journal_book ENUM('general','sales','purchase','cash_receipts',
                    'cash_disbursements','opening_balance','year_end_close','reversal')
               NOT NULL DEFAULT 'general',
  description VARCHAR(500) NULL,
  status ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
  source_type VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,    -- polymorphic doc (app-enforced)
  idempotency_key VARCHAR(120) NULL,          -- unique; claimed before numbering
  reverses_entry_id BIGINT UNSIGNED NULL,
  reversed_by_entry_id BIGINT UNSIGNED NULL,
  reversal_reason VARCHAR(255) NULL,
  total_debit_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_credit_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  posted_by BIGINT UNSIGNED NULL, posted_at TIMESTAMP NULL,
  voided_by BIGINT UNSIGNED NULL, voided_at TIMESTAMP NULL,
  posting_hash CHAR(64) NULL,                 -- sha256 of canonical lines (tamper check)
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entry_number (entry_number),
  UNIQUE KEY uq_idempotency (idempotency_key),
  KEY idx_je_status (status), KEY idx_je_date (entry_date), KEY idx_je_period (fiscal_period_id),
  KEY idx_je_source (source_type, source_id), KEY idx_je_reverses (reverses_entry_id),
  CONSTRAINT fk_je_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods(id),
  CONSTRAINT fk_je_reverses FOREIGN KEY (reverses_entry_id) REFERENCES journal_entries(id),
  CONSTRAINT fk_je_revby FOREIGN KEY (reversed_by_entry_id) REFERENCES journal_entries(id),
  CONSTRAINT chk_je_posted_meta CHECK (status <> 'posted' OR (posted_at IS NOT NULL AND posted_by IS NOT NULL AND entry_number IS NOT NULL)),
  CONSTRAINT chk_je_void_meta CHECK (status <> 'void' OR (voided_at IS NOT NULL AND voided_by IS NOT NULL))
) ENGINE=InnoDB;
```
One posting = one entry. If a document ever needs multiple entries, add a `document_postings(source_type,source_id,journal_entry_id)` link table (open decision — see `06`).

### 1.6 `journal_lines`
```sql
CREATE TABLE journal_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_entry_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  line_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  debit_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
  credit_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
  memo VARCHAR(255) NULL,
  entry_date DATE NOT NULL,                   -- denormalized from header at insert (immutable)
  fiscal_period_id SMALLINT UNSIGNED NOT NULL,
  -- optional dimensions / BIR carry-forward (nullable):
  partner_type VARCHAR(24) NULL, partner_id BIGINT UNSIGNED NULL,   -- customer|vendor
  tax_code_id BIGINT UNSIGNED NULL, tax_base_centavos BIGINT UNSIGNED NULL, atc_code VARCHAR(16) NULL,
  department_id BIGINT UNSIGNED NULL, project_id BIGINT UNSIGNED NULL,
  source_line_ref VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jl_entry_line (journal_entry_id, line_no),
  KEY idx_jl_entry (journal_entry_id), KEY idx_jl_account (account_id),
  KEY idx_jl_period_account (fiscal_period_id, account_id, debit_centavos, credit_centavos),
  KEY idx_jl_partner (partner_type, partner_id),
  CONSTRAINT fk_jl_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_jl_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods(id),
  CONSTRAINT chk_jl_debit_xor_credit CHECK ((debit_centavos > 0) XOR (credit_centavos > 0))
) ENGINE=InnoDB;
```
`chk_jl_debit_xor_credit`: both columns UNSIGNED (≥0), so `(d>0) XOR (c>0)` is true only when exactly one is positive — this **forbids both-zero and both-nonzero at the DB with zero app help**. `ON DELETE CASCADE` fires only for draft entries (posted deletes are blocked upstream).

### 1.7 `account_period_balances` (derived cache — see §4)
```sql
CREATE TABLE account_period_balances (
  account_id BIGINT UNSIGNED NOT NULL,
  fiscal_period_id SMALLINT UNSIGNED NOT NULL,
  opening_signed BIGINT NOT NULL DEFAULT 0,   -- signed centavos, carried from prior period
  period_debits BIGINT UNSIGNED NOT NULL DEFAULT 0,
  period_credits BIGINT UNSIGNED NOT NULL DEFAULT 0,
  closing_signed BIGINT NOT NULL DEFAULT 0,
  rebuilt_at TIMESTAMP NULL,
  PRIMARY KEY (account_id, fiscal_period_id), KEY idx_apb_period (fiscal_period_id),
  CONSTRAINT fk_apb_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_apb_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods(id)
) ENGINE=InnoDB;
```

### 1.8 `audit_log` (append-only — see §6)
Defined in §6. **Note the composite PK required for partitioning.**

## 2. Balance-invariant enforcement (belt-and-suspenders)

MariaDB (like MySQL) has **no deferred/DEFERRABLE constraints**, so `Σdebits=Σcredits` cannot be validated incrementally as lines insert. The check is bound to one atomic event: the header's **`draft → posted` flip**.

### 2.1 The posting transaction (app — primary enforcer)
`App\Domain\Ledger\PostingService::post()` (full contract in `02`):
```
BEGIN;
1. INSERT journal_entries (status='draft', idempotency_key, created_by, entry_date, fiscal_period_id).
   -- unique idempotency_key: a duplicate fails HERE (before any number is drawn) → re-read & return winner.
2. INSERT journal_lines (parent is draft → allowed; each line satisfies the XOR CHECK).
3. Validate: Σdebit==Σcredit, ≥2 lines, Σ>0, accounts postable+active, period open AND entry_date > posting_lock_date.
4. Allocate gapless number: SELECT ... FOR UPDATE document_sequences; last_value+1 (see §3).
5. UPDATE journal_entries SET status='posted', entry_number, posted_by, posted_at, posting_hash, totals
      WHERE id=? AND status='draft';           -- fires trigger T4 (balance + period re-check)
6. Upsert account_period_balances for affected (account, period) (see §4).
7. INSERT audit_log ('entry.posted', before/after).
COMMIT;   -- any failure → ROLLBACK; the §4 sequence increment rolls back too → no gap.
```

### 2.2 Triggers (DB safety net)
Design principle: **only the header row changes at post time.** Lines are inserted while draft and never touched again → immutability triggers stay trivial.

- **T1 `bi_journal_lines` (BEFORE INSERT):** reject if parent `status<>'draft'`; reject if account not `is_postable AND is_active`; denormalize `entry_date`/`fiscal_period_id` from the header.
- **T2/T3 `bu_/bd_journal_lines` (BEFORE UPDATE/DELETE):** if parent `status<>'draft'` → `SIGNAL SQLSTATE '45000'` ("posted journal lines are immutable").
- **T4 `bu_journal_entries` (BEFORE UPDATE):**
  - `OLD.status='void'` → reject (void frozen).
  - `OLD.status='posted'` → allow exactly two updates, everything else rejected: (a) `posted→void` + void metadata; (b) **status unchanged, setting only `reversed_by_entry_id` (NULL→value, once) + `reversal_reason`** — this is the update `reverse()` performs; the original stays `posted` ("reversed" is represented by `reversed_by_entry_id` being set, not a status value). Freeze `entry_date`/`entry_number`/`fiscal_period_id`/`source_*`/`posted_at` (null-safe `<=>`) in both cases.
  - `OLD.status='draft' AND NEW.status='posted'` → re-check the period is open + `entry_date > posting_lock_date`; `SELECT SUM(debit),SUM(credit),COUNT(*) FROM journal_lines WHERE journal_entry_id=NEW.id`; reject if `count<2 OR Σdebit=0 OR Σdebit<>Σcredit`. (A trigger on `journal_entries` may read the sibling `journal_lines` table — legal.)
- **T5 `bd_journal_entries` (BEFORE DELETE):** reject unless `OLD.status='draft'` ("posted/void entries cannot be deleted — use reversal").

### 2.3 Why the app is primary
No deferred constraints → balance is checkable only at the flip. Triggers are defense-in-depth, not proof: bypassable by a privileged user, not re-run on row-based-replication replicas, opaque (`SIGNAL` → generic SQLSTATE 45000 — translate to friendly errors). So: **app `PostingService` is primary**, CHECK handles per-row invariants, triggers are the net, and a **nightly `ledger:verify`** re-derives every posted entry's balance from `journal_lines`, asserts `Σdebit=Σcredit` + `posting_hash`, and walks the audit hash chain — catching direct-SQL tampering, drift, or replica anomalies.

## 3. Gapless numbering
`AUTO_INCREMENT` is NOT gapless (rollbacks burn values). Allocate inside the posting transaction:
```sql
SELECT last_value, prefix, pad_width INTO @last,@pfx,@pad
  FROM document_sequences WHERE document_type=? AND fiscal_year=? FOR UPDATE;   -- X-lock this row
UPDATE document_sequences SET last_value = last_value+1 WHERE document_type=? AND fiscal_year=?;
SET @entry_number = CONCAT(@pfx, LPAD(@last+1, @pad, '0'));                     -- e.g. 'GJ-2026-000123'
```
Gapless because: `FOR UPDATE` serializes concurrent posters *of the same series*; commit → number used (sequential); rollback → `last_value` increment rolled back → number reused → **no gap**. Per-book series (`GJ/SJ/PJ/CRJ/CDJ`) spread contention. Keep the posting transaction short (validate before `BEGIN` where possible).

**Number format is mandatory, not an example:** `entry_number` has a global UNIQUE key, so the prefix **must embed series + fiscal year** (`'{type}-{year_label}-'`, e.g. `GJ-2026-000123`) or the per-year reset and cross-book counters collide on the first duplicate. Sequence rows are seeded with that prefix (validation rejects empty prefixes) and `PostingService` asserts the generated number contains the series and year label. `document_sequences.fiscal_year` references `fiscal_years.id` (works for non-calendar years). **Clarification:** the yearly reset applies to *internal journal book numbers*; customer-facing **invoice serials** (Phase 2) are a separate continuous series that never resets and continues across system migration (RMC 77-2024). **BIR:** voided documents **keep their number** (marked void); the reversing entry draws its own next number. Sequences carry a `branch_id` (default = the tenant's single main branch) because BIR serials are per document type **per branch** — see §7. See `03-bir-accreditation.md`.

## 4. Balance computation at scale (kept derived)
Summing all `journal_lines` forever is O(n). Two-tier:
1. **`account_period_balances`** maintained incrementally at post/void:
   ```sql
   INSERT INTO account_period_balances (account_id, fiscal_period_id, period_debits, period_credits)
   SELECT account_id, fiscal_period_id, SUM(debit_centavos), SUM(credit_centavos)
     FROM journal_lines WHERE journal_entry_id=? GROUP BY account_id, fiscal_period_id
   ON DUPLICATE KEY UPDATE period_debits=period_debits+VALUES(period_debits),
                           period_credits=period_credits+VALUES(period_credits);
   ```
   Posted entries are append-only, so aggregates only ever grow; reversals are appends of opposite sign and self-correct. No historical row is mutated in place.
2. **Roll-forward at period close:** compute each account's `closing_signed = opening_signed ± movement` (sign per `normal_balance`/`is_contra`), seed next period's `opening_signed`. Then: trial balance / balance sheet **as of a closed period** = read `closing_signed` (O(accounts)); **current open-period** balance = open-period `opening_signed` + SUM of that one period's lines (scan bounded to one period).

Back-dating into closed periods is blocked (§1.3, §2) → closed aggregates are provably final. **Rebuildable** via `php artisan ledger:rebuild-balances` (truncate + one grouped scan of `journal_lines` using `idx_jl_period_account`, then a roll-forward pass) — this proves it's a cache, not truth. `ledger:verify` recomputes into a temp shape and asserts equality. Optional third tier: a Redis hot-balance per account for dashboards, invalidated on post.

## 5. Opening balances & period close / retained earnings
- **Opening balances:** a posted, immutable opening-balance JE (`journal_book='opening_balance'`), dated the day before go-live. The **Opening Balance Equity** system account absorbs the plug so balances can be entered account-by-account; an accountant then reconciles OBE into **Retained Earnings**. Historical open AR/AP is captured via `partner_type/partner_id` line dimensions.
- **Monthly close:** flip period `closed`, roll balances forward, advance `posting_lock_date`. No closing *entry* — P&L reports are period-scoped sums.
- **Year-end close:** post a closing JE (`journal_book='year_end_close'`, dated FY `end_date`): debit each revenue for its credit balance / credit each expense for its debit balance, net → **Income Summary**; then close Income Summary → **Retained Earnings**; (if applicable) close drawings/dividends. Set `fiscal_years.status='closed'`. Nominal accounts show zero into the new year; the balance sheet's Retained Earnings reflects accumulated results. *Accountant sign-off: Income Summary vs direct-to-RE, drawings vs dividends, book-vs-tax (MCIT/NOLCO) — see `06`.*

## 6. Audit log (BIR RMC 5-2021 Annex B; append-only)
```sql
CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at DATETIME(6) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL, actor_name VARCHAR(255) NULL, actor_ip VARBINARY(16) NULL,
  event VARCHAR(48) NOT NULL,                 -- 'entry.posted','entry.voided','account.deactivated','login',...
  auditable_type VARCHAR(64) NULL, auditable_id BIGINT UNSIGNED NULL,
  document_number VARCHAR(40) NULL,
  before_json JSON NULL, after_json JSON NULL, context_json JSON NULL,
  prev_hash CHAR(64) NULL, row_hash CHAR(64) NOT NULL,   -- sha256(prev_hash || canonical(payload))
  PRIMARY KEY (id, occurred_at),              -- composite: partition column MUST be in every unique key
  KEY idx_audit_actor (actor_user_id), KEY idx_audit_target (auditable_type, auditable_id),
  KEY idx_audit_event_time (event, occurred_at), KEY idx_audit_time (occurred_at)
) ENGINE=InnoDB
PARTITION BY RANGE (YEAR(occurred_at)) (
  PARTITION p2026 VALUES LESS THAN (2027),
  PARTITION p2027 VALUES LESS THAN (2028),
  PARTITION pmax  VALUES LESS THAN MAXVALUE
);
```
> **Design correction applied:** the PK is `(id, occurred_at)`, not `id` alone — MariaDB/MySQL require every partitioning column to be part of every unique key, so `PRIMARY KEY(id)` + `PARTITION BY YEAR(occurred_at)` would fail to create.

**Append-only, two layers:** (1) `BEFORE UPDATE`/`BEFORE DELETE` triggers that `SIGNAL SQLSTATE '45000'` ("audit_log is append-only"); (2) the application DB user is granted only `INSERT, SELECT` on `audit_log`. **Tamper evidence:** `row_hash` chains to the previous row; `ledger:verify` walks the chain and flags breaks. **No silent deletes anywhere:** corrections are reversals, accounts are deactivated not dropped, every state change writes an `audit_log` row in the same transaction. **Retention:** ≥ 5 years statutory (RR 7-2024) — but older issuances say 10 (see `03` open items); engineer conservatively (10 yr) with a **legal-hold flag** suspending purge during any protest/refund/case. Backups via `spatie/laravel-backup` (installed); keep a PH-resident readable copy.

## 7. Tax, profile & branch tables (added 2026-07-21 — closes audit blockers S1/S2/S3/S5/A3)

These were referenced by `02`/`03` but previously had no DDL. All tenant-DB.

```sql
CREATE TABLE tax_codes (                      -- referenced by journal_lines.tax_code_id
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(16) NOT NULL,                  -- 'OV12','IV12','PT3','EXEMPT','ZR0'
  kind ENUM('output_vat','input_vat','percentage_tax','exempt','zero_rated') NOT NULL,
  rate_bp SMALLINT UNSIGNED NOT NULL,         -- basis points: 12% = 1200, 3% = 300
  account_id BIGINT UNSIGNED NULL,            -- GL account the tax posts to
  default_atc VARCHAR(16) NULL,
  effective_from DATE NOT NULL, effective_to DATE NULL,   -- date-effective: NEVER hard-code rates
  PRIMARY KEY (id), UNIQUE KEY uq_tax_code_eff (code, effective_from),
  CONSTRAINT fk_tax_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE atc_rates (                      -- versioned EWT reference (03 TL;DR 9); seeded Phase 2, CPA-verified Phase 4
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  atc_code VARCHAR(16) NOT NULL,              -- 'WC160','WI010',...
  description VARCHAR(255) NOT NULL,
  payee_type ENUM('individual','juridical') NOT NULL,
  rate_bp SMALLINT UNSIGNED NOT NULL,
  threshold_centavos BIGINT UNSIGNED NULL,    -- e.g. ₱3M / ₱720k gross thresholds
  requires_sworn_declaration TINYINT(1) NOT NULL DEFAULT 0,
  legal_basis VARCHAR(64) NULL,
  effective_from DATE NOT NULL, effective_to DATE NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_atc_eff (atc_code, payee_type, effective_from)
) ENGINE=InnoDB;
-- Vendor-side sworn-declaration status/validity lives on the Phase-2 vendors table
-- (sworn_declaration_valid_until DATE NULL): absent/expired → resolve the higher rate.

CREATE TABLE company_profile (                -- singleton; feeds the mandatory report header/footer + returns
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  registered_name VARCHAR(255) NOT NULL,
  registered_address VARCHAR(500) NOT NULL,
  tin CHAR(9) NOT NULL, branch_code VARCHAR(5) NOT NULL DEFAULT '000',
  taxpayer_classification ENUM('micro','small','medium','large') NULL,   -- EOPT class
  is_twa TINYINT(1) NOT NULL DEFAULT 0, twa_effective_date DATE NULL,
  accn VARCHAR(64) NULL, accn_issued_at DATE NULL,        -- Acknowledgement Certificate (go-live gate)
  registration_mode ENUM('manual','loose_leaf','cba','cas') NULL,
  PRIMARY KEY (id), CONSTRAINT chk_company_singleton CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE tax_regimes (                    -- effective-dated: VAT vs percentage-tax vs 8% (03: "per period, from day one")
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  regime ENUM('vat','percentage_tax','eight_percent') NOT NULL,
  effective_from DATE NOT NULL, effective_to DATE NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_regime_from (effective_from)
) ENGINE=InnoDB;

CREATE TABLE branches (                       -- BIR serials are per branch; v1 UI = single branch, schema ready
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_code VARCHAR(5) NOT NULL DEFAULT '000',
  name VARCHAR(128) NOT NULL, address VARCHAR(500) NULL,
  is_main TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id), UNIQUE KEY uq_branch_code (branch_code)
) ENGINE=InnoDB;
-- document_sequences gains branch_id SMALLINT UNSIGNED NOT NULL (FK branches, default main);
-- UNIQUE becomes (document_type, fiscal_year, branch_id).

CREATE TABLE account_roles (                  -- CoA role map backing 02's AccountResolver (A/R, A/P, Sales, VAT, CWT, WHT, GRNI…)
  role VARCHAR(32) NOT NULL,                  -- 'ar','ap','sales','output_vat','input_vat','cwt_asset','wht_payable','rounding','grni',...
  account_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role),
  CONSTRAINT fk_role_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
```

**Software name/version** (header/footer + AC history): repo-level `config/compliance.php` (`software_name`, `software_version` — semver, bumped per release with a major/minor-enhancement classification per RMO 9-2021), consumed by the header/footer component; per-tenant AC re-issuance history = rows appended to a `company_profile_history` (or audit_log events).

**Year-end close vs lock date (closes audit blocker S10):** period 13 is the **adjustment period**, defined as `end_date..end_date` of the fiscal year, and may be `open` while periods 1–12 are closed. `JournalDraft` carries an optional explicit `fiscal_period_id` — the `year_end_close`/adjustment books route to period 13 explicitly instead of by date lookup (which would be ambiguous when 12 and 13 both cover `end_date`). The lock rule reads: `entry_date > posting_lock_date` **unless posting into the open adjustment period**.

**System actor (closes audit S9):** `users` live in the **tenant DB** (roles are per-tenant; see `06`). Provisioning seeds a `system` user; non-interactive postings (year-end close jobs, imports, recurring) use its id for `created_by`/`posted_by`, with `audit_log.actor_name='system'`.

## Open decisions (see `06-decisions.md`)
- MariaDB ≥ 10.5 confirmed in every environment (dev box: 11.8 ✔ — verified 2026-07-21).
- One-entry-per-document sufficient for v1, or add `document_postings`.
- Monthly close default: soft (`closed`) vs hard (`locked`).
- Retention target 5 vs 10 yr (CPA).
- `department_id`/`project_id` on `journal_lines` are reserved dimensions (no tables yet — v2 tracking categories; app-enforced until then).
