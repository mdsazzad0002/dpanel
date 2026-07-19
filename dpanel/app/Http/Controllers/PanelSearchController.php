<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class PanelSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'items' => $this->buildItems($request),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildItems(Request $request): array
    {
        $actor = $request->user();
        $token = (string) ($request->hasSession() ? $request->session()->get('panel_session_token', '') : '');

        $items = array_merge(
            $this->buildNavigationItems($actor, $token),
            $this->buildWebsiteItems($actor, $token),
        );

        return array_values($items);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildNavigationItems(?User $actor, string $token): array
    {
        $items = [];

        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Dashboard',
            'hint' => 'Overview and stats',
            'group' => 'General',
            'routeName' => 'dashboard',
            'iconClass' => 'bi bi-speedometer2',
            'keywords' => ['home', 'overview', 'stats'],
        ]);

        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Create Website',
            'hint' => 'Add a new website',
            'group' => 'Websites',
            'routeName' => 'websites.create',
            'iconClass' => 'bi bi-plus-square',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'List Websites',
            'hint' => 'View all websites',
            'group' => 'Websites',
            'routeName' => 'websites.list',
            'iconClass' => 'bi bi-list-ul',
            'roles' => ['admin', 'reseller'],
        ]);

        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Create Email',
            'hint' => 'Add a mailbox',
            'group' => 'Email',
            'routeName' => 'emails.create',
            'iconClass' => 'bi bi-envelope-plus',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'List Emails',
            'hint' => 'View all mailboxes',
            'group' => 'Email',
            'routeName' => 'emails.list',
            'iconClass' => 'bi bi-envelope-open',
            'roles' => ['admin', 'reseller'],
        ]);

        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Create Database',
            'hint' => 'Create a new database',
            'group' => 'Databases',
            'routeName' => 'databases.create',
            'iconClass' => 'bi bi-database-add',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'List Databases',
            'hint' => 'View all databases',
            'group' => 'Databases',
            'routeName' => 'databases.list',
            'iconClass' => 'bi bi-table',
            'roles' => ['admin', 'reseller'],
        ]);

        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Nameservers',
            'hint' => 'Manage NS records',
            'group' => 'DNS',
            'routeName' => 'dns.nameservers',
            'iconClass' => 'bi bi-signpost-split',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'DNS Zones',
            'hint' => 'Manage DNS zones',
            'group' => 'DNS',
            'routeName' => 'dns.zones',
            'iconClass' => 'bi bi-bounding-box-circles',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'DNS Records',
            'hint' => 'A, CNAME, MX, TXT records',
            'group' => 'DNS',
            'routeName' => 'dns.records',
            'iconClass' => 'bi bi-journal-code',
            'roles' => ['admin', 'reseller'],
        ]);

        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'PHP Management',
            'hint' => 'Versions, extensions and config',
            'group' => 'Server Stack',
            'routeName' => 'php.manager',
            'iconClass' => 'bi bi-braces',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Apache + Nginx Setup',
            'hint' => 'Web server stack and vHost controls',
            'group' => 'Server Stack',
            'routeName' => 'apache.index',
            'iconClass' => 'bi bi-hdd-network',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Security',
            'hint' => 'Firewall, SSH and hardening',
            'group' => 'Server Stack',
            'routeName' => 'security.manager',
            'iconClass' => 'bi bi-shield-lock',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Backups',
            'hint' => 'Snapshots and restore',
            'group' => 'Server Stack',
            'routeName' => 'backups.index',
            'iconClass' => 'bi bi-cloud-arrow-down',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Monitoring',
            'hint' => 'CPU, RAM, disk, logs',
            'group' => 'Server Stack',
            'routeName' => 'monitoring.index',
            'iconClass' => 'bi bi-activity',
            'roles' => ['admin', 'reseller'],
        ]);

        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Admin Panel',
            'hint' => 'Switch to the admin panel',
            'group' => 'Panels',
            'routeName' => 'admin.panel',
            'routeParams' => ['role' => 'admin'],
            'iconClass' => 'bi bi-person-gear',
            'roles' => ['admin'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'Reseller Panel',
            'hint' => 'Switch to the reseller panel',
            'group' => 'Panels',
            'routeName' => 'reseller.panel',
            'routeParams' => ['role' => 'reseller'],
            'iconClass' => 'bi bi-person-workspace',
            'roles' => ['admin', 'reseller'],
        ]);
        $this->appendRouteItem($items, $actor, $token, [
            'label' => 'User Panel',
            'hint' => 'Switch to the user panel',
            'group' => 'Panels',
            'routeName' => 'user.panel',
            'routeParams' => ['role' => 'general'],
            'iconClass' => 'bi bi-person',
            'roles' => ['admin', 'reseller', 'general', 'general_user'],
        ]);

        if ($actor?->hasRole('admin')) {
            $this->appendRouteItem($items, $actor, $token, [
                'label' => 'Manage Users',
                'hint' => 'Admin and reseller user accounts',
                'group' => 'Users',
                'routeName' => 'users.manage',
                'iconClass' => 'bi bi-people',
                'roles' => ['admin', 'reseller'],
            ]);
            $this->appendRouteItem($items, $actor, $token, [
                'label' => 'Manage Roles',
                'hint' => 'Edit existing roles',
                'group' => 'Users',
                'routeName' => 'roles.manage',
                'iconClass' => 'bi bi-shield-check',
                'roles' => ['admin'],
            ]);
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildWebsiteItems(?User $actor, string $token): array
    {
        if ($actor === null || ! Route::has('websites.manage')) {
            return [];
        }

        try {
            if (! Website::query()->getConnection()->getSchemaBuilder()->hasTable('websites')) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        return Website::query()
            ->with([
                'assignedReseller:id,name,email',
                'assignedUser:id,name,email',
            ])
            ->visibleTo($actor)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(function (Website $website) use ($token): array {
                $domain = strtolower(trim((string) ($website->domain ?? '')));
                $rootPath = str_replace('\\', '/', trim((string) ($website->root_path ?? '')));
                $status = strtolower(trim((string) ($website->status ?? 'pending'))) ?: 'pending';
                $phpVersion = trim((string) ($website->php_version ?? ''));
                $assignedResellerName = trim((string) ($website->assignedReseller?->name ?? ''));
                $assignedUserName = trim((string) ($website->assignedUser?->name ?? ''));
                $href = route('websites.manage', [
                    'token' => $token,
                    'id' => (string) $website->id,
                ]);

                return [
                    'label' => $domain !== '' ? $domain : (string) $website->id,
                    'hint' => trim(implode(' • ', array_values(array_filter([
                        $status !== '' ? ucfirst($status) : null,
                        $phpVersion !== '' ? "PHP {$phpVersion}" : null,
                        $rootPath !== '' ? $rootPath : null,
                    ])))),
                    'group' => 'Websites',
                    'href' => $href,
                    'iconClass' => 'bi bi-globe2',
                    'keywords' => array_values(array_filter([
                        $domain,
                        $rootPath,
                        $status,
                        $phpVersion,
                        $assignedResellerName,
                        $assignedUserName,
                        'manage website',
                        'website panel',
                        'open website',
                        $website->enable_ssl ? 'ssl' : null,
                    ])),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $definition
     */
    private function appendRouteItem(array &$items, ?User $actor, string $token, array $definition): void
    {
        if (! $this->actorCanSee($actor, $definition)) {
            return;
        }

        $routeName = (string) ($definition['routeName'] ?? '');
        if ($routeName === '' || ! Route::has($routeName)) {
            return;
        }

        $routeParams = $definition['routeParams'] ?? [];
        $href = route($routeName, array_merge(['token' => $token], is_array($routeParams) ? $routeParams : []));

        $items[] = [
            'label' => (string) ($definition['label'] ?? ''),
            'hint' => (string) ($definition['hint'] ?? ''),
            'group' => (string) ($definition['group'] ?? ''),
            'href' => $href,
            'iconClass' => (string) ($definition['iconClass'] ?? 'bi bi-link-45deg'),
            'keywords' => array_values(array_filter(array_merge(
                (array) ($definition['keywords'] ?? []),
                [$routeName, $definition['group'] ?? '', $definition['label'] ?? '', $definition['hint'] ?? '']
            ))),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function actorCanSee(?User $actor, array $definition): bool
    {
        if ($actor === null) {
            return false;
        }

        $roles = (array) ($definition['roles'] ?? []);
        $permissions = (array) ($definition['permissions'] ?? []);

        if ($permissions !== []) {
            foreach ($permissions as $permission) {
                if ($actor->can((string) $permission)) {
                    return true;
                }
            }

            return false;
        }

        if ($roles === []) {
            return true;
        }

        foreach ($roles as $role) {
            if ($actor->hasRole((string) $role)) {
                return true;
            }
        }

        return false;
    }
}
