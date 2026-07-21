<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Multitenancy\SwitchTenantDatabaseTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenancyStructureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Landlord and tenant schemas must live in separate migration paths
     * (docs/specs/01, HANDOFF Phase 0): tenant DBs must NOT receive the
     * landlord set (tenants/cache/jobs/telescope), and the landlord set
     * must not contain tenant-only files.
     */
    public function test_tenant_migration_path_exists_and_is_tenant_only(): void
    {
        $tenantPath = database_path('migrations/tenant');

        $this->assertDirectoryExists($tenantPath);

        $files = collect(glob($tenantPath.'/*.php'))->map(fn ($f) => basename($f));

        $this->assertTrue(
            $files->contains(fn ($f) => str_contains($f, 'users')),
            'Tenant path must create its own users table (D20: users live in the tenant DB).'
        );

        foreach (['tenants', 'telescope', 'jobs', 'cache'] as $landlordOnly) {
            $this->assertFalse(
                $files->contains(fn ($f) => str_contains($f, $landlordOnly)),
                "Tenant migration path must not contain landlord-only migration [{$landlordOnly}]."
            );
        }
    }

    public function test_orphan_landlord_migration_directory_is_gone(): void
    {
        $this->assertDirectoryDoesNotExist(
            database_path('migrations/landlord'),
            'The divergent duplicate tenants migration (migrations/landlord) must be removed.'
        );
    }

    public function test_switch_task_builds_tenant_connection_from_template_and_restores_default(): void
    {
        $originalDefault = DB::getDefaultConnection();

        $tenant = Tenant::create([
            'name' => 'Acme',
            'domain' => 'acme.soro.local',
            'subdomain' => 'acme',
            'database' => 'tenant_acme',
            'is_active' => true,
        ]);

        $task = new SwitchTenantDatabaseTask;
        $task->makeCurrent($tenant);

        $this->assertSame('tenant', DB::getDefaultConnection());
        $this->assertSame('tenant_acme', config('database.connections.tenant.database'));
        // Template must be the configured engine template (mariadb per D22), not hardcoded mysql
        $this->assertSame(
            config('database.connections.'.config('multitenancy.tenant_database_template_connection').'.driver'),
            config('database.connections.tenant.driver')
        );

        $task->forgetCurrent();

        $this->assertSame(
            $originalDefault,
            DB::getDefaultConnection(),
            'forgetCurrent must restore the original default connection, not a hardcoded one.'
        );
    }
}
