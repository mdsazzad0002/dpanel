<?php

namespace Database\Seeders;

use App\Support\RolePermissions;
use App\Support\UserAccessCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * The 3 system roles (admin/reseller/general) are self-healing: if a
     * row is missing it's recreated with the hardcoded default permission
     * set from App\Support\RolePermissions. Existing rows (system or
     * custom, admin-created via the Manage Roles offcanvas) are left
     * alone — their permissions_csv is now admin-editable, not
     * force-synced on every deploy.
     */
    public function run(): void
    {
        // Migrate legacy role name to the current one.
        DB::table('users')->where('role', 'general_user')->update(['role' => 'general']);

        foreach (RolePermissions::ROLES as $roleName) {
            RolePermissions::ensureRole($roleName);
        }

        UserAccessCache::invalidate();
    }
}
