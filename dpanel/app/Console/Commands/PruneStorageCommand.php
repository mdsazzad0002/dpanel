<?php

namespace App\Console\Commands;

use App\Jobs\DeleteMigrationImportArtifactsJob;
use App\Models\CronJob;
use App\Models\MigrationImport;
use App\Models\Website;
use App\Services\Backup\QuickExportLinkFactory;
use App\Services\Cron\CronSystemService;
use App\Support\Platform\LocalCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Removes what storage/app keeps after it stopped being useful. Each of these
 * used to rely on one delayed queue job (or a cron line) alone, and a Redis
 * restart or a cron line pointing at php-fpm left them on disk for good:
 *
 *  - migration imports older than MIGRATION_HOURS (as DeleteMigrationImportArtifactsJob);
 *  - folders in storage/app/migrations no import knows about, and dRust's
 *    `.generic-*` extraction folders left by a failed restore;
 *  - Clone & Share packages and quick exports past their download window.
 *
 * Website Trash is not touched: its own retention cron does that. A cron line
 * that runs php-fpm instead of the PHP CLI is rewritten, so it runs again.
 */
class PruneStorageCommand extends Command
{
    protected $signature = 'storage:prune {--dry-run : List what would go, remove nothing}';

    protected $description = 'Remove expired migration, export and temporary files from storage/app';

    private const MIGRATION_HOURS = 8;

    /** Left-over folders get a day, so nothing still being written is touched. */
    private const ORPHAN_HOURS = 24;

    private int $bytes = 0;

    private int $items = 0;

    public function handle(CronSystemService $cron): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->expireImports($dry);
        $this->pruneMigrationFolders($dry);
        $this->pruneOlderFiles(storage_path('app/backups/quick-exports'), QuickExportLinkFactory::TTL_HOURS + 1, $dry);
        $this->pruneOlderFiles(storage_path('app/backups/clone-share'), self::ORPHAN_HOURS, $dry);
        $this->pruneOlderFiles(storage_path('app/migrations/clone-share'), self::ORPHAN_HOURS, $dry);
        $this->repairCronLines($cron, $dry);

        $this->info(sprintf('%s %d item(s), %s.', $dry ? 'Would remove' : 'Removed', $this->items, $this->size($this->bytes)));

        return self::SUCCESS;
    }

    private function expireImports(bool $dry): void
    {
        MigrationImport::query()
            ->whereNotIn('status', ['expired', 'restoring'])
            ->where('created_at', '<', now()->subHours(self::MIGRATION_HOURS))
            ->get()
            ->each(function (MigrationImport $import) use ($dry): void {
                $dir = (string) data_get($import->inventory, 'upload_dir', '');
                $size = ($dir !== '' && is_dir($dir) ? $this->folderSize($dir) : 0) + (is_file((string) $import->archive_path) ? (int) filesize((string) $import->archive_path) : 0);
                $this->report("import {$import->id} ({$import->provider}, {$import->status})", $size);
                if (! $dry) {
                    try {
                        (new DeleteMigrationImportArtifactsJob($import->id))->handle();
                    } catch (\Throwable $e) {
                        $this->warn("  could not remove: {$e->getMessage()}");
                    }
                }
            });
    }

    private function pruneMigrationFolders(bool $dry): void
    {
        $root = storage_path('app/migrations');
        if (! is_dir($root)) {
            return;
        }
        $cutoff = now()->subHours(self::ORPHAN_HOURS)->getTimestamp();
        // glob, not File::directories: that skips the hidden `.generic-*` folders.
        foreach (array_merge(glob($root.'/*', GLOB_ONLYDIR) ?: [], glob($root.'/.generic-*', GLOB_ONLYDIR) ?: []) as $directory) {
            $name = basename($directory);
            $orphanImport = Str::isUuid($name) && ! MigrationImport::query()->whereKey($name)->where('status', '!=', 'expired')->exists();
            $restoreStage = str_starts_with($name, '.generic-');
            if ((! $orphanImport && ! $restoreStage) || is_link($directory) || filemtime($directory) > $cutoff) {
                continue;
            }
            $this->remove($directory, $dry);
        }
    }

    private function pruneOlderFiles(string $directory, int $hours, bool $dry): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $cutoff = now()->subHours($hours)->getTimestamp();
        foreach (array_merge(File::files($directory, true), File::directories($directory)) as $item) {
            $path = is_string($item) ? $item : $item->getPathname();
            if (! is_link($path) && filemtime($path) < $cutoff) {
                $this->remove($path, $dry);
            }
        }
    }

    /** A cron line written from a web request named php-fpm, which can't run artisan. */
    private function repairCronLines(CronSystemService $cron, bool $dry): void
    {
        $php = app(LocalCommand::class)->panelPhpCli();
        CronJob::query()->where('command', 'like', '%php-fpm%artisan%')->get()->each(function (CronJob $job) use ($cron, $php, $dry): void {
            $fixed = (string) preg_replace('#\S*php-fpm[0-9.]*(?=\s+artisan\b)#', $php, (string) $job->command);
            $this->line("cron {$job->id}: php-fpm → {$php}");
            if ($dry || $fixed === $job->command) {
                return;
            }
            $website = Website::query()->find($job->website_id);
            $job->forceFill(['command' => $fixed])->save();
            try {
                $website && $cron->sync($job, $website);
            } catch (\Throwable $e) {
                $this->warn("  saved, but the system cron was not updated: {$e->getMessage()}");
            }
        });
    }

    private function remove(string $path, bool $dry): void
    {
        $this->report(Str::after($path, storage_path('app').'/'), is_dir($path) ? $this->folderSize($path) : (int) @filesize($path));
        if ($dry) {
            return;
        }
        $removed = is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        if (! $removed || file_exists($path)) {
            // dRust extracts as root, so some folders need root to remove.
            $this->warn("  could not remove (owned by another user?): {$path}");
        }
    }

    private function report(string $what, int $bytes): void
    {
        $this->items++;
        $this->bytes += $bytes;
        $this->line("- {$what} ({$this->size($bytes)})");
    }

    private function folderSize(string $directory): int
    {
        $total = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $total += $file->isFile() && ! $file->isLink() ? $file->getSize() : 0;
            }
        } catch (\Throwable) {
            // Unreadable parts are left out of the estimate.
        }

        return $total;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 2).' GB' : round($bytes / 1024 ** 2, 1).' MB';
    }
}
