<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ServerPanel\ServerInventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanServerInventoryJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $serverId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(ServerInventoryService $inventoryService): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }

        try {
            $inventoryService->scan($server);
        } catch (\Throwable $exception) {
            $server->forceFill([
                'status' => 'error',
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }
}
