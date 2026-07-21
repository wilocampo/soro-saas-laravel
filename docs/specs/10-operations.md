# 10 — Operations & SaaS-Operator Obligations (runbook-grade)

The ledger specs cover correctness; this covers **running it as a service**. Verified against the actual repo config 2026-07-21. Items marked ⛔ are launch-blocking.

## 1. Per-tenant backups ⛔ (current config backs up the wrong thing)
`config/backup.php` dumps only the **landlord** connection — with DB-per-tenant, every tenant ledger is currently unbackuped. Required:
- **`tenants:backup` command** (Phase 0/1): iterate tenants, one dump artifact per tenant DB per run (`{tenant}/{date}.sql.gz`), via per-tenant `backup:run --only-db` or `mysqldump --single-transaction --triggers`. Per-tenant files are non-negotiable — restoring one tenant must not mean restoring the fleet.
- **Dump correctness test:** dumps must include the T1–T5 triggers and restore must re-create `audit_log` partitions — test, don't assume.
- **WORM + PH residency** (RR 9-2009 §6): S3-compatible storage with **Object Lock (compliance mode)**, a Philippines-resident copy, encrypted archives.
- **Restore-testing with a log** (BIR expects the artifact): monthly job restores a random tenant dump to a scratch DB, runs `migrate:status` + `ledger:verify` + `inventory:verify`, writes a **`restore_test_log`** row (landlord: tenant, backup id, checksum, outcome, duration, operator).
- **Two retention tiers** (decision in `06`): operational backups 30–90 days; **compliance archives** — one immutable per-close/yearly archive per tenant on the conservative 10-yr horizon, with the **legal-hold flag extended to backup purge jobs**.
- **`backup_catalog` table** (landlord): label, software name+version, records covered — RR 9-2009 §6.1 wording; cheap now, painful to reconstruct.

