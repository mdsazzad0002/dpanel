<?php

namespace App\Services\Mcp\Tools;

use App\Models\CronJob;
use App\Models\Mailbox;
use App\Models\McpAccessToken;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;

class PanelOverviewTool implements McpTool
{
    /** Units the panel host depends on. */
    private const SERVICES = ['edge-gateway', 'drust', 'php8.3-fpm', 'php8.2-fpm', 'mariadb', 'mysql', 'redis-server', 'postfix', 'dovecot', 'pdns'];

    public function name(): string
    {
        return 'get_panel_overview';
    }

    public function title(): string
    {
        return 'Panel overview';
    }

    public function description(): string
    {
        return 'Health snapshot of the dPanel host: hostname, OS, uptime, load, memory, disk, each core service\'s state and memory use (edge gateway, drust, PHP-FPM, database, Redis, mail, DNS), failed systemd units, the processes using the most memory and CPU, resource counts, and an alerts list of everything that looks wrong. Start here when diagnosing a problem.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $diskTotal = @disk_total_space('/');
        $diskFree = @disk_free_space('/');

        $overview = [
            'hostname' => gethostname() ?: null,
            'os' => $this->osName(),
            'uptime' => $this->uptime(),
            'load_average' => $load ? array_map(fn ($v) => round($v, 2), $load) : null,
            'cpu_cores' => $this->cpuCores(),
            'memory' => $this->memory(),
            'disk_root' => $diskTotal ? [
                'total_gb' => round($diskTotal / 1073741824, 1),
                'free_gb' => round((float) $diskFree / 1073741824, 1),
                'used_percent' => round(100 - ((float) $diskFree / $diskTotal * 100), 1),
            ] : null,
            'services' => $this->serviceStates(),
            'failed_units' => $this->failedUnits(),
            'top_memory_processes' => $this->topProcesses('rss'),
            'top_cpu_processes' => $this->topProcesses('pcpu'),
            'counts' => [
                'servers' => Server::query()->count(),
                'websites' => Website::query()->count(),
                'mailboxes' => $this->safeCount(fn () => Mailbox::query()->count()),
                'cron_jobs' => $this->safeCount(fn () => CronJob::query()->count()),
                'ssl_expiring_14d' => $this->safeCount(fn () => SslCertificate::query()
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now()->addDays(14))
                    ->count()),
            ],
            'checked_at' => now()->toIso8601String(),
        ];

