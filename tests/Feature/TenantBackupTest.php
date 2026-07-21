<?php

namespace Tests\Feature;

use App\Models\BackupCatalogEntry;
use App\Models\RestoreTestLog;
use App\Models\Tenant;
use App\Tenancy\Backup\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Backup orchestration (docs/specs/10 §1) against an sqlite dumper double —
 * same pattern as TenantProvisioningTest: the engine is faked, the command
 * flow, catalog rows, retention rules, and restore verification are real.
 */
class TenantBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        config(['tenant-backup.disk' => 'backups']);

        $this->sourceDbPath = database_path('testing_backup_'.uniqid().'.sqlite');
    }

    protected function tearDown(): void
    {
        @unlink($this->sourceDbPath);
        parent::tearDown();
    }

    private function makeTenant(string $subdomain = 'acme'): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($subdomain),
            'domain' => "{$subdomain}.soro.local",
            'subdomain' => $subdomain,
            'database' => "tenant_{$subdomain}",
            'is_active' => true,
        ]);
        $tenant->forceFill(['provisioning_status' => 'active'])->save();

        return $tenant;
    }

    /** A real migrated tenant schema, so restore verification is honest. */
    private function migratedSourceDb(): string
    {
        touch($this->sourceDbPath);
        config(['database.connections.tenant' => [
            'driver' => 'sqlite',
            'database' => $this->sourceDbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('tenant');

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        return $this->sourceDbPath;
    }

    private function bindDumper(DatabaseDumper $dumper): void
    {
        $this->app->instance(DatabaseDumper::class, $dumper);
    }

    public function test_backup_creates_one_artifact_per_tenant_with_catalog_row(): void
    {
        $this->makeTenant('acme');
        $this->makeTenant('globex');
        $this->bindDumper(new SqliteGzipDumper($this->migratedSourceDb()));

        $this->artisan('tenants:backup')->assertSuccessful();

        $entries = BackupCatalogEntry::all();
        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertSame('completed', $entry->status);
            Storage::disk('backups')->assertExists($entry->path);

            // Per-tenant artifact path + verifiable checksum
            $this->assertStringContainsString("tenant-backups/{$entry->tenant->subdomain}/", $entry->path);
            $this->assertSame(
                hash('sha256', Storage::disk('backups')->get($entry->path)),
                $entry->checksum_sha256,
            );

            // RR 9-2009 §6.1 label: software name + version present
            $this->assertSame(config('app.name'), $entry->software_name);
            $this->assertSame(config('app.version'), $entry->software_version);
            $this->assertGreaterThan(0, $entry->size_bytes);
        }
    }

    public function test_failed_tenant_is_recorded_and_does_not_sink_the_run(): void
    {
        $this->makeTenant('bad');
        $this->makeTenant('good');
        $this->bindDumper(new SqliteGzipDumper($this->migratedSourceDb(), failFor: 'bad'));

        $this->artisan('tenants:backup')->assertFailed();

        $this->assertSame('failed', BackupCatalogEntry::whereRelation('tenant', 'subdomain', 'bad')->value('status'));
        $this->assertSame('completed', BackupCatalogEntry::whereRelation('tenant', 'subdomain', 'good')->value('status'));
        $this->assertStringContainsString('simulated dump failure', BackupCatalogEntry::where('status', 'failed')->value('error'));
    }

    public function test_prune_rotates_operational_but_never_legal_hold_or_compliance(): void
    {
        $tenant = $this->makeTenant('acme');

        $mk = function (string $path, string $tier, bool $hold) use ($tenant): BackupCatalogEntry {
            Storage::disk('backups')->put($path, 'dump');
            $entry = BackupCatalogEntry::create([
                'tenant_id' => $tenant->id,
                'disk' => 'backups',
                'path' => $path,
                'label' => 'old dump',
                'software_name' => 'Soro',
                'software_version' => 'test',
                'tier' => $tier,
                'legal_hold' => $hold,
                'status' => 'completed',
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            // Age the row past keep_days (created_at is guarded from mass assignment)
            BackupCatalogEntry::withoutTimestamps(
                fn () => $entry->forceFill(['created_at' => now()->subDays(400)])->save()
            );

            return $entry;
        };

        $expired = $mk('tenant-backups/acme/expired.sql.gz', 'operational', false);
        $held = $mk('tenant-backups/acme/held.sql.gz', 'operational', true);
        $compliance = $mk('tenant-backups/acme/yearend.sql.gz', 'compliance', false);

        // No matching tenants to dump — this run only exercises pruning.
        $this->bindDumper(new SqliteGzipDumper($this->migratedSourceDb()));
        $this->artisan('tenants:backup', ['--tenant' => ['nonexistent']])->assertSuccessful();

        $this->assertDatabaseMissing('backup_catalog', ['id' => $expired->id]);
        Storage::disk('backups')->assertMissing($expired->path);

        $this->assertDatabaseHas('backup_catalog', ['id' => $held->id]);
        Storage::disk('backups')->assertExists($held->path);
        $this->assertDatabaseHas('backup_catalog', ['id' => $compliance->id]);
        Storage::disk('backups')->assertExists($compliance->path);
    }

    public function test_restore_test_passes_on_a_real_dump_and_logs_the_outcome(): void
    {
        $tenant = $this->makeTenant('acme');
        $dumper = new SqliteGzipDumper($this->migratedSourceDb());
        $this->bindDumper($dumper);

        $this->artisan('tenants:backup', ['--no-prune' => true])->assertSuccessful();
        $entry = BackupCatalogEntry::firstOrFail();

        $this->artisan('tenants:backup-restore-test', ['--backup' => $entry->id])->assertSuccessful();

        $log = RestoreTestLog::firstOrFail();
        $this->assertSame('passed', $log->outcome);
        $this->assertTrue($log->checksum_verified);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($entry->id, $log->backup_catalog_id);
        $this->assertStringContainsString('checksum: ok', $log->details);
        $this->assertStringContainsString('ok', $log->details);

        // Scratch DB cleaned up after verification
        $this->assertFileDoesNotExist($dumper->restoredPath());
    }

    public function test_restore_test_fails_on_a_tampered_artifact(): void
    {
        $this->makeTenant('acme');
        $this->bindDumper(new SqliteGzipDumper($this->migratedSourceDb()));

        $this->artisan('tenants:backup', ['--no-prune' => true])->assertSuccessful();
        $entry = BackupCatalogEntry::firstOrFail();

        // Tamper with the stored artifact — checksum must catch it
        Storage::disk('backups')->put($entry->path, gzencode('tampered'));

        $this->artisan('tenants:backup-restore-test', ['--backup' => $entry->id])->assertFailed();

        $log = RestoreTestLog::firstOrFail();
        $this->assertSame('failed', $log->outcome);
        $this->assertFalse($log->checksum_verified);
    }
}

/**
 * Dump/restore double: "dumps" a tenant DB by gzipping a migrated sqlite
 * file; restore inflates it and exposes a restore_test sqlite connection —
 * so the commands' catalog/verify/cleanup logic runs for real.
 */
class SqliteGzipDumper implements DatabaseDumper
{
    private string $restoredPath = '';

    public function __construct(
        private string $sourceDbPath,
        private ?string $failFor = null,
    ) {}

    public function dump(Tenant $tenant): string
    {
        if ($tenant->subdomain === $this->failFor) {
            throw new RuntimeException('simulated dump failure');
        }

        $path = tempnam(sys_get_temp_dir(), 'sqlite-dump-');
        file_put_contents($path, gzencode((string) file_get_contents($this->sourceDbPath)));

        return $path;
    }

    public function restore(Tenant $tenant, string $archivePath): string
    {
        $this->restoredPath = sys_get_temp_dir()."/restore_test_{$tenant->id}.sqlite";
        file_put_contents($this->restoredPath, (string) gzdecode((string) file_get_contents($archivePath)));

        config(['database.connections.restore_test' => [
            'driver' => 'sqlite',
            'database' => $this->restoredPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('restore_test');

        return 'restore_test';
    }

    public function cleanupRestore(string $connection): void
    {
        DB::purge($connection);
        @unlink($this->restoredPath);
    }

    public function restoredPath(): string
    {
        return $this->restoredPath;
    }
}
