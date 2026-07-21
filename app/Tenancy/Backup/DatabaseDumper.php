<?php

namespace App\Tenancy\Backup;

use App\Models\Tenant;

/**
 * Engine-facing dump/restore for one tenant database (docs/specs/10 §1).
 * MariaDbDumper is the real implementation; tests swap in an sqlite
 * double the same way TenantProvisioningTest fakes TenantDatabaseManager.
 */
interface DatabaseDumper
{
    /**
     * Dump the tenant's database to a gzipped SQL artifact.
     * Must include trigger DDL (--triggers) — the T1–T5 safety net has to
     * survive a restore. Returns the local path of the artifact.
     */
    public function dump(Tenant $tenant): string;

    /**
     * Restore an artifact into a scratch database and return the name of a
     * configured connection pointing at it, for verification queries.
     */
    public function restore(Tenant $tenant, string $archivePath): string;

    /** Drop the scratch database created by restore() and purge its connection. */
    public function cleanupRestore(string $connection): void;
}
