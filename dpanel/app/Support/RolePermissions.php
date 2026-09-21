<?php

namespace App\Support;

use App\Models\Role;

/**
 * PERMISSIONS is the fixed, hardcoded pool of permission names the app
 * understands — this list can't be extended from the admin UI. ROLES lists
 * the 3 built-in system roles and their default permission set, used only
 * to self-heal (recreate) those roles if their row goes missing.
 *
 * Which permissions are actually attached to a role (system or
 * admin-created) lives in roles.permissions_csv and is editable via the
 * Manage Roles offcanvas — see RoleManagementController.
 */
class RolePermissions
{
    public const ROLES = ['admin', 'reseller', 'general'];

    public const PERMISSIONS = [
        'view_dashboard',
        'manage_websites',
        'manage_dns',
        'manage_email',
        'manage_databases',
        'manage_backups',
        'manage_cron_jobs',
        'manage_redis',
        'manage_ssl',
        'manage_git',
        'use_file_manager',
        'manage_chat_engine',
        'manage_migrations',
        'manage_php',
        'view_monitoring',
        'manage_subscriptions',
        'manage_packages',
        'manage_users',
        'manage_security',
        'manage_servers',
        'manage_apache',
        'use_terminal',
    ];

    private const GENERAL_PERMISSIONS = [
        'view_dashboard',
        'manage_websites',
        'manage_dns',
        'manage_email',
        'manage_databases',
        'manage_backups',
        'manage_cron_jobs',
        'manage_redis',
        'manage_ssl',
        'manage_git',
        'use_file_manager',
        'manage_chat_engine',
    ];

    private const RESELLER_PERMISSIONS = [
        ...self::GENERAL_PERMISSIONS,
        'manage_migrations',
        'manage_php',
        'view_monitoring',
        'manage_subscriptions',
        'manage_packages',
        'manage_users',
    ];

    /**
     * Fixed, hardcoded grouping of PERMISSIONS for display purposes only
     * (e.g. the Manage Roles offcanvas checklist). Every name in
     * PERMISSIONS must appear in exactly one group here.
     *
     * @var array<string, array<int, string>>
     */
    public const GROUPS = [
        'General' => [
            'view_dashboard',
        ],
        'Website & Hosting' => [
            'manage_websites',
            'manage_dns',
            'manage_email',
            'manage_databases',
            'manage_backups',
            'manage_cron_jobs',
            'manage_redis',
            'manage_ssl',
            'manage_git',
            'use_file_manager',
        ],
        'AI Chat Engine' => [
            'manage_chat_engine',
        ],
        'Tools & Monitoring' => [
            'manage_migrations',
            'manage_php',
            'view_monitoring',
            'use_terminal',
        ],
        'Account & Billing' => [
            'manage_subscriptions',
            'manage_packages',
            'manage_users',
        ],
        'System Administration' => [
            'manage_security',
            'manage_servers',
            'manage_apache',
        ],
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public static function map(): array
    {
        return [
            'admin' => self::PERMISSIONS,
            'reseller' => self::RESELLER_PERMISSIONS,
            'general' => self::GENERAL_PERMISSIONS,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function forRole(string $role): array
    {
        return self::map()[$role] ?? [];
    }

    /**
     * Fetch a system role, recreating it (and its permissions_csv) from the
     * hardcoded map if the row is missing or was never synced.
     */
    public static function ensureRole(string $roleName): ?Role
    {
        if (! in_array($roleName, self::ROLES, true)) {
            return null;
        }

        $role = Role::firstOrCreate(['name' => $roleName]);

        if (! $role->permissions_csv) {
            $role->permissions_csv = implode(',', self::forRole($roleName));
            $role->save();
        }

        return $role;
    }
}
