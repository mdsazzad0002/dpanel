<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'view_dashboard',
            'manage_websites',
            'manage_email',
            'manage_apache',
            'use_terminal',
            'manage_subscriptions',
            'manage_users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $reseller = Role::firstOrCreate(['name' => 'reseller']);
        $generalUser = Role::firstOrCreate(['name' => 'general_user']);

        $superAdmin->syncPermissions($permissions);
        $reseller->syncPermissions([
            'view_dashboard',
            'manage_websites',
            'manage_email',
            'manage_subscriptions',
        ]);
        $generalUser->syncPermissions([
            'view_dashboard',
        ]);
    }
}
