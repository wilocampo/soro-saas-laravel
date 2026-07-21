<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Engine-facing tenant database operations (MariaDB per D22).
 * Kept separate from ProvisionTenant so orchestration/seeding can be
 * tested against a sqlite tenant connection with this class faked.
 */
class TenantDatabaseManager
{
    public function createDatabase(Tenant $tenant): void
    {
        // Must run on the server/template connection — the app default may be sqlite (dev).
        DB::connection($this->templateConnection())
            ->statement("CREATE DATABASE IF NOT EXISTS `{$tenant->getDatabaseName()}`");

        $this->configureTenantConnection($tenant);
    }

    public function dropDatabase(Tenant $tenant): void
    {
        DB::connection($this->templateConnection())
            ->statement("DROP DATABASE IF EXISTS `{$tenant->getDatabaseName()}`");
    }

    /**
     * Tenant DBs receive ONLY the tenant migration set (docs/specs/01) —
     * never the landlord set (tenants/cache/jobs/telescope).
     */
    public function migrate(Tenant $tenant): void
    {
        $this->configureTenantConnection($tenant);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    public function configureTenantConnection(Tenant $tenant): void
    {
        config([
            'database.connections.tenant' => array_merge(
                config('database.connections.'.$this->templateConnection()),
                ['database' => $tenant->getDatabaseName()]
            ),
        ]);

        DB::purge('tenant');
    }

    protected function templateConnection(): string
    {
        return config('multitenancy.tenant_database_template_connection', 'mariadb');
    }
}
