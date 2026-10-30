<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ClonePackageRestorer;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Receiving end of a cross-server clone: downloads the package produced by
 * ShareWebsitePackageJob on the other server (via its shareable download URL)
 * and restores it into a website owned on *this* server via ClonePackageRestorer.
 */
class ImportSharedWebsiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $cloneId,
        public string $sourceUrl,
        public string $targetWebsiteId,
        public int $userId,
        public ?string $databaseTarget = null,
        public string $newDatabaseName = '',
    ) {
        $this->onQueue('heavy');
    }

    public function handle(ClonePackageRestorer $restorer, NotificationService $notifications): void
    {
        CloneShareJobStatus::set($this->cloneId, ['stage' => 'downloading']);

        $user = User::find($this->userId);
        $target = $user ? Website::query()->visibleTo($user)->find($this->targetWebsiteId) : null;
        if (! $user || ! $target) {
            CloneShareJobStatus::set($this->cloneId, ['stage' => 'failed', 'message' => 'Target website was not found.']);

            return;
        }

        $migrationsDir = storage_path('app/migrations/clone-share');
        File::ensureDirectoryExists($migrationsDir, 0750);
        $packagePath = $migrationsDir.'/'.Str::uuid().'-incoming.zip';

        try {
            $response = Http::timeout(3600)->sink($packagePath)->get($this->sourceUrl);
            if (! $response->successful() || ! is_file($packagePath) || filesize($packagePath) === 0) {
                throw new RuntimeException('The share link could not be downloaded — it may have expired.');
            }

            $restorer->restore($user, $target, $packagePath, null, function (string $stage): void {
                if ($stage === 'restoring') {
                    CloneShareJobStatus::set($this->cloneId, ['stage' => 'restoring']);
                }
            }, $this->databaseTarget, $this->newDatabaseName);
        } catch (Throwable $e) {
            CloneShareJobStatus::set($this->cloneId, ['stage' => 'failed', 'message' => $e->getMessage()]);
            $notifications->notifyUser(
                actor: $user,
                type: 'clone_website',
                status: 'blocked',
                title: "Clone import failed: {$target->domain}",
                message: $e->getMessage(),
                subject: $target,
            );

            return;
        } finally {
            File::delete($packagePath);
        }

        CloneShareJobStatus::set($this->cloneId, ['stage' => 'ready']);
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'completed',
            title: "Clone imported: {$target->domain}",
            subject: $target,
        );
    }
}
