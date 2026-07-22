<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Per-tenant backups nightly (RPO ≤ 24h fleet-wide, docs/specs/10 §6);
// app timezone is Asia/Manila (D18), so these are Manila times.
Schedule::command('tenants:backup')->dailyAt('01:30');

// Monthly proof-of-restore with a logged outcome (docs/specs/10 §1).
Schedule::command('tenants:backup-restore-test')->monthlyOn(1, '03:00');

// Nightly ledger reconciliation (docs/specs/10 §6): a hash-chain break or
// balance drift is a sev-1 page, not a log line — hence emailOutputOnFailure
// once alerting is configured. Runs per tenant via tenants:artisan (Phase 2 ops).
Schedule::command('ledger:verify')->dailyAt('02:30');

// The stock twin (docs/specs/08 §4.4). Runs AFTER ledger:verify so a GL
// problem is reported before the tie-out that depends on the GL being sound.
Schedule::command('inventory:verify')->dailyAt('02:45');

// Password-rotation notices (docs/specs/04, 10 §5).
Schedule::command('users:password-rotation-check')->dailyAt('08:00');
