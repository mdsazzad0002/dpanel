<?php

namespace App\Jobs;

use App\Models\SecurityScan;
use App\Services\Security\SecurityScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSecurityScanJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $scanId)
    {
        // File and ClamAV scans can run for a long time; keep them off the
        // default queue so they never delay panel jobs.
        $this->onQueue('heavy');
    }

    public function handle(SecurityScanService $scans): void
    {
        $scan = SecurityScan::query()->find($this->scanId);
        if ($scan === null || $scan->isFinished()) {
            return;
        }

        $scans->execute($scan);
    }

    public function failed(?\Throwable $exception): void
    {
        SecurityScan::query()
            ->whereKey($this->scanId)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'completed_at' => now(), 'error' => mb_substr((string) $exception?->getMessage(), 0, 2000) ?: 'Scan job failed.']);
    }
}
