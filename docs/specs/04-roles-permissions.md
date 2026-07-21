# 04 — Roles, Permissions & CAS Security

Roles/permissions use `spatie/laravel-permission`, seeded **per tenant DB** on provisioning (teams feature stays OFF — isolation is physical, per DB). This spec also captures the BIR CAS access-control requirements the software must enforce.

## Roles (default set, per tenant)

| Role | Can do | Cannot |
|---|---|---|
| `owner` | Everything: billing, users, close/reopen periods, manage CoA, post, reverse, reports, settings | — |
| `accountant` | Manage CoA, create/post/reverse entries, close periods, opening balances, all reports/exports | Billing, delete users |
| `bookkeeper` | Create **draft** entries + documents (invoices/bills/payments) | **Post**, close/reopen, reverse (see open decision below) |
| `auditor` (read-only) | View + export all reports, view audit log | Any write |

## Permissions (granular; roles are bundles)
```
accounts.view  accounts.manage
journal.view   journal.draft   journal.post   journal.reverse
periods.view   periods.close   periods.reopen
documents.view documents.create documents.post documents.void
reports.view   reports.export
settings.view  settings.manage
users.view     users.manage
billing.manage
audit.view
```
Map: `owner`=all; `accountant`=all except `billing.manage`, `users.manage`, **`periods.reopen`** (reopen is owner-only per the note below); `bookkeeper`=`*.view` **except `audit.view`**, plus `journal.draft`, `documents.create`, `reports.view`; `auditor`=`*.view`, `reports.export`, `audit.view`. Inventory (spec `08`) adds: `inventory.{view,receive,adjust,count.approve,transfer}` — `count.approve` is accountant+, receiving/counting entry is bookkeeper-level.

**Open decision (see `06`):** does `bookkeeper` get `journal.post`/`documents.post` (post-but-not-close), or draft-only? Default here is draft-only; confirm with the user.

## Seeding (Phase 0 fix)
New tenant DBs are currently **not seeded**. Provisioning must seed: the roles/permissions above (extend `RolePermissionSeeder`) **and** an initial `owner` user. Move provisioning out of `TenantController` into a job/action + `artisan` command (see `06`).

## BIR CAS access-control requirements (RMC 5-2021 Annex B item 11 — see `03`)
The system MUST enforce, independent of the role model:
- **Least privilege / role-based access** to accounting functions (the matrix above satisfies this).
- **Single active session per user**; automatic logout on a new login.
- **Password policy:** periodic rotation (~30 days), complexity/alphanumeric, lockout after failed attempts.
- **Encrypted transport** (TLS) for all remote access.
- **No "suppression" capabilities** — no training/demo mode that records to real books, no hidden delete, no reset-to-zero of recorded sales (NIRC Sec. 264-B). Any "clear data" is dev-only and impossible in production tenants.
- Every privileged action (post, void, close, CoA change, user/role change, login) writes an **`audit_log`** row (see `01` §6).

## Notes
- `owner`/`accountant` posting/closing actions are the ones that must appear in the audit trail with user + timestamp.
- Period **reopen** is deliberately high-privilege (`owner` only) and always audited; a `locked` period cannot be reopened without a settings change + audit event (`01` §1.3).
