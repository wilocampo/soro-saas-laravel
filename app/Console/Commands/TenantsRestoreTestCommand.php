<?php

namespace App\Console\Commands;

use App\Models\BackupCatalogEntry;
use App\Models\RestoreTestLog;
use App\Tenancy\Backup\DatabaseDumper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Monthly proof-of-restore (docs/specs/10 §1): restore a tenant dump to a
 * scratch DB, verify checksum + schema, write a restore_test_log row. BIR
 * expects the log artifact, not just the backups.
 */
class TenantsRestoreTestCommand extends Command
{
    protected $signature = 'tenants:backup-restore-test
        {--tenant= : Tenant ID to test (default: the tenant with the oldest recent test)}
        {--backup= : Specific backup_catalog ID to restore}
        {--operator= : Recorded in the log (default: the console user)}';

    protected $description = 'Restore a tenant backup into a scratch database, verify it, and log the outcome';

    public function handle(DatabaseDumper $dumper): int
    {
        $entry = $this->pickEntry();
        $tenant = $entry?->tenant;

        if ($entry === null || $tenant === null) {
            $this->error('No completed backup found to test.');

            return self::FAILURE;
        }

        $startedAt = hrtime(true);
        $checks = [];
        $checksumVerified = false;
        $localPath = null;
        $connection = null;

        try {
            $localPath = tempnam(sys_get_temp_dir(), 'restore-test-');
            file_put_contents($localPath, Storage::disk($entry->disk)->readStream($entry->path));

            $checksumVerified = hash_file('sha256', $localPath) === $entry->checksum_sha256;
            $checks[] = 'checksum: '.($checksumVerified ? 'ok' : 'MISMATCH');

            $connection = $dumper->restore($tenant, $localPath);

            $checks[] = $this->verifySchema($connection);

            // Phase 1 will chain ledger:verify / inventory:verify here once
            // those commands exist — the scratch connection is ready for them.

            $outcome = $checksumVerified && ! str_contains(implode($checks), 'MISSING') ? 'passed' : 'failed';
        } catch (Throwable $e) {
            $checks[] = 'exception: '.$e->getMessage();
            $outcome = 'failed';
        } finally {
            if ($connection !== null) {
                $dumper->cleanupRestore($connection);
            }
            if ($localPath !== null && file_exists($localPath)) {
                unlink($localPath);
            }
        }

        RestoreTestLog::create([
            'tenant_id' => $entry->tenant_id,
            'backup_catalog_id' => $entry->id,
            'checksum_verified' => $checksumVerified,
            'outcome' => $outcome,
            'details' => implode('; ', $checks),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'operator' => $this->option('operator') ?? get_current_user(),
        ]);

        $message = "Restore test {$outcome} for backup #{$entry->id} (tenant {$entry->tenant_id}): ".implode('; ', $checks);
        $outcome === 'passed' ? $this->info($message) : $this->error($message);

        return $outcome === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The restored schema must contain every tenant migration (triggers ride
     * along via --triggers; the T1–T5 round-trip test joins the Ledger suite).
     */
    protected function verifySchema(string $connection): string
    {
        if (! Schema::connection($connection)->hasTable('migrations')) {
            return 'migrations table: MISSING';
        }

        $ran = DB::connection($connection)->table('migrations')->count();
        $expected = iterator_count(
            Finder::create()->files()->in(database_path('migrations/tenant'))->name('*.php')
        );

        return $ran >= $expected
            ? "migrations: {$ran}/{$expected} ok"
            : "migrations: {$ran}/{$expected} MISSING";
    }

    protected function pickEntry(): ?BackupCatalogEntry
    {
        if ($id = $this->option('backup')) {
            return BackupCatalogEntry::with('tenant')->where('status', 'completed')->find($id);
        }

        $query = BackupCatalogEntry::with('tenant')
            ->where('status', 'completed')
            ->latest('id');

        if ($tenantId = $this->option('tenant')) {
            return $query->where('tenant_id', $tenantId)->first();
        }

        // Rotate coverage: test the tenant whose last restore test is oldest.
        $lastTested = RestoreTestLog::query()
            ->selectRaw('tenant_id, MAX(created_at) as tested_at')
            ->groupBy('tenant_id')
            ->pluck('tested_at', 'tenant_id');

        return $query->get()
            ->sortBy(fn (BackupCatalogEntry $e) => $lastTested[$e->tenant_id] ?? '')
            ->first();
    }
}
