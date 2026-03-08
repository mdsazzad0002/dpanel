<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
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

        // Keep only system user types.
        $systemRoles = ['admin', 'reseller', 'general'];

        // Migrate legacy role name to the current one.
        $legacyGeneralRole = Role::query()->where('name', 'general_user')->first();
        $generalRole = Role::firstOrCreate(['name' => 'general']);
        if ($legacyGeneralRole && $legacyGeneralRole->id !== $generalRole->id) {
            DB::table('model_has_roles')
                ->where('role_id', $legacyGeneralRole->id)
                ->update(['role_id' => $generalRole->id]);
            $legacyGeneralRole->delete();
        }

        Role::query()
            ->whereNotIn('name', $systemRoles)
            ->get()
            ->each(function (Role $role): void {
                $role->users()->detach();
                $role->permissions()->detach();
                $role->delete();
            });

        $allPermissions = Permission::query()->pluck('name')->values()->all();

        $admin = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'reseller']);
        Role::firstOrCreate(['name' => 'general']);

        $admin->syncPermissions($allPermissions);
    }
}
