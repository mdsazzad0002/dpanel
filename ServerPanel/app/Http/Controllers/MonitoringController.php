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
        return Inertia::render('Monitoring', [
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
                'apache' => $this->serviceStatus('apache2'),
                'nginx' => $this->serviceStatus('nginx'),
                'mariadb' => $this->serviceStatus('mariadb'),
                'mysql' => $this->serviceStatus('mysql'),
                'redis' => $this->serviceStatus('redis-server'),
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

    /**
     * @return array<string, int|float>
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
}
