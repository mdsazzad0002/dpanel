<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Monitoring/Monitoring', [
            'snapshot' => $this->buildSnapshot(),
        ]);
    }

    public function snapshot(): JsonResponse
    {
        return response()->json([
            'snapshot' => $this->buildSnapshot(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(): array
    {
        return [
            ...$this->systemSnapshot(),
            'websites_total' => $this->safeCountWebsites(),
            'mailboxes_total' => $this->mailboxesCount(),
            'database_requests_total' => $this->safeCountDatabaseRequests(),
            'cron_jobs_total' => $this->safeCountCronJobs(),
            'services' => [
                'edge' => $this->serviceStatus('edge-gateway'),
                'api_service' => $this->serviceStatus('drust'),
                'mariadb' => $this->serviceStatus('mariadb'),
                'mysql' => $this->serviceStatus('mysql'),
                'redis' => $this->redisServiceStatus(),
                'postfix' => $this->serviceStatus('postfix'),
                'dovecot' => $this->serviceStatus('dovecot'),
            ],
            'topProcesses' => $this->topProcesses(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    private function mailboxesCount(): int
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

    private function safeCountWebsites(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return 0;
            }

            return Website::count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeCountDatabaseRequests(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('database_requests')) {
                return 0;
            }

            return DatabaseRequest::count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeCountCronJobs(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('cron_jobs')) {
                return 0;
            }

            return CronJob::count();
        } catch (\Throwable $e) {
            return 0;
        }
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

    /**
     * @return array<string, mixed>
     */
    private function systemSnapshot(): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return [
                'cpu_load_percent' => 0.0,
                'cpu_model' => null,
                'cpu_clock_mhz' => null,
                'bus_speed_mhz' => null,
                'cpu_max_mhz' => null,
                'cpu_min_mhz' => null,
                'memory_used_mb' => 0,
                'memory_total_mb' => 0,
                'memory_speed_mt_s' => null,
                'memory_configured_speed_mt_s' => null,
                'disk_used_gb' => 0.0,
                'disk_total_gb' => 0.0,
                'disks' => [],
            ];
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        $cpuInfo = is_string($cpuInfo) ? $cpuInfo : '';

        $cpu = 0.0;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
            $cores = $cores > 0 ? $cores : 1;
            $cpu = round(min(100, max(0, ((float) ($load[0] ?? 0.0) / $cores) * 100)), 2);
        }

        $cpuClockMhz = null;
        $currentFrequency = @file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq');
        if (is_string($currentFrequency) && is_numeric(trim($currentFrequency))) {
            $cpuClockMhz = round(((float) trim($currentFrequency)) / 1000, 2);
        } elseif (preg_match('/^cpu MHz\s*:\s*([\d.]+)/m', $cpuInfo, $clockMatch) === 1) {
            $cpuClockMhz = round((float) $clockMatch[1], 2);
        }
        $cpuModel = preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $modelMatch) === 1
            ? trim($modelMatch[1])
            : null;
        $busSpeedMhz = preg_match('/^bus MHz\s*:\s*([\d.]+)/m', $cpuInfo, $busMatch) === 1
            ? round((float) $busMatch[1], 2)
            : null;

        [$cpuMaxMhz, $cpuMinMhz] = $this->cpuFrequencyInfo();
        [$memorySpeedMtS, $memoryConfiguredSpeedMtS] = $this->memorySpeedInfo();

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

        $disks = $this->physicalDisks();
        $basePath = base_path();
        $baseDisk = collect($disks)
            ->flatMap(fn (array $disk): array => $disk['mounts'])
            ->filter(fn (array $mount): bool => str_starts_with($basePath, rtrim($mount['mountpoint'], '/').'/')
                || $basePath === $mount['mountpoint'])
            ->sortByDesc(fn (array $mount): int => strlen($mount['mountpoint']))
            ->first();
        $diskTotalGb = (float) ($baseDisk['total_gb'] ?? 0.0);
        $diskUsedGb = (float) ($baseDisk['used_gb'] ?? 0.0);
        if ($diskTotalGb <= 0) {
            $diskTotal = @disk_total_space($basePath);
            $diskFree = @disk_free_space($basePath);
            if (is_numeric($diskTotal) && is_numeric($diskFree) && $diskTotal > 0) {
                $diskTotalGb = round(((float) $diskTotal) / 1024 / 1024 / 1024, 2);
                $diskUsedGb = round((((float) $diskTotal) - ((float) $diskFree)) / 1024 / 1024 / 1024, 2);
            }
        }

        return [
            'cpu_load_percent' => $cpu,
            'cpu_model' => $cpuModel,
            'cpu_clock_mhz' => $cpuClockMhz,
            'bus_speed_mhz' => $busSpeedMhz,
            'cpu_max_mhz' => $cpuMaxMhz,
            'cpu_min_mhz' => $cpuMinMhz,
            'memory_used_mb' => $memoryUsed,
            'memory_total_mb' => $memoryTotal,
            'memory_speed_mt_s' => $memorySpeedMtS,
            'memory_configured_speed_mt_s' => $memoryConfiguredSpeedMtS,
            'disk_used_gb' => $diskUsedGb,
            'disk_total_gb' => $diskTotalGb,
            'disks' => $disks,
        ];
    }

    /**
     * @return array<int, array{id: string, device: string, model: string, media_type: string, total_gb: float, mounted_total_gb: float, mounted_used_gb: float, mounted_free_gb: float, usage_percent: ?float, mounts: array<int, array{mountpoint: string, filesystem: string, total_gb: float, used_gb: float, free_gb: float, usage_percent: float}>}>
     */
    private function physicalDisks(): array
    {
        $blockDevices = glob('/sys/block/*') ?: [];
        $mountTable = @file_get_contents('/proc/mounts');
        if ($blockDevices === [] || ! is_string($mountTable)) {
            return [];
        }

        $mounts = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($mountTable)) ?: [] as $line) {
            $parts = preg_split('/\s+/', $line);
            if (! is_array($parts) || count($parts) < 3 || ! str_starts_with($parts[0], '/dev/')) {
                continue;
            }

            $sourceName = basename($parts[0]);
            $sourcePath = realpath('/sys/class/block/'.$sourceName);
            if ($sourcePath === false) {
                continue;
            }

            $mounts[] = [
                'source_path' => $sourcePath,
                'source_name' => $sourceName,
                'mountpoint' => strtr($parts[1], [
                    '\\040' => ' ',
                    '\\011' => "\t",
                    '\\012' => "\n",
                    '\\134' => '\\',
                ]),
                'filesystem' => $parts[2],
            ];
        }

        $disks = [];
        foreach ($blockDevices as $blockDevice) {
            $deviceName = basename($blockDevice);
            $devicePath = realpath($blockDevice);
            if ($devicePath === false
                || str_starts_with($deviceName, 'loop')
                || str_starts_with($deviceName, 'ram')
                || str_starts_with($deviceName, 'zram')
                || str_starts_with($deviceName, 'dm-')
                || str_starts_with($deviceName, 'md')
                || str_starts_with($deviceName, 'sr')
                || str_starts_with($deviceName, 'fd')
                || str_contains($devicePath, '/devices/virtual/')) {
                continue;
            }

            $sectors = @file_get_contents($blockDevice.'/size');
            if (! is_string($sectors) || ! is_numeric(trim($sectors)) || (float) trim($sectors) <= 0) {
                continue;
            }

            $totalGb = ((float) trim($sectors) * 512) / 1024 / 1024 / 1024;
            $model = @file_get_contents($blockDevice.'/device/model');
            $model = is_string($model) && trim($model) !== '' ? trim($model) : $deviceName;
            $rotational = @file_get_contents($blockDevice.'/queue/rotational');
            $diskMounts = [];
            $seenSources = [];
            foreach ($mounts as $mount) {
                $mountDevice = $mount['source_path'] === $devicePath
                    || dirname($mount['source_path']) === $devicePath;
                if (! $mountDevice || isset($seenSources[$mount['source_name']])) {
                    continue;
                }

                $seenSources[$mount['source_name']] = true;
                $total = @disk_total_space($mount['mountpoint']);
                $free = @disk_free_space($mount['mountpoint']);
                if (! is_numeric($total) || ! is_numeric($free) || $total <= 0) {
                    continue;
                }

                $mountTotalGb = (float) $total / 1024 / 1024 / 1024;
                $mountFreeGb = (float) $free / 1024 / 1024 / 1024;
                $diskMounts[] = [
                    'mountpoint' => $mount['mountpoint'],
                    'filesystem' => $mount['filesystem'],
                    'total_gb' => round($mountTotalGb, 2),
                    'used_gb' => round(max(0, $mountTotalGb - $mountFreeGb), 2),
                    'free_gb' => round($mountFreeGb, 2),
                    'usage_percent' => round(min(100, max(0, (($total - $free) / $total) * 100)), 1),
                ];
            }

            $mountedTotalGb = array_sum(array_column($diskMounts, 'total_gb'));
            $mountedUsedGb = array_sum(array_column($diskMounts, 'used_gb'));
            $mountedFreeGb = array_sum(array_column($diskMounts, 'free_gb'));
            $disks[] = [
                'id' => $deviceName,
                'device' => '/dev/'.$deviceName,
                'model' => $model,
                'media_type' => ! is_string($rotational) ? 'DISK' : (trim($rotational) === '1' ? 'HDD' : 'SSD'),
                'total_gb' => round($totalGb, 2),
                'mounted_total_gb' => round($mountedTotalGb, 2),
                'mounted_used_gb' => round($mountedUsedGb, 2),
                'mounted_free_gb' => round($mountedFreeGb, 2),
                'usage_percent' => $mountedTotalGb > 0 ? round(min(100, max(0, ($mountedUsedGb / $mountedTotalGb) * 100)), 1) : null,
                'mounts' => $diskMounts,
            ];
        }

        return $disks;
    }

    /**
     * @return array<int, array<string, string|float|int>>
     */
    private function topProcesses(): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return [];
        }

        $out = @shell_exec("ps -eo pid,comm,%cpu,%mem --sort=-%cpu | head -n 6 2>/dev/null");
        if (! is_string($out) || trim($out) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($out)) ?: [];
        array_shift($lines);

        return collect($lines)->map(function (string $line): ?array {
            $parts = preg_split('/\s+/', trim($line));
            if (! is_array($parts) || count($parts) < 4) {
                return null;
            }

            return [
                'pid' => (int) ($parts[0] ?? 0),
                'name' => (string) ($parts[1] ?? ''),
                'cpu' => (float) ($parts[2] ?? 0),
                'mem' => (float) ($parts[3] ?? 0),
            ];
        })->filter()->values()->all();
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function cpuFrequencyInfo(): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return [null, null];
        }

        $out = @shell_exec('lscpu 2>/dev/null');
        if (! is_string($out) || trim($out) === '') {
            return [null, null];
        }

        $maxMhz = null;
        $minMhz = null;

        if (preg_match('/^CPU max MHz\s*:\s*([\d.]+)/m', $out, $maxMatch) === 1) {
            $maxMhz = round((float) $maxMatch[1], 2);
        }

        if (preg_match('/^CPU min MHz\s*:\s*([\d.]+)/m', $out, $minMatch) === 1) {
            $minMhz = round((float) $minMatch[1], 2);
        }

        return [$maxMhz, $minMhz];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function memorySpeedInfo(): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return [null, null];
        }

        $out = @shell_exec('sudo dmidecode --type memory 2>/dev/null');
        if (! is_string($out) || trim($out) === '') {
            return [null, null];
        }

        $speedMtS = null;
        $configuredSpeedMtS = null;

        if (preg_match_all('/^\s*Speed\s*:\s*(\d+)\s*MT\/s/m', $out, $speedMatches) > 0) {
            $speedMtS = (int) $speedMatches[1][0];
        }

        if (preg_match_all('/^\s*Configured Memory Speed\s*:\s*(\d+)\s*MT\/s/m', $out, $configuredSpeedMatches) > 0) {
            $configuredSpeedMtS = (int) $configuredSpeedMatches[1][0];
        }

        return [$speedMtS, $configuredSpeedMtS];
    }
}
