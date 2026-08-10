<?php

namespace Database\Seeders;

use App\Support\UserAccessCache;
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

        // Canonical permission registry. Keep this idempotent so upgrades add
        // newly introduced permissions without duplicating existing records.
        // Numeric priority controls grouping and display order; it is not a role label.
        $permissions = [
            'view_dashboard' => 1,
            'manage_websites' => 1,
            'manage_dns' => 1,
            'manage_email' => 1,
            'manage_databases' => 1,
            'manage_backups' => 1,
            'manage_cron_jobs' => 1,
            'manage_redis' => 1,
            'manage_ssl' => 1,
            'manage_git' => 1,
            'use_file_manager' => 1,
            'manage_migrations' => 2,
            'manage_php' => 2,
            'view_monitoring' => 2,
            'manage_subscriptions' => 2,
            'manage_packages' => 2,
            'manage_users' => 2,
            'manage_security' => 3,
            'manage_servers' => 3,
            'manage_apache' => 3,
            'use_terminal' => 3,
        ];

        foreach ($permissions as $permissionName => $priority) {
            $permission = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            $permission->forceFill(['priority' => $priority])->save();
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

        $generalPermissions = [
            'view_dashboard', 'manage_websites', 'manage_dns', 'manage_email',
            'manage_databases', 'manage_backups', 'manage_cron_jobs', 'manage_redis',
            'manage_ssl', 'manage_git', 'use_file_manager',
        ];
        $resellerPermissions = array_values(array_unique(array_merge($generalPermissions, [
            'manage_migrations', 'manage_php', 'view_monitoring',
            'manage_subscriptions', 'manage_packages', 'manage_users',
        ])));
        $allPermissions = Permission::query()->orderBy('priority')->orderBy('name')->pluck('name')->values()->all();

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $reseller = Role::firstOrCreate(['name' => 'reseller']);
        $general = Role::firstOrCreate(['name' => 'general']);

        $admin->syncPermissions($allPermissions);
        $reseller->syncPermissions($resellerPermissions);
        $general->syncPermissions($generalPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        UserAccessCache::invalidate();

    }
}
