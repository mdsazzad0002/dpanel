<?php

namespace App\Jobs;

use App\Services\Docker\DrustDockerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Removes the container of a website that was deleted or moved off the
 * Docker runtime. Takes the name, not the website, which may be gone.
 */
class RemoveDockerSiteJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public string $containerName) {}

    public function handle(DrustDockerClient $drust): void
    {
        try {
            $status = $drust->status();
            $exists = collect((array) ($status['containers'] ?? []))
                ->contains(fn ($container): bool => ($container['name'] ?? '') === $this->containerName);
            if ($exists) {
                $drust->action('remove', ['id' => $this->containerName]);
            }
        } catch (\Throwable $e) {
            Log::warning('Docker site container removal failed', [
                'container' => $this->containerName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