## 2. Tenant lifecycle ops
- **Fleet migrations:** landlord first, then `tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"`. Handle **partial failure** (tenant #37 of 200 fails → mixed schema versions): per-tenant migration state in the landlord DB, resumable/idempotent loop, `--pretend` dry-run on staging. Trigger DDL needs the migration user to hold **`TRIGGER` privilege** (+ binlog/`log_bin_trust_function_creators` handling on managed MySQL) — deploy-blocking detail.
- **Provisioning saga** (Phase 0): create DB → migrate → seed roles/admin → **grants** (below), with a state machine (`provisioning → active/failed`) and compensation (drop or quarantine half-created DBs; routing must never reach a partially-migrated DB).
- **DB users & grants** ⛔: `audit_log` INSERT/SELECT-only for the app user (01 §6) means provisioning must issue **per-DB grants** and deploys need a *separate privileged migration user*. Currently implied nowhere — now a provisioning requirement.
- **Offboarding** (decision in `06` + ToS): departing tenant gets a **standard export bundle** (full SQL dump + books `.csv/.dat` + PDFs + printable audit log — reuses the Phase-4 exporters); operator retains a frozen, legal-hold-aware archive for a fixed contractual window, then deletes (indefinite silent retention conflicts with DPA minimization). Soft-disable routing → export → checksum acknowledged → `DROP DATABASE`.

## 3. CI ⛔ (nothing exists; larastan not installed)
Single `ci.yml`: pint → **larastan** (add to require-dev, baseline, required check) → SQLite fast suite → **`mysql:8.0` service container** for the `Ledger` testsuite + `ledger:verify` on fixtures (assert `>= 8.0.16` in a test; the gapless-concurrency property requires real MySQL connections) → `npm ci && npm run build` (flushes the Inertia v1/v2 dependency conflict) → `composer audit`. Branch protection: Ledger + larastan required on every PR. Phase-0 exit criterion.

## 4. Data Privacy Act (RA 10173) ⛔ — applies squarely; entirely organizational-plus-a-little-code
- **TIN/tax data is Sensitive Personal Information** (RA 10173 Sec. 3(l)) — counterparty TINs, addresses, withholding data at scale.
- **NPC registration is mandatory** at ≥1,000 SPI data subjects (NPC Circular 2022-04): appoint a **DPO**, register DPO + Data Processing System via NPCRS — new systems within **20 days of commencement**. A multi-tenant accounting SaaS crosses the threshold almost immediately. **Add the NPCRS registration as a Phase-5 go-live gate beside the ACCN gate** (same "registration blocks launch" pattern).
- **Breach response:** notify NPC + affected subjects within **72 hours**, full report in 5 days, annual incident report (NPC Circular 16-03) — runbook with named roles.
- **Role split:** operator is a **PIP (processor)** for tenant books (each tenant is the PIC of its counterparties' data) and a **PIC** for signup/billing data → DPA processing terms in the SaaS ToS + flow-down to hosting.
- **Cheap-now code:** privacy-notice page, signup consent copy, a one-page **personal-data inventory** doc (which tables/columns hold PII — counterparties, users, 2307/alphalist artifacts, `audit_log.actor_*`, backups, Telescope), offboarding delete honoring retention-law override (DPA "required by law" resolves the minimization-vs-BIR-retention tension — write the reasoning down for counsel).

## 5. Security ops
- **Encryption at rest** (decision in `06`): MySQL InnoDB tablespace encryption (keyring) + encrypted backups — **not** Laravel `encrypted` casts on TIN columns (breaks `.dat` exporters, joins, per-tenant dumps). **APP_KEY custody**: rotation + escrow documented (losing it = losing Cashier/encrypted data).
- **Secrets:** `.env` perms, deploy-time templating (sops/age), distinct per-env DB credentials, app-vs-migration user split.
- **TLS for subdomain tenancy:** wildcard cert needs **DNS-01 ACME automation**; HSTS; cookie domain scoping across tenant subdomains.
- **Login hardening:** rate-limit auth routes; **2FA decision** (at least for `owner`/`accountant` — table stakes for a system-of-record); the 30-day password rotation (04) needs a scheduled enforcement job + expiry notices — a real Phase-1 ticket.
- **Supply chain:** `composer audit`/`npm audit` in CI; Renovate/Dependabot.
- **Session store:** sessions/cache/jobs tables live on the **landlord** connection (write it down — 04's single-active-session needs one authoritative store across subdomains).

## 6. Availability / system-of-record commitments (put numbers in the handoff)
- **RPO ≤ 15 min / RTO ≤ 4 h** (single tenant; ≤ 24 h fleet) via nightly per-tenant dumps **+ MySQL binlog retention ≥ 7 days** for point-in-time recovery. Honest and achievable on a VPS.
- **Maintenance windows:** Sundays 00:00–04:00 **Asia/Manila**, with **freeze windows around BIR filing peaks** (the 10th, the 25th after quarter-end, end-of-month after quarter, January alphalist season). An accounting SaaS down on a filing deadline loses customers.
- **`ledger:verify` / `inventory:verify` as alarms:** scheduled via cron → `tenants:artisan` loop; a hash-chain break or tie-out drift is a **sev-1 page**, not a log line. Wire spatie backup-failure notifications to email/Slack.
- **Queue tenancy** ⛔: jobs (posting, PDFs, backups, verify) must be **tenant-aware** (spatie `queues_are_tenant_aware_by_default` is already true — keep it, and add a test); jobs table on landlord. A posting job hitting the wrong tenant DB is the nightmare scenario — Phase-0 exit criterion with a test.
- **Timezone** (decision in `06`): app timezone **Asia/Manila** for this single-market product; `entry_date`, period boundaries, `posting_lock_date` comparisons, and close-day boundaries are Manila dates; `audit_log` partitions by Manila year. Currently `config/app.php` is UTC — will produce off-by-one-day close bugs on Dec 31/Jan 1 if unaddressed.

## 7. Environments
- **Staging with a seeded demo tenant** — required and nearly free. Compliance nuance: NIRC Sec. 264-B bans "training mode" in production — the compliant answer to demo/training needs is **staging, never a sandbox tenant in prod**. Written here so nobody adds one later.
- **Production hardening (Phase-5 gate):** `TELESCOPE_ENABLED=false` (it records request payloads — TINs/passwords in flight; a DPA hazard), `APP_DEBUG=false`, `config:cache`/`route:cache`, registration off under `SINGLE_TENANT`, non-leaking error pages, PII-aware log rotation.
