<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Tenancy\ProvisionTenant;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDbPath = database_path('testing_tenant_'.uniqid().'.sqlite');
    }

    protected function tearDown(): void
    {
        @unlink($this->tenantDbPath);
        parent::tearDown();
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Acme',
            'domain' => 'acme.soro.local',
            'subdomain' => 'acme',
            'database' => 'tenant_acme',
            'is_active' => true,
        ]);
    }

    private function fakeManager(): SqliteTenantDatabaseManager
    {
        return new SqliteTenantDatabaseManager($this->tenantDbPath);
    }

    public function test_provisioning_migrates_and_seeds_roles_system_and_owner(): void
    {
        $tenant = $this->makeTenant();

        (new ProvisionTenant($this->fakeManager()))($tenant, [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@acme.ph',
            'password' => 'secret-password',
        ]);

        $this->assertSame('active', $tenant->fresh()->provisioning_status);

        $t = DB::connection('tenant');

        // Spec-04 roles seeded in the TENANT database
        $roles = $t->table('roles')->pluck('name')->all();
        foreach (['owner', 'accountant', 'bookkeeper', 'auditor'] as $role) {
            $this->assertContains($role, $roles);
        }

        // System actor (D20) + initial owner with the owner role
        $this->assertSame(1, $t->table('users')->where('is_system', true)->count());

        $ownerUser = $t->table('users')->where('email', 'juan@acme.ph')->first();
        $this->assertNotNull($ownerUser);

        $ownerRoleId = $t->table('roles')->where('name', 'owner')->value('id');
        $this->assertTrue(
            $t->table('model_has_roles')
                ->where('role_id', $ownerRoleId)
                ->where('model_id', $ownerUser->id)
                ->exists()
        );

        // Landlord-only tables must NOT exist in the tenant DB
        $this->assertFalse($t->getSchemaBuilder()->hasTable('tenants'));
        $this->assertFalse($t->getSchemaBuilder()->hasTable('jobs'));

        // Default connection restored after seeding
        $this->assertNotSame('tenant', DB::getDefaultConnection());
    }

    public function test_failed_provisioning_compensates_and_marks_failed(): void
    {
        $tenant = $this->makeTenant();

        $manager = new class($this->tenantDbPath) extends SqliteTenantDatabaseManager
        {
            public bool $dropped = false;

            public function migrate(Tenant $tenant): void
            {
                throw new RuntimeException('boom');
            }

            public function dropDatabase(Tenant $tenant): void
            {
                $this->dropped = true;
                parent::dropDatabase($tenant);
            }
        };

        try {
            (new ProvisionTenant($manager))($tenant, [
                'name' => 'X', 'email' => 'x@x.ph', 'password' => 'password123',
            ]);
            $this->fail('Provisioning should rethrow.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('failed', $tenant->fresh()->provisioning_status);
        $this->assertTrue($manager->dropped, 'Compensation must drop the half-created DB.');
    }
}

/**
 * Test double: engine-specific CREATE/DROP DATABASE replaced with a sqlite
 * file; migrate() is inherited so the REAL tenant migration path runs.
 */
class SqliteTenantDatabaseManager extends TenantDatabaseManager
{
    public function __construct(private string $dbPath) {}

    public function createDatabase(Tenant $tenant): void
    {
        touch($this->dbPath);
        $this->configureTenantConnection($tenant);
    }

    public function dropDatabase(Tenant $tenant): void
    {
        @unlink($this->dbPath);
    }

    public function configureTenantConnection(Tenant $tenant): void
    {
        config([
            'database.connections.tenant' => [
                'driver' => 'sqlite',
                'database' => $this->dbPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('tenant');
    }
}
