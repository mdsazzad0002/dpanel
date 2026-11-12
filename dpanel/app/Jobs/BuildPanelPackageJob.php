<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\PanelLink\PanelPackageBuilder;
use App\Services\PanelLink\PanelPackageStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Source side of a pull: a linked panel asked this one (POST
 * /api/v1/migration/exports) for a package. Builds it into the export's
 * folder; the other panel polls the export and then downloads it in chunks.
 */
class BuildPanelPackageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $exportId)
    {
        $this->onQueue('heavy');
    }

    public function handle(PanelPackageStore $store, PanelPackageBuilder $builder): void
    {
        $meta = $store->find($this->exportId, 'export');
        if ($meta === null) {
            return;
        }
        $actor = User::query()->find((int) $meta['created_by']);
        if (! $actor instanceof User) {
            $store->update($this->exportId, ['status' => 'failed', 'message' => 'The token owner no longer exists.']);

            return;
        }
        $website = ! empty($meta['website_id']) ? Website::query()->visibleTo($actor)->find($meta['website_id']) : null;
        $store->update($this->exportId, ['status' => 'building']);

        try {
            $path = $store->packagePath($this->exportId, PanelPackageBuilder::extension((string) $meta['kind']));
            $built = $builder->build($actor, (string) $meta['kind'], $website, (array) ($meta['paths'] ?? []), $path);
            $size = (int) filesize($built['path']);
            $store->update($this->exportId, [
                'status' => 'ready',
                'path' => $built['path'],
                'name' => $built['name'],
                'size' => $size,
                'sha256' => (string) hash_file('sha256', $built['path']),
                'chunk_size' => PanelPackageStore::CHUNK_SIZE,
                'total' => $store->chunkCount($size),
                'has_database' => $built['has_database'],
                'warning' => $built['warning'],
                'paths' => null,
            ]);
        } catch (Throwable $e) {
            $store->update($this->exportId, ['status' => 'failed', 'message' => $e->getMessage()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PanelPackageStore::class)->update($this->exportId, ['status' => 'failed', 'message' => 'Packaging stopped: '.($exception?->getMessage() ?: 'worker timeout or crash')]);
    }
}
