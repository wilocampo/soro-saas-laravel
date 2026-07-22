<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The production hardening gate (docs/specs/10 §7, Phase 5).
 *
 * A checklist nobody runs is decoration, so this is a command with an exit
 * code: wire it into deploy and a misconfigured box fails the deploy rather
 * than quietly serving stack traces with TINs in them.
 *
 * Each check states WHY it matters, because the person running this at 1am
 * during a go-live is not the person who wrote the spec.
 */
class ProductionCheckCommand extends Command
{
    protected $signature = 'app:production-check {--strict : treat warnings as failures}';

    protected $description = 'Verify the production hardening checklist before go-live (docs/specs/10 §7)';

    /** @var list<array{level:string, title:string, detail:string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->checkDebugAndEnv();
        $this->checkTelescope();
        $this->checkSecrets();
        $this->checkDatabaseGrants();
        $this->checkBackups();
        $this->checkPdfRenderer();
        $this->checkSingleTenant();

        $failures = 0;
        $warnings = 0;

        foreach ($this->findings as $finding) {
            $line = "  {$finding['title']} — {$finding['detail']}";

            if ($finding['level'] === 'fail') {
                $failures++;
                $this->error("✗{$line}");
            } else {
                $warnings++;
                $this->warn("!{$line}");
            }
        }

        if ($this->findings === []) {
            $this->info('Production check passed — nothing outstanding.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("{$failures} failure(s), {$warnings} warning(s).");

        // Warnings only fail the deploy under --strict; a failure always does.
        $strictBreach = $warnings !== 0 && $this->option('strict');

        return $failures === 0 && ! $strictBreach ? self::SUCCESS : self::FAILURE;
    }

    private function checkDebugAndEnv(): void
    {
        if (config('app.debug')) {
            $this->flag('APP_DEBUG is on', 'Stack traces leak TINs, queries and payloads to anyone who triggers an error.');
        }

        if (config('app.env') !== 'production') {
            $this->advise('APP_ENV is not production', 'Framework behaviour and error pages differ from what you tested.');
        }

        if (config('app.timezone') !== 'Asia/Manila') {
            $this->flag('Timezone is not Asia/Manila', 'Entry dates decide fiscal periods and BIR deadlines (D18).');
        }
    }

    private function checkTelescope(): void
    {
        // Telescope records request payloads — passwords in flight, TINs,
        // entire invoices. On a production box that is a DPA hazard.
        if (config('telescope.enabled')) {
            $this->flag('Telescope is enabled', 'It records request payloads including credentials and personal data (RA 10173).');
        }
    }

    private function checkSecrets(): void
    {
        if (config('app.key') === null || config('app.key') === '') {
            $this->flag('APP_KEY is unset', 'Sessions and encrypted columns are unreadable or forgeable without it.');
        }

        if (config('billing.enabled') && config('cashier.secret') === null) {
            $this->flag('Billing is on but STRIPE_SECRET is unset', 'Checkout will fail at the worst moment.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->flag('APP_URL is not https', 'RMC 5-2021 Annex B item 11 expects TLS for remote access.');
        }
    }

    /**
     * The app DB user must hold only INSERT and SELECT on `audit_log`
     * (CLAUDE.md #8): the append-only guarantee rests on privileges, not
     * only on triggers, because a trigger can be dropped by whoever can
     * drop it.
     */
    private function checkDatabaseGrants(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->advise('Not running on MariaDB', 'CHECK constraints and triggers are the DB safety net (D22).');

            return;
        }

        try {
            $grants = collect(DB::select('SHOW GRANTS FOR CURRENT_USER()'))
                ->flatMap(fn ($row) => array_values((array) $row))
                ->implode(' ');
        } catch (\Throwable) {
            $this->advise('Could not read database grants', 'Verify by hand that the app user cannot UPDATE or DELETE audit_log.');

            return;
        }

        if (str_contains($grants, 'ALL PRIVILEGES')) {
            $this->flag(
                'The app database user has ALL PRIVILEGES',
                'It must not be able to UPDATE or DELETE audit_log — grant INSERT, SELECT on that table only (CLAUDE.md #8).'
            );
        }
    }

    private function checkBackups(): void
    {
        if (! Schema::hasTable('backup_catalog')) {
            $this->advise('No backup catalogue', 'Run tenants:backup at least once and schedule it (spec 10 §1).');

            return;
        }

        $latest = DB::table('backup_catalog')->max('created_at');

        if ($latest === null) {
            $this->flag('No backup has ever run', 'RR 9-2009 §6 expects a readable, PH-resident copy of the books.');
        } elseif (now()->diffInHours($latest) > 48) {
            $this->flag('The newest backup is over 48h old', 'Check the schedule and the dump credentials.');
        }
    }

    private function checkPdfRenderer(): void
    {
        // Invoices are legal documents; if Chrome is missing, the first
        // customer who asks for a PDF gets a 500 instead.
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->flag('Puppeteer is not installed', 'Invoice and report PDFs need headless Chrome — run `npm ci` on the server.');
        }
    }

    private function checkSingleTenant(): void
    {
        if (config('app.single_tenant') && ! config('billing.enabled')) {
            return;   // per-client VPS, billed by contract: correct
        }

        if (config('app.single_tenant') && config('billing.enabled')) {
            $this->advise(
                'SINGLE_TENANT with billing enabled',
                'A per-client install is usually billed by contract; confirm this is intended.'
            );
        }
    }

    /** Named `flag`, not `fail`: Command::fail() already exists and is final in intent. */
    private function flag(string $title, string $detail): void
    {
        $this->findings[] = ['level' => 'fail', 'title' => $title, 'detail' => $detail];
    }

    private function advise(string $title, string $detail): void
    {
        $this->findings[] = ['level' => 'warn', 'title' => $title, 'detail' => $detail];
    }
}
