<?php

namespace App\Http\Controllers;

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

        return Inertia::render('Dashboard/Dashboard', [
            // Every field here comes from a `systemctl`/`du`/`redis-cli` shell-out,
            // which is what makes the dashboard feel slow — deferred so the page
            // renders immediately and these fill in via background requests.
            'dashboardStats' => Inertia::defer(fn () => $this->buildStats($actor), 'system'),
            'accountSummary' => Inertia::defer(fn () => $this->buildAccountSummary($actor), 'account'),
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
        $validPaths = collect($paths)
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '' && is_dir($path))
            ->unique()
            ->values();

        if ($validPaths->isEmpty()) {
            return 0.0;
        }

        // One `du` invocation covering every path instead of spawning a
        // subprocess per website — this is what was slowing dashboard loads
        // down for accounts with several sites.
        $command = 'du -sb -- '.$validPaths->map(fn ($path) => escapeshellarg($path))->implode(' ').' 2>/dev/null';
        $output = @shell_exec($command);

        $bytes = 0;
        if (is_string($output)) {
            foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
                if (preg_match('/^(\d+)/', trim($line), $matches) === 1) {
                    $bytes += (int) $matches[1];
                }
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
        $canViewServerUsage = (bool) $actor?->hasRole('admin');
        $cpuCores = $this->cpuCoreCount();
        $system = $canViewServerUsage ? $this->systemSnapshot($cpuCores) : null;

        $serviceStates = $this->batchServiceStatuses([
            'edge-gateway', 'drust', 'postfix', 'dovecot', 'mariadb', 'mysql', 'mysqld', 'redis-server', 'redis',
        ]);

        return [
            'hostname' => $this->serverHostname(),
            'server_ip' => $this->serverIpAddress(),
            'os' => $this->serverOsName(),
            'uptime' => $this->serverUptime(),
            'cpu_cores' => $canViewServerUsage ? $cpuCores : null,
            'cpu_load_percent' => $system['cpu_load_percent'] ?? null,
            'memory_used_mb' => $system['memory_used_mb'] ?? null,
            'memory_total_mb' => $system['memory_total_mb'] ?? null,
            'disk_used_gb' => $system['disk_used_gb'] ?? null,
            'disk_total_gb' => $system['disk_total_gb'] ?? null,
            'services' => [
                'drust_gateway' => $serviceStates['edge-gateway'],
                'drust_api' => $serviceStates['drust'],
                'mail' => $serviceStates['postfix'],
                'dovecot' => $serviceStates['dovecot'],
                'database' => $this->databaseServiceStatus($serviceStates),
                'redis' => $this->redisServiceStatus($serviceStates),
            ],
        ];
    }

    /**
     * Check several systemd units in a single `systemctl` invocation instead
     * of spawning one process per unit.
     *
     * @param  array<int, string>  $services
     * @return array<string, string> service name => 'running'|'down'|'unknown'
     */
    private function batchServiceStatuses(array $services): array
    {
        if ($services === [] || str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return array_fill_keys($services, 'unknown');
        }

        $command = 'systemctl is-active '.implode(' ', array_map('escapeshellarg', $services)).' 2>/dev/null';
        $output = @shell_exec($command);
        $lines = is_string($output) ? preg_split('/\R/', trim($output)) : [];

        $states = [];
        foreach ($services as $index => $service) {
            $states[$service] = trim((string) ($lines[$index] ?? '')) === 'active' ? 'running' : 'down';
        }

        return $states;
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

        if ($this->databaseServiceIsRunning($serviceStates, ['mariadb', 'mysql', 'mysqld'])) {
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
     * @param  array<string, string>  $serviceStates
     * @param  array<int, string>  $services
     */
    private function databaseServiceIsRunning(array $serviceStates, array $services): bool
    {
        foreach ($services as $service) {
            if (($serviceStates[$service] ?? null) === 'running') {
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

    /**
     * @param  array<string, string>  $serviceStates
     */
    private function redisServiceStatus(array $serviceStates): string
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 'unknown';
        }

        if (($serviceStates['redis-server'] ?? null) === 'running' || ($serviceStates['redis'] ?? null) === 'running') {
            return 'running';
        }

        $ping = @shell_exec('redis-cli ping 2>/dev/null');
        if (is_string($ping) && strtoupper(trim($ping)) === 'PONG') {
            return 'running';
        }

        return 'down';
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
    private function systemSnapshot(int $cpuCores): array
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
            $cpu = round(min(100, max(0, ((float) ($load[0] ?? 0.0) / $cpuCores) * 100)), 2);
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
