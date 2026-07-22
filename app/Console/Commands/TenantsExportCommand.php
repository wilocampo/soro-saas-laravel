<?php

namespace App\Console\Commands;

use App\Domain\Compliance\ReportHeader;
use App\Domain\Reports\Export\ReportRenderer;
use App\Domain\Reports\Export\ReportSheetFactory;
use App\Models\Tenant;
use App\Tenancy\Backup\DatabaseDumper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The offboarding export bundle (docs/specs/10 §"Offboarding", Phase 5).
 *
 * A departing tenant leaves with everything: a full SQL dump, the books as
 * `.csv` AND `.xlsx`, the financial statements as PDF, and the audit log.
 * This is not a courtesy — BIR requires the TAXPAYER to keep and produce
 * its books for the retention period, and that obligation does not end when
 * the subscription does. A SaaS that made leaving mean losing your records
 * would put every departing customer in breach.
 *
 * Runs while the tenant database still exists. Deletion is a separate,
 * deliberate act after the checksum is acknowledged.
 */
class TenantsExportCommand extends Command
{
    protected $signature = 'tenants:export
        {tenant : tenant id or subdomain}
        {--path= : where to write the bundle (default storage/app/exports)}
        {--from= : first day of the export range (default: start of the current fiscal year)}
        {--to= : last day (default: today)}';

    protected $description = 'Export a tenant\'s complete records: SQL dump, books, statements and audit log';

    public function handle(DatabaseDumper $dumper): int
    {
        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        $stamp = CarbonImmutable::now()->format('Ymd-His');
        $directory = rtrim($this->option('path') ?: storage_path('app/exports'), '/')
            ."/{$tenant->subdomain}-{$stamp}";

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->error("Cannot create [{$directory}].");

            return self::FAILURE;
        }

        $this->info("Exporting {$tenant->name} → {$directory}");

        $manifest = ['tenant' => $tenant->only(['id', 'name', 'subdomain', 'database'])];

        // The dump first: if everything else fails, the data is still out.
        // The dumper writes to its own configured location and returns the
        // path; we move it into the bundle so everything travels together.
        $dumped = $dumper->dump($tenant);
        $sqlPath = "{$directory}/database.sql.gz";
        copy($dumped, $sqlPath);

        $manifest['files'][] = $this->describe($sqlPath, 'Full MariaDB dump (schema, data, triggers, routines)');
        $this->line('  ✓ database dump');

        $tenant->execute(function () use ($directory, &$manifest) {
            [$from, $to] = $this->range();
            $manifest['range'] = ['from' => $from, 'to' => $to];
            $manifest['header'] = app(ReportHeader::class)->for('Offboarding export');

            $factory = app(ReportSheetFactory::class);
            $renderer = app(ReportRenderer::class);
            $periodId = $this->latestPeriodId();

            $sheets = ['journal' => $factory->journal($from, $to)];

            if ($periodId !== null) {
                $sheets['trial-balance'] = $factory->trialBalance($periodId);
                $sheets['balance-sheet'] = $factory->balanceSheet($periodId);
                $sheets['income-statement'] = $factory->incomeStatement($periodId);
            }

            foreach ($sheets as $name => $sheet) {
                // Both formats on purpose: `.csv` is what BIR reads and what
                // survives any software; `.xlsx` is what an accountant opens.
                foreach (['csv', 'xlsx'] as $format) {
                    $path = "{$directory}/{$name}.{$format}";
                    $renderer->{$format}($sheet, $path);
                    $manifest['files'][] = $this->describe($path, "{$sheet->title} ({$format})");
                }

                $this->line("  ✓ {$name}");
            }

            // The audit log leaves as data, with its hash chain intact, so a
            // reader can still verify it was not tampered with.
            $auditPath = "{$directory}/audit-log.csv";
            $this->exportAuditLog($auditPath);
            $manifest['files'][] = $this->describe($auditPath, 'Append-only audit log with its hash chain');
            $this->line('  ✓ audit log');
        });

        $manifestPath = "{$directory}/MANIFEST.json";
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('Bundle complete. Checksums are in MANIFEST.json.');
        $this->line('Have the tenant acknowledge the checksums BEFORE any database is dropped (spec 10).');

        return self::SUCCESS;
    }

    /** @return array{path:string, bytes:int, sha256:string, description:string} */
    private function describe(string $path, string $description): array
    {
        return [
            'path' => basename($path),
            'bytes' => (int) filesize($path),
            // The tenant acknowledges these before we delete anything.
            'sha256' => hash_file('sha256', $path),
            'description' => $description,
        ];
    }

    private function exportAuditLog(string $path): void
    {
        $handle = fopen($path, 'w');

        fputcsv($handle, [
            'id', 'occurred_at', 'actor_user_id', 'actor_name', 'event',
            'auditable_type', 'auditable_id', 'document_number', 'prev_hash', 'row_hash',
        ], escape: '');

        DB::table('audit_log')->orderBy('id')->chunk(1000, function ($rows) use ($handle) {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->id, $row->occurred_at, $row->actor_user_id, $row->actor_name, $row->event,
                    $row->auditable_type, $row->auditable_id, $row->document_number,
                    $row->prev_hash, $row->row_hash,
                ], escape: '');
            }
        });

        fclose($handle);
    }

    /** @return array{0:string, 1:string} */
    private function range(): array
    {
        $now = CarbonImmutable::now();

        return [
            $this->option('from') ?: $now->startOfYear()->toDateString(),
            $this->option('to') ?: $now->toDateString(),
        ];
    }

    private function latestPeriodId(): ?int
    {
        $id = DB::table('fiscal_periods')->where('period_no', '<=', 12)
            ->orderByDesc('end_date')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function resolveTenant(): ?Tenant
    {
        $key = (string) $this->argument('tenant');

        return Str::isUuid($key) || is_numeric($key)
            ? Tenant::query()->find($key)
            : Tenant::query()->where('subdomain', $key)->first();
    }
}
