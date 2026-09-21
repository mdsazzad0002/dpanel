<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Console\Command;

class PruneFilemanagerTrashCommand extends Command
{
    protected $signature = 'serverpanel:filemanager-trash-prune {--days=15}';

    protected $description = 'Remove expired File Manager trash batches (cPanel-style .trash folder, no database)';

    public function __construct(private readonly FilemanagerService $filemanagerService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = time() - ($days * 86400);
        $removed = 0;
        $failed = false;

        Website::query()->whereNotNull('site_owner')->where('site_owner', '!=', '')->chunkById(50, function ($websites) use ($cutoff, &$removed, &$failed): void {
            foreach ($websites as $website) {
                $trashRoot = '/home/'.$website->site_owner.'/.trash';
                if (! is_dir($trashRoot)) {
                    continue;
                }
                foreach ($this->expiredBatches($trashRoot, $cutoff) as $batchDirectory) {
                    try {
                        $this->filemanagerService->deletePath((string) $website->site_owner, $batchDirectory);
                        $removed++;
                    } catch (\Throwable $e) {
                        $failed = true;
                        $this->error("Failed to prune {$batchDirectory}: {$e->getMessage()}");
                    }
                }
            }
        });

        $this->info("Removed {$removed} expired filemanager trash batch(es). Retention: {$days} days.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, string> */
    private function expiredBatches(string $trashRoot, int $cutoff): array
    {
        $entries = @scandir($trashRoot);
        if (! is_array($entries)) {
            return [];
        }

        $expired = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $batchDirectory = rtrim($trashRoot, '/').'/'.$entry;
            if (! is_dir($batchDirectory)) {
                continue;
            }
            if ((int) (@filemtime($batchDirectory) ?: 0) <= $cutoff) {
                $expired[] = $batchDirectory;
            }
        }

        return $expired;
    }
}
