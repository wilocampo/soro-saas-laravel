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
