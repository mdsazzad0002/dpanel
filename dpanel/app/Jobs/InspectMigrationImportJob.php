<?php

namespace App\Jobs;

use App\Models\MigrationImport;
use App\Services\Migration\CpanelMigrationProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function handle(CpanelMigrationProvider $provider): void
    {
        $import = MigrationImport::find($this->importId);
        if (! $import || ! is_file($import->archive_path)) {
            return;
        }

        try {
            $inventory = $provider->inspect($import->archive_path);
            $import->update(['inventory' => $inventory, 'status' => 'ready', 'last_error' => null]);
        } catch (Throwable $exception) {
            $import->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
