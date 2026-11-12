<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationService;
use App\Services\PanelLink\PanelPackageImporter;
use App\Services\PanelLink\PanelPackageStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Receiving side of a push: a linked panel uploaded a package here
 * (/api/v1/migration/uploads) and asked for it to be imported. The other
 * panel polls the upload until it is completed or failed.
 */
class ImportPanelPackageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $uploadId)
    {
        $this->onQueue('heavy');
    }

    public function handle(PanelPackageStore $store, PanelPackageImporter $importer, NotificationService $notifications): void
    {
        $meta = $store->find($this->uploadId, 'upload');
        $actor = $meta ? User::query()->find((int) $meta['created_by']) : null;
        if ($meta === null || ! $actor instanceof User) {
            $meta && $store->update($this->uploadId, ['status' => 'failed', 'message' => 'The token owner no longer exists.']);

            return;
        }
        $store->update($this->uploadId, ['status' => 'importing', 'stage' => 'importing']);
        $label = (string) ($meta['site']['domain'] ?? 'Panel backup');

        try {
            $result = $importer->import($actor, (string) $meta['kind'], (string) $meta['path'], (array) ($meta['site'] ?? []), (array) ($meta['options'] ?? []), function (string $stage): void {
                app(PanelPackageStore::class)->update($this->uploadId, ['stage' => $stage]);
            });
        } catch (Throwable $e) {
            $store->update($this->uploadId, ['status' => 'failed', 'message' => $e->getMessage()]);
            $notifications->notifyUser(actor: $actor, type: 'clone_website', status: 'blocked', title: "Migration from another dPanel failed: {$label}", message: $e->getMessage());
            $this->cleanup($store);

            return;
        }

        $store->update($this->uploadId, ['status' => 'completed', 'stage' => 'completed', 'result' => $result, 'message' => $result['message']]);
        $notifications->notifyUser(actor: $actor, type: 'clone_website', status: 'completed', title: "Migrated from another dPanel: {$label}", message: $result['message']);
        $this->cleanup($store);
    }

    public function failed(?Throwable $exception): void
    {
        app(PanelPackageStore::class)->update($this->uploadId, ['status' => 'failed', 'message' => 'Import stopped: '.($exception?->getMessage() ?: 'worker timeout or crash')]);
    }

    /** The package itself goes; meta.json stays so the other panel can read the result. */
    private function cleanup(PanelPackageStore $store): void
    {
        $meta = $store->find($this->uploadId, 'upload');
        if (! empty($meta['path']) && is_file((string) $meta['path'])) {
            @unlink((string) $meta['path']);
        }
    }
}
