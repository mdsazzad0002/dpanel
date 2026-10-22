<?php

namespace App\Http\Controllers;

use App\Models\AppProject;
use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
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
            ...$this->hostTelemetry(),
            'sampled_at' => microtime(true),
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

        // Static hardware facts; the page polls every second, so avoid spawning lscpu/dmidecode each time.
        $hardware = Cache::remember('monitoring.hardware_info', now()->addMinutes(10), fn (): array => [
            'cpu_frequency' => $this->cpuFrequencyInfo(),
            'memory_speed' => $this->memorySpeedInfo(),
            'bus_speed' => $this->dmiBusSpeedMhz(),
        ]);
        [$cpuMaxMhz, $cpuMinMhz] = $hardware['cpu_frequency'];
        [$memorySpeedMtS, $memoryConfiguredSpeedMtS] = $hardware['memory_speed'];
        $busSpeedMhz ??= $hardware['bus_speed'];

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
                'io' => $this->blockDeviceIo($blockDevice),
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

        $out = $this->dmidecode('memory');
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

    /**
     * x86 /proc/cpuinfo has no "bus MHz" line, so fall back to the DMI processor external clock.
     */
    private function dmiBusSpeedMhz(): ?float
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return null;
        }

        $out = $this->dmidecode('processor');
        if (! is_string($out) || preg_match('/^\s*External Clock\s*:\s*([\d.]+)\s*MHz/m', $out, $match) !== 1) {
            return null;
        }

        return round((float) $match[1], 2);
    }

    /**
     * DMI tables are root-only; requires a NOPASSWD sudoers rule for /usr/sbin/dmidecode.
     */
    private function dmidecode(string $type): ?string
    {
        $out = @shell_exec('sudo -n /usr/sbin/dmidecode --type '.escapeshellarg($type).' 2>/dev/null');

        return is_string($out) && trim($out) !== '' ? $out : null;
    }

    /**
     * Cumulative I/O counters for a block device; the client derives rates from successive samples.
     *
     * @return array{reads: int, writes: int, read_bytes: int, write_bytes: int}|null
     */
    private function blockDeviceIo(string $blockDevice): ?array
    {
        $stat = @file_get_contents($blockDevice.'/stat');
        $fields = is_string($stat) ? preg_split('/\s+/', trim($stat)) : false;
        if (! is_array($fields) || count($fields) < 7) {
            return null;
        }

        return [
            'reads' => (int) $fields[0],
            'writes' => (int) $fields[4],
            'read_bytes' => (int) $fields[2] * 512,
            'write_bytes' => (int) $fields[6] * 512,
        ];
    }

    /**
     * Uptime, load, swap, sensors, network and socket counters read straight from /proc and /sys.
     *
     * @return array<string, mixed>
     */
    private function hostTelemetry(): array
    {
        $osRelease = @parse_ini_file('/etc/os-release');
        $telemetry = [
            'hostname' => gethostname() ?: null,
            'os_name' => is_array($osRelease) ? ($osRelease['PRETTY_NAME'] ?? null) : PHP_OS_FAMILY,
            'kernel' => php_uname('r'),
            'cpu_cores' => null,
            'uptime_seconds' => null,
            'load_average' => null,
            'process_count' => null,
            'swap_total_mb' => 0,
            'swap_used_mb' => 0,
            'temperatures' => [],
            'network' => [],
            'loopback' => null,
            'connections' => null,
            'connection_breakdown' => [],
        ];

        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return $telemetry;
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        $cores = is_string($cpuInfo) ? preg_match_all('/^processor\s*:/m', $cpuInfo) : 0;
        $telemetry['cpu_cores'] = $cores > 0 ? $cores : null;

        $uptime = @file_get_contents('/proc/uptime');
        if (is_string($uptime) && is_numeric(strtok($uptime, ' '))) {
            $telemetry['uptime_seconds'] = (int) strtok($uptime, ' ');
        }

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (is_array($load)) {
            $telemetry['load_average'] = array_map(fn ($value): float => round((float) $value, 2), $load);
        }

        $telemetry['process_count'] = count(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: []);

        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo)
            && preg_match('/^SwapTotal:\s+(\d+)\s+kB$/m', $memInfo, $swapTotal) === 1
            && preg_match('/^SwapFree:\s+(\d+)\s+kB$/m', $memInfo, $swapFree) === 1) {
            $telemetry['swap_total_mb'] = (int) floor((int) $swapTotal[1] / 1024);
            $telemetry['swap_used_mb'] = (int) floor(((int) $swapTotal[1] - (int) $swapFree[1]) / 1024);
        }

        $telemetry['temperatures'] = $this->temperatureSensors();
        $interfaces = collect($this->networkInterfaces());
        $telemetry['network'] = $interfaces->where('name', '!=', 'lo')->values()->all();
        $telemetry['loopback'] = $interfaces->firstWhere('name', 'lo');
        $telemetry['connections'] = $this->connectionStats();
        $telemetry['connection_breakdown'] = $this->connectionBreakdown();

        return $telemetry;
    }

    /**
     * @return array<int, array{id: string, chip: string, label: string, celsius: float, critical: ?float}>
     */
    private function temperatureSensors(): array
    {
        $sensors = [];
        foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $hwmon) {
            $chip = trim((string) @file_get_contents($hwmon.'/name')) ?: basename($hwmon);
            foreach (glob($hwmon.'/temp*_input') ?: [] as $input) {
                $raw = @file_get_contents($input);
                if (! is_string($raw) || ! is_numeric(trim($raw))) {
                    continue;
                }

                // Unwired sensor channels report 0 or negative milli-degrees.
                $celsius = round((int) trim($raw) / 1000, 1);
                if ($celsius < 1 || $celsius > 150) {
                    continue;
                }

                $base = substr($input, 0, -strlen('_input'));
                $label = trim((string) @file_get_contents($base.'_label')) ?: basename($base);
                $critical = @file_get_contents($base.'_crit');
                $critical = is_string($critical) && is_numeric(trim($critical)) ? round((int) trim($critical) / 1000, 1) : null;

                $sensors[] = [
                    'id' => basename($hwmon).':'.basename($base),
                    'chip' => $chip,
                    'label' => $label,
                    'celsius' => $celsius,
                    'critical' => $critical !== null && $critical > 0 && $critical < 150 ? $critical : null,
                ];
            }
        }

        return $sensors;
    }

    /**
     * @return array<int, array{name: string, rx_bytes: int, tx_bytes: int}>
     */
    private function networkInterfaces(): array
    {
        $netDev = @file_get_contents('/proc/net/dev');
        if (! is_string($netDev)) {
            return [];
        }

        $interfaces = [];
        foreach (array_slice(preg_split('/\r\n|\r|\n/', trim($netDev)) ?: [], 2) as $line) {
            [$name, $counters] = array_pad(explode(':', $line, 2), 2, '');
            $name = trim($name);
            $fields = preg_split('/\s+/', trim($counters));
            if (preg_match('/^(veth|virbr|docker|br-|vnet|tap|tun)/', $name) === 1
                || ! is_array($fields)
                || count($fields) < 9) {
                continue;
            }

            $interfaces[] = [
                'name' => $name,
                'rx_bytes' => (int) $fields[0],
                'tx_bytes' => (int) $fields[8],
            ];
        }

        return $interfaces;
    }

    /**
     * @return array{tcp_established: ?int, tcp_time_wait: ?int, tcp_in_use: ?int, udp_in_use: ?int, sockets_used: ?int}
     */
    private function connectionStats(): array
    {
        $stats = [
            'tcp_established' => null,
            'tcp_time_wait' => null,
            'tcp_in_use' => null,
            'udp_in_use' => null,
            'sockets_used' => null,
        ];

        $snmp = @file_get_contents('/proc/net/snmp');
        if (is_string($snmp) && preg_match_all('/^Tcp:\s+(.+)$/m', $snmp, $tcpLines) === 2) {
            $tcp = array_combine(preg_split('/\s+/', $tcpLines[1][0]), preg_split('/\s+/', $tcpLines[1][1]));
            $stats['tcp_established'] = isset($tcp['CurrEstab']) ? (int) $tcp['CurrEstab'] : null;
        }

        $sockstat = @file_get_contents('/proc/net/sockstat');
        if (is_string($sockstat)) {
            if (preg_match('/^sockets: used (\d+)/m', $sockstat, $used) === 1) {
                $stats['sockets_used'] = (int) $used[1];
            }
            if (preg_match('/^TCP: inuse (\d+).*?\btw (\d+)/m', $sockstat, $tcpSock) === 1) {
                $stats['tcp_in_use'] = (int) $tcpSock[1];
                $stats['tcp_time_wait'] = (int) $tcpSock[2];
            }
            if (preg_match('/^UDP: inuse (\d+)/m', $sockstat, $udp) === 1) {
                $stats['udp_in_use'] = (int) $udp[1];
            }
        }

        return $stats;
    }

    /**
     * Established TCP sockets grouped by service port, so local app traffic (e.g. a Python app
     * talking to PostgreSQL on 127.0.0.1) is visible. Reads /proc/net/tcp{,6}, which needs no
     * root; process names are not available that way, so ports are labelled from known
     * services and the panel's own app projects instead.
     *
     * @return array<int, array{port: int, direction: string, label: string, runtime: ?string, listening: bool, local: int, lan: int, external: int, total: int}>
     */
    private function connectionBreakdown(): array
    {
        $sockets = [];
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $table) {
            $lines = @file($table, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_slice(is_array($lines) ? $lines : [], 1) as $line) {
                $fields = preg_split('/\s+/', trim($line));
                if (! is_array($fields) || count($fields) < 4) {
                    continue;
                }

                [$localIp, $localPort] = $this->decodeProcSocket($fields[1]);
                [$remoteIp, $remotePort] = $this->decodeProcSocket($fields[2]);
                $sockets[] = compact('localIp', 'localPort', 'remoteIp', 'remotePort') + ['state' => $fields[3]];
            }
        }

        $listeningPorts = [];
        $hostAddresses = ['0.0.0.0' => true, '::' => true];
        foreach ($sockets as $socket) {
            $hostAddresses[$socket['localIp']] = true;
            if ($socket['state'] === '0A') {
                $listeningPorts[$socket['localPort']] = true;
            }
        }

        $labels = $this->servicePortLabels();
        $groups = [];
        foreach ($sockets as $socket) {
            if ($socket['state'] !== '01') {
                continue;
            }

            $peerIsHost = isset($hostAddresses[$socket['remoteIp']]) || $this->isLoopbackIp($socket['remoteIp']);
            if (isset($listeningPorts[$socket['localPort']])) {
                $port = $socket['localPort'];
                $direction = 'inbound';
            } elseif ($peerIsHost && (isset($listeningPorts[$socket['remotePort']]) || $socket['localPort'] > $socket['remotePort'])) {
                // Client half of a same-host pair; the other half is counted instead.
                continue;
            } else {
                $port = $socket['remotePort'];
                $direction = 'outbound';
            }

            $scope = $peerIsHost ? 'local' : ($this->isPrivateIp($socket['remoteIp']) ? 'lan' : 'external');
            $key = $direction.':'.$port;
            $groups[$key] ??= [
                'port' => $port,
                'direction' => $direction,
                'label' => $labels[$port]['label'] ?? ($direction === 'inbound' ? 'Port '.$port : 'Remote :'.$port),
                'runtime' => $labels[$port]['runtime'] ?? null,
                'listening' => $direction === 'inbound',
                'local' => 0,
                'lan' => 0,
                'external' => 0,
                'total' => 0,
            ];
            $groups[$key][$scope]++;
            $groups[$key]['total']++;
        }

        // Always list the panel's own apps so an idle or stopped app is visible too.
        foreach ($labels as $port => $label) {
            if ($label['runtime'] === null || isset($groups['inbound:'.$port])) {
                continue;
            }
            $groups['inbound:'.$port] = [
                'port' => $port,
                'direction' => 'inbound',
                'label' => $label['label'],
                'runtime' => $label['runtime'],
                'listening' => isset($listeningPorts[$port]),
                'local' => 0,
                'lan' => 0,
                'external' => 0,
                'total' => 0,
            ];
        }

        return collect($groups)
            ->sortBy([
                fn (array $a, array $b): int => ($b['runtime'] !== null) <=> ($a['runtime'] !== null),
                fn (array $a, array $b): int => $b['total'] <=> $a['total'],
            ])
            ->take(25)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, runtime: ?string}>
     */
    private function servicePortLabels(): array
    {
        $labels = collect([
            21 => 'FTP', 22 => 'SSH', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3', 143 => 'IMAP',
            443 => 'HTTPS', 465 => 'SMTPS', 587 => 'Submission', 993 => 'IMAPS', 995 => 'POP3S',
            3306 => 'MariaDB/MySQL', 5432 => 'PostgreSQL', 6379 => 'Redis', 11211 => 'Memcached', 27017 => 'MongoDB',
        ])->map(fn (string $label): array => ['label' => $label, 'runtime' => null])->all();

        try {
            AppProject::query()
                ->whereNotNull('port')
                ->get(['name', 'runtime', 'port'])
                ->each(function (AppProject $app) use (&$labels): void {
                    $labels[(int) $app->port] = ['label' => $app->name, 'runtime' => $app->runtime];
                });
        } catch (\Throwable) {
            // Labels are cosmetic; fall back to well-known ports only.
        }

        return $labels;
    }

    /**
     * Decode an "ADDR:PORT" pair from /proc/net/tcp{,6}; addresses are little-endian 32-bit words.
     *
     * @return array{0: string, 1: int}
     */
    private function decodeProcSocket(string $value): array
    {
        [$hexIp, $hexPort] = array_pad(explode(':', $value, 2), 2, '0');
        $binary = implode('', array_map(
            fn (string $word): string => strrev((string) hex2bin($word)),
            str_split($hexIp, 8),
        ));
        $ip = @inet_ntop($binary) ?: '0.0.0.0';
        if (str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }

        return [$ip, (int) hexdec($hexPort)];
    }

    private function isLoopbackIp(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
