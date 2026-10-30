<?php

namespace App\Jobs;

use App\Models\MigrationImport;
use App\Services\Migration\AccountArchiveMigrationProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Throwable;

class InspectMigrationImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries = 2;

    public function __construct(public string $importId)
    {
        $this->onQueue('heavy');
    }

    public function handle(AccountArchiveMigrationProvider $provider): void
    {
        $import = MigrationImport::find($this->importId);
        if (! $import || ! file_exists($import->archive_path)) {
            return;
        }

        try {
            // Keep what the upload stored (partial backup options, upload dir).
            $inventory = $provider->inspect($import) + Arr::only((array) $import->inventory, ['options', 'upload_dir']);
            $import->update(['inventory' => $inventory, 'status' => 'ready', 'last_error' => null]);
        } catch (Throwable $exception) {
            $import->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
