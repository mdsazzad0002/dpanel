<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('permission.table_names.permissions', 'permissions');

        $priorities = [
            1 => [
                'view_dashboard', 'manage_websites', 'manage_dns', 'manage_email',
                'manage_databases', 'manage_backups', 'manage_cron_jobs', 'manage_redis',
                'manage_ssl', 'manage_git', 'use_file_manager',
            ],
            2 => [
                'manage_migrations', 'manage_php', 'view_monitoring',
                'manage_subscriptions', 'manage_packages', 'manage_users',
            ],
            3 => [
                'manage_security', 'manage_servers', 'manage_apache', 'use_terminal',
            ],
        ];

        foreach ($priorities as $priority => $permissionNames) {
            DB::table($tableName)
                ->whereIn('name', $permissionNames)
                ->update(['priority' => $priority]);
        }
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions', 'permissions'))
            ->update(['priority' => 1]);
    }
};
