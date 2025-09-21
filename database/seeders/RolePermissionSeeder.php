<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Create permissions
        $permissions = [
            // User management
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            
            // Tenant management (landlord only)
            'tenants.view',
            'tenants.create',
            'tenants.edit',
            'tenants.delete',
            
            // Settings
            'settings.view',
            'settings.edit',
            
            // Dashboard
            'dashboard.view',
            
            // Notifications
            'notifications.view',
            'notifications.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $user = Role::firstOrCreate(['name' => 'user']);

        // Assign permissions to roles
        $superAdmin->givePermissionTo(Permission::all());
        
        $admin->givePermissionTo([
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'settings.view',
            'settings.edit',
            'dashboard.view',
            'notifications.view',
            'notifications.manage',
        ]);
        
        $manager->givePermissionTo([
            'users.view',
            'users.create',
            'users.edit',
            'settings.view',
            'dashboard.view',
            'notifications.view',
        ]);
        
        $user->givePermissionTo([
            'dashboard.view',
            'notifications.view',
        ]);
    }
}