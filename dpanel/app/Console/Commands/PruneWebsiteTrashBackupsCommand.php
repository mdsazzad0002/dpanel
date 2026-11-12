<?php

namespace App\Console\Commands;

use App\Models\WebsiteTrashBackup;
use Illuminate\Console\Command;
use App\Services\Website\TrashBackupDeleter;

class PruneWebsiteTrashBackupsCommand extends Command
{
    protected $signature = 'serverpanel:trash-backups-prune {--days=15}';

    protected $description = 'Remove expired website trash archives through drust';

    public function handle(TrashBackupDeleter $deleter): int
    {
        $days = max(1, (int) $this->option('days'));
        $failed = false;
        $removed = 0;

        WebsiteTrashBackup::query()
            ->where('created_at', '<=', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(50, function ($backups) use (&$failed, &$removed, $deleter): void {
                foreach ($backups as $backup) {
                    try {
                        $deleter->delete($backup);
                        $removed++;
                    } catch (\Throwable $e) {
                        $failed = true;
                        $this->error("Trash backup {$backup->id} failed: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Removed {$removed} expired website trash backup(s). Retention: {$days} days.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
