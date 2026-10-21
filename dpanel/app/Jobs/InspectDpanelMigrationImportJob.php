<?php

namespace App\Jobs;

use App\Models\MigrationImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class InspectDpanelMigrationImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3500;

    public int $tries = 1;

    public function __construct(public string $importId)
    {
        $this->onQueue('heavy');
    }

    public function handle(): void
    {
        $import = MigrationImport::query()->where('provider', 'dpanel')->find($this->importId);
        if (! $import) {
            return;
        }
        if (! is_file($import->archive_path)) {
            $import->update(['status' => 'failed', 'last_error' => 'Uploaded DPanel archive is missing.']);

            return;
        }

        try {
            $result = Process::path(base_path())
                ->timeout($this->timeout - 60)
                ->run([PHP_BINARY, base_path('artisan'), 'serverpanel:restore', $import->archive_path, '--dry-run']);
            if (! $result->successful()) {
                throw new RuntimeException('DPanel package validation failed: '.trim($result->errorOutput().' '.$result->output()));
            }

            $import->update([
                'status' => 'ready',
                'inventory' => array_merge((array) $import->inventory, [
                    'validation' => trim($result->output()),
                    'validated_at' => now()->toIso8601String(),
                ]),
                'last_error' => null,
            ]);
        } catch (\Throwable $exception) {
            $import->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
