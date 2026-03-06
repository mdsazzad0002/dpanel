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

        // Keep admin as the only default role.
        Role::query()
            ->where('name', '!=', 'admin')
            ->get()
            ->each(function (Role $role): void {
                $role->users()->detach();
                $role->permissions()->detach();
                $role->delete();
            });

        $allPermissions = Permission::query()->pluck('name')->values()->all();

        $admin = Role::firstOrCreate(['name' => 'admin']);

        $admin->syncPermissions($allPermissions);
    }
}
