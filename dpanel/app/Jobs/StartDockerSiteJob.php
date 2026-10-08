<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Website\DockerSiteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * (Re)creates a Docker site's container in the background, since pulling
 * its image can take minutes.
 */
class StartDockerSiteJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 960;

    public int $tries = 1;

    public function __construct(public string $websiteId) {}

    public function handle(DockerSiteService $sites): void
    {
        $website = Website::query()->find($this->websiteId);
        // A site that fronts a stack has no container of its own to start.
        if ($website === null || ! $website->isDockerRuntime() || $website->usesDockerPort() || $website->docker_process_status === 'stopped') {
            return;
        }

        try {
            $sites->control($website, 'recreate');
        } catch (\Throwable $e) {
            $website->forceFill(['docker_process_status' => 'error'])->saveQuietly();
            Log::warning('Docker site start failed', [
                'website_id' => $website->id,
                'domain' => $website->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
