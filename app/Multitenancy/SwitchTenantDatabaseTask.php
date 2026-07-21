<?php

namespace App\Multitenancy;

use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchTenantDatabaseTask implements SwitchTenantTask
{
    /**
     * The default connection that was active before the first tenant switch,
     * restored by forgetCurrent(). Never hardcode the engine here — dev/test
     * defaults differ (sqlite in tests, mariadb in dev — D22).
     */
    protected static ?string $originalDefaultConnection = null;

    public function makeCurrent(IsTenant $tenant): void
    {
        static::$originalDefaultConnection ??= DB::getDefaultConnection();

        $template = config('multitenancy.tenant_database_template_connection', 'mariadb');

        config([
            'database.connections.tenant' => array_merge(
                config("database.connections.{$template}"),
                ['database' => $tenant->getDatabaseName()]
            ),
        ]);

        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
    }

    public function forgetCurrent(): void
    {
        DB::purge('tenant');
        DB::setDefaultConnection(
            static::$originalDefaultConnection ?? config('database.default')
        );
        static::$originalDefaultConnection = null;
    }
}
