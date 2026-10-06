<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Website\PythonProcessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Brings a Python site's process up (or re-applies its settings) in the
 * background, so the venv/pip install does not block the panel request and
 * the first visitor does not have to wait for it either.
 */
class StartPythonProcessJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $websiteId) {}

    public function handle(PythonProcessService $processes): void
    {
        $website = Website::query()->find($this->websiteId);
        if ($website === null || ! $website->isPythonRuntime() || $website->python_process_status === 'stopped') {
            return;
        }

        try {
            // Restart re-provisions the unit first, so it both starts a site
            // that never ran and applies changed settings to a running one.
            $processes->control($website, 'restart');
        } catch (\Throwable $e) {
            $website->forceFill(['python_process_status' => 'error'])->saveQuietly();
            Log::warning('Python process start failed', [
                'website_id' => $website->id,
                'domain' => $website->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
