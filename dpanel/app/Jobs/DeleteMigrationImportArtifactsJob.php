<?php

namespace App\Jobs;

use App\Models\MigrationImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;

class DeleteMigrationImportArtifactsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $importId)
    {
    }

    public function handle(): void
    {
        $import = MigrationImport::find($this->importId);
        if (! $import) {
            return;
        }

        // Do not remove an archive while a restore is actively using it.
        if ($import->status === 'restoring') {
            self::dispatch($this->importId)->delay(now()->addMinutes(15));

            return;
        }

        $root = realpath(storage_path('app/migrations'));
        if ($root === false) {
            return;
        }

        $paths = [(string) $import->archive_path, (string) data_get($import->inventory, 'sql_path', '')];
        foreach ($paths as $path) {
            if ($path === '' || ! $this->isWithin($path, $root) || is_link($path)) {
                continue;
            }
            if (is_file($path) && ! @unlink($path)) {
                throw new \RuntimeException("Could not remove expired migration file: {$path}");
            }
        }

        $uploadDir = (string) data_get($import->inventory, 'upload_dir', '');
        if ($uploadDir !== '' && $this->isWithin($uploadDir, $root) && is_dir($uploadDir) && ! is_link($uploadDir)) {
            if (! File::deleteDirectory($uploadDir) && is_dir($uploadDir)) {
                throw new \RuntimeException("Could not remove expired migration directory: {$uploadDir}");
            }
        }

        $import->update(['status' => 'expired', 'last_error' => 'Migration files expired after 8 hours.']);
    }

    private function isWithin(string $path, string $root): bool
    {
        $parent = realpath(dirname($path));
        return $parent !== false
            && ($parent === $root || str_starts_with($parent, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
    }
}
