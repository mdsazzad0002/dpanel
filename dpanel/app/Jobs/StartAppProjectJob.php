<?php

namespace App\Jobs;

use App\Models\AppProject;
use App\Services\Projects\AppProjectProcessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Starts or restarts Python and Rust projects in the background. Python may
 * provision dependencies; Rust binaries are started without building.
 */
class StartAppProjectJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** Seconds drust may spend provisioning before the request gives up. */
    public const PROVISION_TIMEOUT = 1700;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $projectId, public string $action)
    {
        // The default worker kills jobs after 300s; 'heavy' allows 3600s.
        $this->onQueue('heavy');
    }

    public function handle(AppProjectProcessService $process): void
    {
        $project = AppProject::query()->find($this->projectId);
        // Stopped or deleted while queued: do not bring it back up.
        if ($project === null || $project->status !== 'starting') {
            return;
        }

        try {
            $process->control($project, $this->action, self::PROVISION_TIMEOUT);
            $project->forceFill(['last_error' => null])->saveQuietly();
        } catch (\Throwable $e) {
            $project->forceFill(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 4000)])->saveQuietly();
            Log::warning('App project start failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
