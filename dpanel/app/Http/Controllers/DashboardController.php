<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Mailbox;
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

        return Inertia::render('Dashboard', [
            'dashboardStats' => $stats,
            'websiteRecords' => $this->buildWebsiteRecords($actor),
            'websiteScopeLabel' => $this->websiteScopeLabel($actor),
        ]);
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
        $system = $this->systemSnapshot();

        return [
            'cpu_load_percent' => $system['cpu_load_percent'],
            'memory_used_mb' => $system['memory_used_mb'],
            'memory_total_mb' => $system['memory_total_mb'],
            'disk_used_gb' => $system['disk_used_gb'],
            'disk_total_gb' => $system['disk_total_gb'],
            'websites_total' => $websites,
            'websites_pending' => $websitePending,
            'mailboxes_total' => $mailboxes,
            'mail_queue' => $mailQueue,
            'database_requests_total' => $databaseRequests,
            'cron_jobs_active' => $cronJobs,
            'services' => [
                'apache' => $this->serviceStatus('apache2'),
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

        if ($this->serviceStatus('mariadb') === 'running') {
            return 'mariadb';
        }
        if ($this->serviceStatus('mysql') === 'running') {
            return 'mysql';
        }

        return $default;
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
