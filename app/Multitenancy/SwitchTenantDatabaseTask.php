<?php

namespace App\Multitenancy;

use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Spatie\Multitenancy\Contracts\IsTenant;

class SwitchTenantDatabaseTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        /** @var Tenant $tenant */
        $tenant = $tenant;
        $databaseName = $tenant->getDatabaseName();
        
        config([
            'database.connections.tenant' => array_merge(
                config('database.connections.mysql'),
                ['database' => $databaseName]
            )
        ]);
        
        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
    }

    public function forgetCurrent(): void
    {
        DB::purge('tenant');
        DB::setDefaultConnection('mysql');
    }
}