        return [...$overview, 'alerts' => $this->alerts($overview)];
    }

    /**
     * What a person would flag at a glance, so the model starts from there.
     *
     * @return array<int, string>
     */
    private function alerts(array $o): array
    {
        $alerts = [];

        if (($o['memory']['used_percent'] ?? 0) >= 90) {
            $alerts[] = "Memory is {$o['memory']['used_percent']}% used ({$o['memory']['available_mb']} MB available).";
        }
        if (($o['disk_root']['used_percent'] ?? 0) >= 90) {
            $alerts[] = "Disk / is {$o['disk_root']['used_percent']}% full ({$o['disk_root']['free_gb']} GB free); analyze_disk_usage shows what fills it.";
        }
        if ($o['load_average'] && $o['cpu_cores'] && $o['load_average'][0] > $o['cpu_cores'] * 1.5) {
            $alerts[] = "Load {$o['load_average'][0]} is high for {$o['cpu_cores']} cores; see top_cpu_processes.";
        }
        foreach ($o['services'] as $unit => $service) {
            if (is_array($service) && $service['state'] !== 'active') {
                $alerts[] = "Service {$unit} is {$service['state']}.";
            }
        }
        foreach ($o['failed_units'] as $unit) {
            $alerts[] = "systemd unit {$unit} has failed.";
        }
        if (($o['counts']['ssl_expiring_14d'] ?? 0) > 0) {
            $alerts[] = "{$o['counts']['ssl_expiring_14d']} SSL certificate(s) expire within 14 days.";
        }

        return $alerts;
    }

    private function cpuCores(): ?int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');

        return is_string($cpuinfo) ? (preg_match_all('/^processor\s*:/m', $cpuinfo) ?: null) : null;
    }

    /**
     * @return array<int, string>
     */
    private function failedUnits(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('shell_exec')) {
            return [];
        }

        $output = (string) @shell_exec('systemctl list-units --state=failed --plain --no-legend --no-pager 2>/dev/null');

        return array_slice(array_values(array_filter(array_map(
            fn (string $line) => strtok(trim($line), ' ') ?: '',
            preg_split('/\R/', trim($output)) ?: [],
        ))), 0, 20);
    }

    /**
     * @return array<string, string|array{state: string, memory_mb: int|null}> unit => state and memory, or 'unknown'
     */
    private function serviceStates(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('shell_exec')) {
            return array_fill_keys(self::SERVICES, 'unknown');
        }

        $units = array_map(fn (string $unit) => escapeshellarg($unit.'.service'), self::SERVICES);
        $output = @shell_exec('systemctl show --property=Id,LoadState,ActiveState,MemoryCurrent '.implode(' ', $units).' 2>/dev/null');

        if (! is_string($output) || trim($output) === '') {
            return array_fill_keys(self::SERVICES, 'unknown');
        }

        $states = [];
        foreach (preg_split('/\R\R+/', trim($output)) ?: [] as $block) {
            preg_match('/^Id=(.+?)\.service$/m', $block, $id);
            preg_match('/^LoadState=(.+)$/m', $block, $load);
            preg_match('/^ActiveState=(.+)$/m', $block, $active);

            // Skip alternatives that aren't installed (mysql vs mariadb, other PHP versions).
            if (! isset($id[1]) || ($load[1] ?? '') === 'not-found') {
                continue;
            }

            preg_match('/^MemoryCurrent=(\d+)$/m', $block, $memory);
            $states[$id[1]] = [
                'state' => $active[1] ?? 'unknown',
                'memory_mb' => isset($memory[1]) ? intdiv((int) $memory[1], 1048576) : null,
            ];
        }

        return $states;
    }

    /**
     * @param  'rss'|'pcpu'  $sort
     * @return array<int, array{pid: int, user: string, command: string, memory_mb: int, memory_percent: float, cpu_percent: float}>
     */
    private function topProcesses(string $sort): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('shell_exec')) {
            return [];
        }

        $output = @shell_exec("ps -eo pid=,user=,rss=,pmem=,pcpu=,comm= --sort=-{$sort} 2>/dev/null | head -n ".($sort === 'rss' ? 10 : 6));

        $processes = [];
        foreach (preg_split('/\R/', trim((string) $output)) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S+)\s+(\d+)\s+([\d.]+)\s+([\d.]+)\s+(.+)$/', $line, $m) === 1) {
                $processes[] = [
                    'pid' => (int) $m[1],
                    'user' => $m[2],
                    'command' => trim($m[6]),
                    'memory_mb' => intdiv((int) $m[3], 1024),
                    'memory_percent' => (float) $m[4],
                    'cpu_percent' => (float) $m[5],
                ];
            }
        }

        return $processes;
    }

    private function osName(): ?string
    {
        $release = @file_get_contents('/etc/os-release');
        if (is_string($release) && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $release, $m) === 1) {
            return $m[1];
        }

        return php_uname('s').' '.php_uname('r');
    }

    private function uptime(): ?string
    {
        $raw = @file_get_contents('/proc/uptime');
        if (! is_string($raw)) {
            return null;
        }

        $seconds = (int) explode(' ', $raw)[0];

        return sprintf('%dd %dh %dm', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60));
    }

    private function memory(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if (! is_string($raw)) {
            return null;
        }

        preg_match('/MemTotal:\s+(\d+)/', $raw, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $raw, $available);
        if (! isset($total[1], $available[1])) {
            return null;
        }

        return [
            'total_mb' => intdiv((int) $total[1], 1024),
            'available_mb' => intdiv((int) $available[1], 1024),
            'used_percent' => round(100 - ((int) $available[1] / (int) $total[1] * 100), 1),
        ];
    }

    private function safeCount(callable $count): ?int
    {
        try {
            return (int) $count();
        } catch (\Throwable) {
            return null;
        }
    }
}
