<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\PanelSession;
use App\Models\User;
use App\Models\Website;
use App\Services\Bandwidth\BandwidthUsageService;
use App\Models\AccountUsageSample;
use App\Services\Mail\SystemMailer;
use App\Services\Platform\SystemOverviewClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('Dashboard/Dashboard', [
            // The machine numbers come from drust (backlog D3); deferred so the
            // page renders at once and these fill in from background requests.
            'dashboardStats' => Inertia::defer(fn () => $this->buildStats($actor), 'system'),
            'accountSummary' => Inertia::defer(fn () => $this->buildAccountSummary($actor), 'account'),
            'canViewServerUsage' => (bool) $actor?->hasRole('admin'),
            // Admins only: without a system email, login codes and reset links
            // are never delivered. Null when it is set up, so nothing shows.
            'systemMailWarning' => $actor?->hasRole('admin') ? $this->systemMailWarning() : null,
        ]);
    }

    /** @return array{problem: string, source: string}|null */
    private function systemMailWarning(): ?array
    {
        $status = app(SystemMailer::class)->status();

        return $status['ready'] ? null : ['problem' => (string) $status['problem'], 'source' => $status['source']];
    }

    /** @return array<string, mixed> */
    private function buildAccountSummary(?User $actor): array
    {
        if ($actor === null) {
            return [];
        }

        $actor->loadMissing('package');
        $userIds = $actor->hasRole('reseller')
            ? User::query()->whereKey($actor->id)->orWhere('reseller_id', $actor->id)->pluck('id')->all()
            : [$actor->id];

        $websiteQuery = Website::query()->whereIn('assigned_user_id', $userIds)
            ->whereNotIn('type', ['alis', 'alias']);
        if ($actor->hasRole('reseller')) {
            $websiteQuery->orWhere(function ($query) use ($actor): void {
                $query->where('assigned_reseller_id', $actor->id)
                    ->whereNull('assigned_user_id')
                    ->whereNotIn('type', ['alis', 'alias']);
            });
        }

        $websites = $websiteQuery->get(['domain', 'root_path']);
        $websiteDomains = $websites->pluck('domain')
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->filter()->unique()->values()->all();
        $mailDomains = MailDomain::query()->whereIn('assigned_user_id', $userIds)->pluck('domain')
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->filter()->unique()->values()->all();
        $domains = collect($websiteDomains)->merge($mailDomains)->unique()->values()->all();
        $mailboxes = Mailbox::query()->whereIn('domain', $domains);
        $session = PanelSession::query()->where('user_id', $actor->id)->latest('created_at')->first();
        $package = $actor->package;
        $bandwidth = $this->bandwidthUsage($actor);

        return [
            'scope' => $actor->hasRole('reseller') ? 'Reseller aggregate usage' : 'Your account usage',
            'package_name' => $package?->name,
            'package_owner' => $package?->owner_user_id === null ? 'Admin' : 'Reseller',
            'disk_used_mb' => $this->measuredDiskMb($userIds),
            'disk_limit_mb' => $package?->max_storage_mb,
            'mailboxes_used' => (int) $mailboxes->count(),
            'mailboxes_limit' => $package?->max_mailboxes,
            'mail_storage_mb' => (int) $mailboxes->sum('quota_mb'),
            'websites_used' => $websites->count(),
            'websites_limit' => $package?->max_websites,
            'databases_used' => (int) DatabaseRequest::query()->whereIn('assigned_user_id', $userIds)->count(),
            'databases_limit' => $package?->max_databases,
            'bandwidth_used_gb' => $bandwidth['used_gb'],
            'bandwidth_limit_gb' => $package?->max_bandwidth_gb,
            'bandwidth_requests' => $bandwidth['requests'],
            'bandwidth_status' => $bandwidth['status'],
            'last_login_ip' => $session?->ip_address,
            'last_login_at' => $session?->created_at?->toIso8601String(),
        ];
    }

    /**
     * The newest hourly reading of each account (`accounts:disk-check`),
     * so the dashboard never walks the disk itself.
     *
     * @param  array<int, int>  $userIds
     */
    private function measuredDiskMb(array $userIds): float
    {
        $latest = AccountUsageSample::query()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('disk_mb')
            ->selectRaw('user_id, MAX(id) as id')
            ->groupBy('user_id')
            ->pluck('id');

        return (float) AccountUsageSample::query()->whereIn('id', $latest)->sum('disk_mb');
    }

    /**
     * Same report the Bandwidth page shows, so the two numbers always match.
     *
     * @return array{used_gb: float, requests: int, status: string}
     */
    private function bandwidthUsage(User $actor): array
    {
        $report = app(BandwidthUsageService::class)->report($actor, now('UTC')->format('Y-m'));
        if (! $report['available']) {
            return ['used_gb' => 0.0, 'requests' => 0, 'status' => 'Waiting for traffic'];
        }

        return [
            'used_gb' => round($report['totals']['total_bytes'] / 1024 / 1024 / 1024, 6),
            'requests' => $report['totals']['requests'],
            'status' => $actor->hasRole('admin') ? 'Live · all websites this month' : 'Live · current month',
        ];
    }

    private const SERVICES = ['edge-gateway', 'drust', 'postfix', 'dovecot', 'mariadb', 'mysql', 'mysqld', 'redis-server', 'redis'];

    /**
     * @return array<string, mixed>
     */
    private function buildStats(?User $actor): array
    {
        $canViewServerUsage = (bool) $actor?->hasRole('admin');
        $overview = app(SystemOverviewClient::class)->overview(self::SERVICES) ?? [];
        $states = array_merge(array_fill_keys(self::SERVICES, 'unknown'), (array) ($overview['services'] ?? []));
        $system = $canViewServerUsage ? self::systemSnapshot($overview) : null;

        return [
            'hostname' => (string) ($overview['hostname'] ?? (gethostname() ?: 'unknown')),
            'server_ip' => (string) ($overview['primary_ip'] ?? ($this->requestServerValue('SERVER_ADDR') ?: 'unknown')),
            'os' => (string) ($overview['os_name'] ?? PHP_OS_FAMILY),
            'uptime' => isset($overview['uptime_seconds']) ? self::formatDuration((int) $overview['uptime_seconds']) : 'unknown',
            'cpu_cores' => $canViewServerUsage ? (int) ($overview['cpu_cores'] ?? 1) : null,
            'cpu_load_percent' => $system['cpu_load_percent'] ?? null,
            'memory_used_mb' => $system['memory_used_mb'] ?? null,
            'memory_total_mb' => $system['memory_total_mb'] ?? null,
            'disk_used_gb' => $system['disk_used_gb'] ?? null,
            'disk_total_gb' => $system['disk_total_gb'] ?? null,
            'services' => [
                'drust_gateway' => $states['edge-gateway'],
                'drust_api' => $overview === [] ? 'down' : $states['drust'],
                'mail' => $states['postfix'],
                'dovecot' => $states['dovecot'],
                'database' => $this->databaseServiceStatus($states),
                'redis' => $this->redisServiceStatus($states),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $serviceStates
     */
    private function databaseServiceStatus(array $serviceStates): string
    {
        $default = (string) config('database.default', 'unknown');

        if ($default === 'sqlite') {
            return 'sqlite';
        }

        foreach (['mariadb', 'mysql', 'mysqld'] as $service) {
            if (($serviceStates[$service] ?? null) === 'running') {
                return 'mariadb';
            }
        }

        try {
            DB::connection()->getPdo();
            if (str_contains(strtolower($default), 'mysql') || str_contains(strtolower($default), 'mariadb')) {
                return 'mariadb';
            }
        } catch (\Throwable) {
            // Fall through to the configured driver's name.
        }

        return $default;
    }

    /**
     * @param  array<string, string>  $serviceStates
     */
    private function redisServiceStatus(array $serviceStates): string
    {
        if (($serviceStates['redis-server'] ?? null) === 'running' || ($serviceStates['redis'] ?? null) === 'running') {
            return 'running';
        }

        // No service manager (Windows, macOS): ask Redis itself.
        try {
            return Redis::connection()->ping() ? 'running' : 'down';
        } catch (\Throwable) {
            return in_array('unknown', [$serviceStates['redis-server'] ?? 'unknown', $serviceStates['redis'] ?? 'unknown'], true) ? 'unknown' : 'down';
        }
    }

    public static function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'d';
        }
        if ($hours > 0) {
            $parts[] = $hours.'h';
        }
        if ($minutes > 0 || $parts === []) {
            $parts[] = $minutes.'m';
        }

        return implode(' ', $parts);
    }

    private function requestServerValue(string $key): string
    {
        $value = $_SERVER[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return array{cpu_load_percent:float,memory_used_mb:int,memory_total_mb:int,disk_used_gb:float,disk_total_gb:float}
     */
    public static function systemSnapshot(array $overview): array
    {
        $cores = max(1, (int) ($overview['cpu_cores'] ?? 1));
        $load = (float) ($overview['load'][0] ?? 0.0);
        $memory = (array) ($overview['memory'] ?? []);
        $totalKb = (int) ($memory['total_kb'] ?? 0);
        $availableKb = (int) ($memory['available_kb'] ?? 0);
        $disk = (array) ($overview['disk'] ?? []);
        $diskTotal = (float) ($disk['total_bytes'] ?? 0);
        $diskFree = (float) ($disk['free_bytes'] ?? 0);
        $gb = 1024 * 1024 * 1024;

        return [
            'cpu_load_percent' => round(min(100, max(0, ($load / $cores) * 100)), 2),
            'memory_used_mb' => (int) floor(max(0, $totalKb - $availableKb) / 1024),
            'memory_total_mb' => (int) floor($totalKb / 1024),
            'disk_used_gb' => $diskTotal > 0 ? round(($diskTotal - $diskFree) / $gb, 2) : 0.0,
            'disk_total_gb' => $diskTotal > 0 ? round($diskTotal / $gb, 2) : 0.0,
        ];
    }
}
