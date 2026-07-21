<?php

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Per-tenant accounting roles & permissions (docs/specs/04).
 * Runs against the tenant connection during provisioning.
 * bookkeeper is draft-only (open decision in 06); auditor is read-only.
 */
class TenantRolesSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'accounts.view', 'accounts.manage',
            'journal.view', 'journal.draft', 'journal.post', 'journal.reverse',
            'periods.view', 'periods.close', 'periods.reopen',
            'documents.view', 'documents.create', 'documents.post', 'documents.void',
            'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.count.approve', 'inventory.transfer',
            'reports.view', 'reports.export',
            'settings.view', 'settings.manage',
            'users.view', 'users.manage',
            'billing.manage',
            'audit.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $views = collect($permissions)->filter(fn ($p) => str_ends_with($p, '.view'))->all();

        Role::firstOrCreate(['name' => 'owner'])
            ->givePermissionTo(Permission::all());

        Role::firstOrCreate(['name' => 'accountant'])->givePermissionTo(
            collect($permissions)->reject(fn ($p) => in_array($p, [
                'billing.manage', 'users.manage', 'periods.reopen', // reopen is owner-only (04)
            ]))->all()
        );

        Role::firstOrCreate(['name' => 'bookkeeper'])->givePermissionTo([
            ...collect($views)->reject(fn ($p) => $p === 'audit.view')->all(), // no audit access (04)
            'journal.draft',
            'documents.create',
            'inventory.receive',
        ]);

        Role::firstOrCreate(['name' => 'auditor'])->givePermissionTo([
            ...$views,
            'reports.export',
        ]);
    }
}
