<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\PanelSession;
use App\Models\User;
use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $stats = $this->buildStats($actor);

        return Inertia::render('Dashboard/Dashboard', [
            'dashboardStats' => $stats,
            'websiteRecords' => $this->buildWebsiteRecords($actor),
            'websiteScopeLabel' => $this->websiteScopeLabel($actor),
            'accountSummary' => $this->buildAccountSummary($actor),
            'canViewServerUsage' => (bool) $actor?->hasRole('admin'),
        ]);
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
        $domains = $websites->pluck('domain')
            ->merge(MailDomain::query()->whereIn('assigned_user_id', $userIds)->pluck('domain'))
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->filter()->unique()->values()->all();
        $mailboxes = Mailbox::query()->whereIn('domain', $domains);
        $session = PanelSession::query()->where('user_id', $actor->id)->latest('created_at')->first();
        $package = $actor->package;
        $bandwidth = $this->bandwidthUsage($domains);

        return [
            'scope' => $actor->hasRole('reseller') ? 'Reseller aggregate usage' : 'Your account usage',
            'package_name' => $package?->name,
            'package_owner' => $package?->owner_user_id === null ? 'Admin' : 'Reseller',
            'disk_used_mb' => $this->websiteDiskUsageMb($websites->pluck('root_path')->all()),
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

    /** @param array<int, mixed> $paths */
    private function websiteDiskUsageMb(array $paths): float
    {
        $bytes = 0;
        foreach (collect($paths)->map(fn ($path) => trim((string) $path))->filter()->unique() as $path) {
            if ($path === '' || ! is_dir($path)) {
                continue;
            }
            $output = @shell_exec('du -sb -- '.escapeshellarg($path).' 2>/dev/null');
            if (is_string($output) && preg_match('/^(\d+)/', trim($output), $matches) === 1) {
                $bytes += (int) $matches[1];
            }
        }

        return round($bytes / 1024 / 1024, 2);
    }

    /** @param array<int, string> $domains
     *  @return array{used_gb: float, requests: int, status: string}
     */
    private function bandwidthUsage(array $domains): array
    {
        $path = rtrim((string) config('serverpanel.bandwidth_directory'), '/')
            .'/'.now('UTC')->format('Y-m').'.json';
        if (! is_file($path)) {
            return ['used_gb' => 0.0, 'requests' => 0, 'status' => 'Waiting for traffic'];
        }
        $payload = @file_get_contents($path);
        $usage = is_string($payload) ? json_decode($payload, true) : null;
        if (! is_array($usage)) {
            return ['used_gb' => 0.0, 'requests' => 0, 'status' => 'Tracker data unavailable'];
        }

        $bytes = 0;
        $requests = 0;
        foreach ($domains as $domain) {
            $record = $usage[strtolower(trim($domain))] ?? null;
            if (! is_array($record)) {
                continue;
            }
            $bytes += (int) ($record['upload_bytes'] ?? 0) + (int) ($record['download_bytes'] ?? 0);
            $requests += (int) ($record['requests'] ?? 0);
        }

        return [
            'used_gb' => round($bytes / 1024 / 1024 / 1024, 6),
            'requests' => $requests,
            'status' => 'Live · current month',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(?User $actor): array
    {
        $websites = $this->safeCountWebsites($actor);
        $websitePending = $this->safeCountWebsitesPending($actor);
        $databaseRequests = $this->safeCountDatabaseRequests();
        $cronJobs = $this->safeCountActiveCronJobs();

        $mailboxes = $this->safeCountMailboxes();
        $mailQueue = $this->mailQueueCount();
        $canViewServerUsage = (bool) $actor?->hasRole('admin');
        $system = $canViewServerUsage ? $this->systemSnapshot() : null;

        return [
            'hostname' => $this->serverHostname(),
            'server_ip' => $this->serverIpAddress(),
            'os' => $this->serverOsName(),
            'uptime' => $this->serverUptime(),
            'cpu_cores' => $canViewServerUsage ? $this->cpuCoreCount() : null,
            'cpu_load_percent' => $system['cpu_load_percent'] ?? null,
            'memory_used_mb' => $system['memory_used_mb'] ?? null,
            'memory_total_mb' => $system['memory_total_mb'] ?? null,
            'disk_used_gb' => $system['disk_used_gb'] ?? null,
            'disk_total_gb' => $system['disk_total_gb'] ?? null,
            'websites_total' => $websites,
            'websites_pending' => $websitePending,
            'mailboxes_total' => $mailboxes,
            'mail_queue' => $mailQueue,
            'database_requests_total' => $databaseRequests,
            'cron_jobs_active' => $cronJobs,
            'services' => [
                'drust_gateway' => $this->serviceStatus('edge-gateway'),
                'drust_api' => $this->serviceStatus('drust'),
                'mail' => $this->serviceStatus('postfix'),
                'dovecot' => $this->serviceStatus('dovecot'),
                'database' => $this->databaseServiceStatus(),
                'redis' => $this->redisServiceStatus(),
            ],
        ];
    }

    private function safeCountMailboxes(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('mailboxes')) {
                return 0;
            }

            return Mailbox::count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeCountWebsites(?User $actor): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return 0;
            }

            return Website::query()
                ->whereRaw("LOWER(TRIM(domain)) <> 'dashboard'")
                ->visibleTo($actor)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeCountWebsitesPending(?User $actor): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return 0;
            }

            return Website::query()
                ->whereRaw("LOWER(TRIM(domain)) <> 'dashboard'")
                ->visibleTo($actor)
                ->where('status', 'pending')
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildWebsiteRecords(?User $actor): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return [];
            }

            return Website::query()
                ->with([
                    'assignedReseller:id,name,email',
                    'assignedUser:id,name,email',
                ])
                ->whereRaw("LOWER(TRIM(domain)) <> 'dashboard'")
                ->visibleTo($actor)
                ->latest('created_at')
                ->get()
                ->map(function (Website $website): array {
                    $assignedResellerName = $website->assignedReseller?->name;
                    $assignedUserName = $website->assignedUser?->name;

                    return [
                        'id' => (string) $website->id,
                        'domain' => strtolower(trim((string) ($website->domain ?? ''))),
                        'root_path' => str_replace('\\', '/', trim((string) ($website->root_path ?? ''))),
                        'php_version' => (string) ($website->php_version ?? ''),
                        'enable_ssl' => (bool) ($website->enable_ssl ?? false),
                        'status' => strtolower(trim((string) ($website->status ?? 'pending'))) ?: 'pending',
                        'assigned_reseller_name' => $assignedResellerName,
                        'assigned_user_name' => $assignedUserName,
                        'created_by_label' => $assignedResellerName ?? $assignedUserName ?? 'Admin',
                        'created_at' => $website->created_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function websiteScopeLabel(?User $actor): string
    {
        if ($actor?->hasRole('admin')) {
            return 'All websites';
        }

        if ($actor?->hasRole('reseller')) {
            return 'Your reseller websites';
        }

        if ($actor && ($actor->hasRole('general') || $actor->hasRole('general_user'))) {
            return 'Your assigned websites';
        }

        return 'Websites';
    }

    private function safeCountDatabaseRequests(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('database_requests')) {
                return 0;
            }

            return DatabaseRequest::query()->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeCountActiveCronJobs(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('cron_jobs')) {
                return 0;
            }

            return CronJob::query()->where('status', 'active')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function databaseServiceStatus(): string
    {
        $default = (string) config('database.default', 'unknown');

        if ($default === 'sqlite') {
            return 'sqlite';
        }

        if ($this->databaseServiceIsRunning(['mariadb', 'mysql', 'mysqld'])) {
            return 'mariadb';
        }

        try {
            DB::connection()->getPdo();
            if (str_contains(strtolower($default), 'mysql') || str_contains(strtolower($default), 'mariadb')) {
                return 'mariadb';
            }
        } catch (\Throwable $e) {
            // Fall through to configured driver label.
        }

        return $default;
    }

    /**
     * @param array<int, string> $services
     */
    private function databaseServiceIsRunning(array $services): bool
    {
        foreach ($services as $service) {
            if ($this->serviceStatus($service) === 'running') {
                return true;
            }
        }

        $process = @shell_exec('pgrep -x mariadbd >/dev/null 2>&1 || pgrep -x mysqld >/dev/null 2>&1; printf "%s" "$?"');
        if (is_string($process) && trim($process) === '0') {
            return true;
        }

        $socket = @shell_exec('test -S /run/mysqld/mysqld.sock || test -S /var/run/mysqld/mysqld.sock; printf "%s" "$?"');
        return is_string($socket) && trim($socket) === '0';
    }

    private function serviceStatus(string $service): string
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 'unknown';
        }

        $out = @shell_exec('systemctl is-active '.escapeshellarg($service).' 2>/dev/null');
        if (! is_string($out)) {
            return 'unknown';
        }

        return trim($out) === 'active' ? 'running' : 'down';
    }

    private function redisServiceStatus(): string
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 'unknown';
        }

        if ($this->serviceStatus('redis-server') === 'running' || $this->serviceStatus('redis') === 'running') {
            return 'running';
        }

        $ping = @shell_exec('redis-cli ping 2>/dev/null');
        if (is_string($ping) && strtoupper(trim($ping)) === 'PONG') {
            return 'running';
        }

        return 'down';
    }

    private function mailQueueCount(): int
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 0;
        }

        $out = @shell_exec("mailq 2>/dev/null | grep -E '^[A-F0-9]' | wc -l");
        if (! is_string($out)) {
            return 0;
        }

        return max(0, (int) trim($out));
    }

    private function serverHostname(): string
    {
        $hostname = gethostname();
        if (is_string($hostname) && trim($hostname) !== '') {
            return trim($hostname);
        }

        $out = @shell_exec('hostname 2>/dev/null');
        return is_string($out) && trim($out) !== '' ? trim($out) : 'unknown';
    }

    private function serverIpAddress(): string
    {
        $out = @shell_exec("hostname -I 2>/dev/null | awk '{print $1}'");
        if (is_string($out) && filter_var(trim($out), FILTER_VALIDATE_IP)) {
            return trim($out);
        }

        $out = @shell_exec("ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if ($i==\"src\") {print $(i+1); exit}}'");
        if (is_string($out) && filter_var(trim($out), FILTER_VALIDATE_IP)) {
            return trim($out);
        }

        return (string) ($this->requestServerValue('SERVER_ADDR') ?: 'unknown');
    }

    private function serverOsName(): string
    {
        $release = @file_get_contents('/etc/os-release');
        if (is_string($release) && preg_match('/^PRETTY_NAME=(.+)$/m', $release, $match) === 1) {
            return trim((string) $match[1], " \t\n\r\0\x0B\"'");
        }

        return PHP_OS_FAMILY.' '.php_uname('r');
    }

    private function serverUptime(): string
    {
        $uptime = @file_get_contents('/proc/uptime');
        if (is_string($uptime) && preg_match('/^([\d.]+)/', $uptime, $match) === 1) {
            return $this->formatDuration((int) floor((float) $match[1]));
        }

        $out = @shell_exec('uptime -p 2>/dev/null');
        return is_string($out) && trim($out) !== '' ? trim($out) : 'unknown';
    }

    private function cpuCoreCount(): int
    {
        $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        return $cores > 0 ? $cores : 1;
    }

    private function formatDuration(int $seconds): string
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
     * @return array{cpu_load_percent:float,memory_used_mb:int,memory_total_mb:int,disk_used_gb:float,disk_total_gb:float}
     */
    private function systemSnapshot(): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return [
                'cpu_load_percent' => 0.0,
                'memory_used_mb' => 0,
                'memory_total_mb' => 0,
                'disk_used_gb' => 0.0,
                'disk_total_gb' => 0.0,
            ];
        }

        $cpu = 0.0;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
            $cores = $cores > 0 ? $cores : 1;
            $cpu = round(min(100, max(0, ((float) ($load[0] ?? 0.0) / $cores) * 100)), 2);
        }

        $memoryUsed = 0;
        $memoryTotal = 0;
        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo) && $memInfo !== '') {
            preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', $memInfo, $total);
            preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $memInfo, $available);
            if (isset($total[1])) {
                $memoryTotal = (int) floor(((int) $total[1]) / 1024);
            }
            if (isset($total[1], $available[1])) {
                $memoryUsed = (int) floor((((int) $total[1]) - ((int) $available[1])) / 1024);
            }
        }

        $diskTotalGb = 0.0;
        $diskUsedGb = 0.0;
        $basePath = base_path();
        $diskTotal = @disk_total_space($basePath);
        $diskFree = @disk_free_space($basePath);
        if (is_numeric($diskTotal) && is_numeric($diskFree) && $diskTotal > 0) {
            $diskTotalGb = round(((float) $diskTotal) / 1024 / 1024 / 1024, 2);
            $diskUsedGb = round((((float) $diskTotal) - ((float) $diskFree)) / 1024 / 1024 / 1024, 2);
        }

        return [
            'cpu_load_percent' => $cpu,
            'memory_used_mb' => $memoryUsed,
            'memory_total_mb' => $memoryTotal,
            'disk_used_gb' => $diskUsedGb,
            'disk_total_gb' => $diskTotalGb,
        ];
    }
}
