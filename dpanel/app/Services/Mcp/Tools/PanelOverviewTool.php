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
        return 'Health snapshot of the dPanel host: hostname, OS, uptime, load, memory, disk, core service states (edge gateway, drust, PHP-FPM, database, Redis, mail, DNS) and resource counts. Start here when diagnosing a problem.';
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

        return [
            'hostname' => gethostname() ?: null,
            'os' => $this->osName(),
            'uptime' => $this->uptime(),
            'load_average' => $load ? array_map(fn ($v) => round($v, 2), $load) : null,
            'memory' => $this->memory(),
            'disk_root' => $diskTotal ? [
                'total_gb' => round($diskTotal / 1073741824, 1),
                'free_gb' => round((float) $diskFree / 1073741824, 1),
                'used_percent' => round(100 - ((float) $diskFree / $diskTotal * 100), 1),
            ] : null,
            'services' => $this->serviceStates(),
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
    }

    /**
     * @return array<string, string> unit => active|inactive|failed|unknown|not-installed
     */
    private function serviceStates(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('shell_exec')) {
            return array_fill_keys(self::SERVICES, 'unknown');
        }

        $units = array_map(fn (string $unit) => escapeshellarg($unit.'.service'), self::SERVICES);
        $output = @shell_exec('systemctl show --property=Id,LoadState,ActiveState '.implode(' ', $units).' 2>/dev/null');

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

            $states[$id[1]] = $active[1] ?? 'unknown';
        }

        return $states;
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
