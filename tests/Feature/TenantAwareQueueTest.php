<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase-0 exit criterion (docs/specs/10 §6): a queued job dispatched for a
 * tenant MUST run against that tenant's database after a full round-trip
 * through the queue store — "a posting job hitting the wrong tenant DB is
 * the nightmare scenario." This exercises the real pipeline: dispatch while
 * the tenant is current → payload carries tenantId via Context → worker
 * picks it up with NO tenant current → spatie re-binds the tenant → the
 * switch task swaps the database → the job's write lands in the tenant DB.
 */
class TenantAwareQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDbPath = database_path('testing_queue_'.uniqid().'.sqlite');

        // Real queue store (not sync) so the job round-trips through a
        // payload; the jobs table is pinned to the landlord connection.
        config(['queue.default' => 'database']);

        // sqlite stand-in for the MariaDB template connection (as in
        // TenantProvisioningTest): the switch task overlays the tenant's
        // database name onto this template.
        config(['database.connections.tenant_template' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['multitenancy.tenant_database_template_connection' => 'tenant_template']);
    }

    protected function tearDown(): void
    {
        @unlink($this->tenantDbPath);
        parent::tearDown();
    }

    private function makeMigratedTenant(): Tenant
    {
        touch($this->tenantDbPath);
        config(['database.connections.tenant' => [
            'driver' => 'sqlite',
            'database' => $this->tenantDbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('tenant');

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        return Tenant::create([
            'name' => 'Acme',
            'domain' => 'acme.soro.local',
            'subdomain' => 'acme',
            'database' => $this->tenantDbPath, // sqlite: database name IS the file path
            'is_active' => true,
        ]);
    }

    public function test_a_queued_job_runs_against_the_dispatching_tenants_database(): void
    {
        $tenant = $this->makeMigratedTenant();
        $landlordConnection = DB::getDefaultConnection();

        // Dispatch while the tenant is current — makeCurrent() must run the
        // switch task (the DB swap), not just bind the container.
        $tenant->makeCurrent();
        $this->assertSame('tenant', DB::getDefaultConnection());

        WriteProbeUserJob::dispatch('probe@tenant.test');
        Tenant::forgetCurrent();

        // The job payload was stored on the LANDLORD jobs table even though
        // the tenant connection was current at dispatch time.
        $this->assertSame($landlordConnection, DB::getDefaultConnection());
        $this->assertSame(1, DB::table('jobs')->count());

        // Worker round-trip with no tenant current.
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0]);

        // The worker leaves the processed job's tenant current until the next
        // job — drop back to the landlord before asserting.
        Tenant::forgetCurrent();

        // The write landed in the TENANT database…
        $tenantDb = DB::connection('tenant');
        $this->assertSame(
            1,
            $tenantDb->table('users')->where('email', 'probe@tenant.test')->count(),
            'The queued job did not write to the tenant database — the switch task did not run on the queue path.'
        );

        // …and NOT in the landlord database (the nightmare scenario).
        $this->assertSame(
            0,
            DB::connection($landlordConnection)->table('users')->where('email', 'probe@tenant.test')->count(),
            'The queued job wrote to the LANDLORD database.'
        );

        $this->assertSame(0, DB::connection($landlordConnection)->table('jobs')->count());
    }

    public function test_make_current_runs_the_database_switch_task(): void
    {
        $tenant = $this->makeMigratedTenant();

        $tenant->makeCurrent();

        $this->assertSame('tenant', DB::getDefaultConnection());
        $this->assertSame(
            $this->tenantDbPath,
            config('database.connections.tenant.database'),
            'makeCurrent() must route through MakeTenantCurrentAction so switch_tenant_tasks run.'
        );

        Tenant::forgetCurrent();
        $this->assertNotSame('tenant', DB::getDefaultConnection());
    }
}

/** Writes one row via the DEFAULT connection — wherever tenancy points it. */
class WriteProbeUserJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $email) {}

    public function handle(): void
    {
        DB::table('users')->insert([
            'name' => 'Probe',
            'email' => $this->email,
            'password' => 'irrelevant-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
