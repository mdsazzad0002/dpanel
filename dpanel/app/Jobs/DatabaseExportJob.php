<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Services\Backup\QuickExportJobStatus;
use App\Services\Backup\QuickExportLinkFactory;
use App\Services\Database\DatabaseDumper;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Exports one database from the Databases page. A bulk export queues one of
 * these per database, so each file is ready, and downloads, on its own
 * instead of waiting for every dump to be zipped together.
 */
class DatabaseExportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $exportId,
        public string $databaseId,
        public int $userId,
    ) {
        // Same queue (and 3600s worker timeout) as website quick exports.
        $this->onQueue('heavy');
    }

    public function handle(DatabaseDumper $dumper, QuickExportLinkFactory $links, NotificationService $notifications): void
    {
        $this->status(['stage' => 'zipping']);

        $user = User::find($this->userId);
        $database = $user ? DatabaseRequest::query()->visibleTo($user)->find($this->databaseId) : null;
        if (! $user || ! $database) {
            $this->status(['stage' => 'failed', 'message' => 'Database not found.']);

            return;
        }

        try {
            $dump = $dumper->dump($database, storage_path('app/backups/quick-exports'));
        } catch (Throwable $e) {
            $this->status(['stage' => 'failed', 'message' => $e->getMessage()]);
            $notifications->notifyUser(
                actor: $user,
                type: 'database_export',
                status: 'blocked',
                title: "Database export failed: {$database->database_name}",
                message: $e->getMessage(),
                subject: $database,
            );

            return;
        }

        $size = (int) @filesize($dump['path']);
        $downloadUrl = $links->make('database:'.$database->id, $dump['path'], $dump['file_name']);
        $this->status(['stage' => 'ready', 'download_url' => $downloadUrl, 'file_name' => $dump['file_name'], 'size' => $size]);
        $notifications->notifyUser(
            actor: $user,
            type: 'database_export',
            status: 'completed',
            title: "Database export ready: {$database->database_name}",
            subject: $database,
            data: ['download_url' => $downloadUrl],
        );
    }

    /** @param  array<string, mixed>  $data */
    private function status(array $data): void
    {
        // The owner is kept with the status so only they can poll it.
        QuickExportJobStatus::set($this->exportId, ['user_id' => $this->userId, 'database_id' => $this->databaseId] + $data);
    }
}
