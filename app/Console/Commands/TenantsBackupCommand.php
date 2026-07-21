<?php

namespace App\Console\Commands;

use App\Models\BackupCatalogEntry;
use App\Models\Tenant;
use App\Tenancy\Backup\DatabaseDumper;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Nightly per-tenant dumps (docs/specs/10 §1). One artifact per tenant per
 * run — restoring one tenant must never mean restoring the fleet. Failures
 * are recorded and skipped so tenant #3 can't sink the rest of the run.
 */
class TenantsBackupCommand extends Command
{
    protected $signature = 'tenants:backup
        {--tenant=* : Limit to these tenant IDs or subdomains}
        {--tier=operational : operational (rotated) or compliance (never auto-pruned)}
        {--no-prune : Skip retention pruning after the run}';

    protected $description = 'Dump each tenant database to the backup disk, one gzipped artifact per tenant';

    public function handle(DatabaseDumper $dumper): int
    {
        $disk = config('tenant-backup.disk');
        $tier = $this->option('tier');
        $failures = 0;

        foreach ($this->targetTenants() as $tenant) {
            $failures += $this->backupTenant($tenant, $dumper, $disk, $tier) ? 0 : 1;
        }

        if (! $this->option('no-prune')) {
            $this->prune($disk);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function backupTenant(Tenant $tenant, DatabaseDumper $dumper, string $disk, string $tier): bool
    {
        $slug = $tenant->subdomain ?? (string) $tenant->id;
        $startedAt = now();
        $this->info("Backing up tenant [{$slug}]…");

        try {
            $localPath = $dumper->dump($tenant);
            $remotePath = config('tenant-backup.prefix')."/{$slug}/".$startedAt->format('Y-m-d_His').'.sql.gz';

            $stream = fopen($localPath, 'rb');
            Storage::disk($disk)->put($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            BackupCatalogEntry::create([
                'tenant_id' => $tenant->id,
                'disk' => $disk,
                'path' => $remotePath,
                // RR 9-2009 §6.1: label states the software + records covered.
                'label' => sprintf(
                    '%s full DB dump — tenant %s — as of %s',
                    config('app.name'),
                    $slug,
                    $startedAt->toDateTimeString(),
                ),
                'software_name' => config('app.name'),
                'software_version' => config('app.version'),
                'size_bytes' => filesize($localPath),
                'checksum_sha256' => hash_file('sha256', $localPath),
                'tier' => $tier,
                'status' => 'completed',
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            unlink($localPath);

            return true;
        } catch (Throwable $e) {
            BackupCatalogEntry::create([
                'tenant_id' => $tenant->id,
                'disk' => $disk,
                'path' => '',
                'label' => "FAILED backup — tenant {$slug}",
                'software_name' => config('app.name'),
                'software_version' => config('app.version'),
                'tier' => $tier,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            $this->error("Backup failed for tenant [{$slug}]: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Rotate operational dumps past keep_days. Compliance-tier and
     * legal-hold rows are never age-pruned (docs/specs/10 §1).
     */
    protected function prune(string $disk): void
    {
        $expired = BackupCatalogEntry::query()
            ->where('tier', 'operational')
            ->where('legal_hold', false)
            ->where('created_at', '<', now()->subDays(config('tenant-backup.keep_days')))
            ->get();

        foreach ($expired as $entry) {
            if ($entry->path !== '') {
                Storage::disk($entry->disk)->delete($entry->path);
            }
            $entry->delete();
            $this->line("Pruned expired backup [{$entry->path}]");
        }
    }

    /** @return Collection<int, Tenant> */
    protected function targetTenants()
    {
        $query = Tenant::query()->where('provisioning_status', '!=', 'failed');

        if ($only = $this->option('tenant')) {
            $query->where(fn ($q) => $q->whereIn('id', $only)->orWhereIn('subdomain', $only));
        }

        return $query->get();
    }
}
