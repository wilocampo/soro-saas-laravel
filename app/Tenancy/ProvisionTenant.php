<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Tenant\TenantRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Provisioning saga (docs/specs/10 §2): create DB → migrate tenant path →
 * seed roles + `system` actor + initial owner. State machine on
 * tenants.provisioning_status with compensation on failure — routing must
 * never reach a partially-migrated tenant DB.
 */
class ProvisionTenant
{
    public function __construct(
        protected TenantDatabaseManager $databases,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $owner
     */
    public function __invoke(Tenant $tenant, array $owner): void
    {
        $tenant->forceFill(['provisioning_status' => 'provisioning'])->save();

        try {
            $this->databases->createDatabase($tenant);
            $this->databases->migrate($tenant);
            $this->seedTenant($owner);

            $tenant->forceFill(['provisioning_status' => 'active'])->save();
        } catch (Throwable $e) {
            // Compensate: never leave a half-created DB reachable.
            try {
                $this->databases->dropDatabase($tenant);
            } finally {
                $tenant->forceFill(['provisioning_status' => 'failed'])->save();
            }

            throw $e;
        }
    }

    /**
     * Runs against the tenant connection: spec-04 roles/permissions, the
     * non-interactive `system` actor (D20), and the initial owner user.
     */
    protected function seedTenant(array $owner): void
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');

        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            (new TenantRolesSeeder)->run();

            $system = new User([
                'name' => 'System',
                'email' => 'system@internal.invalid',
                'password' => Hash::make(str()->random(64)),
            ]);
            $system->is_system = true; // deliberately not mass-assignable
            $system->save();

            User::create([
                'name' => $owner['name'],
                'email' => $owner['email'],
                'password' => Hash::make($owner['password']),
            ])->assignRole('owner');
        } finally {
            DB::setDefaultConnection($original);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
