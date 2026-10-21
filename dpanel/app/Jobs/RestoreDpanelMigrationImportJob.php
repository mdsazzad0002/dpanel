<?php

namespace App\Jobs;

use App\Models\MigrationImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class RestoreDpanelMigrationImportJob implements ShouldQueue
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
            $enteredMaintenance = ! app()->isDownForMaintenance();
            if ($enteredMaintenance) {
                $maintenance = Process::path(base_path())
                    ->timeout(120)
                    ->run([PHP_BINARY, base_path('artisan'), 'down', '--retry=60']);
                if (! $maintenance->successful()) {
                    throw new RuntimeException('Could not put the target panel into maintenance mode: '.trim($maintenance->errorOutput().' '.$maintenance->output()));
                }
            }

            $restoreError = null;
            try {
                $result = Process::path(base_path())
                    ->timeout($this->timeout - 60)
                    ->run([PHP_BINARY, base_path('artisan'), 'serverpanel:restore', $import->archive_path, '--force']);
                if (! $result->successful()) {
                    throw new RuntimeException('DPanel restore failed: '.trim($result->errorOutput().' '.$result->output()));
                }
            } catch (Throwable $exception) {
                $restoreError = $exception;
            } finally {
                if ($enteredMaintenance) {
                    $up = Process::path(base_path())
                        ->timeout(120)
                        ->run([PHP_BINARY, base_path('artisan'), 'up']);
                    if (! $up->successful()) {
                        $message = 'Could not disable target maintenance mode: '.trim($up->errorOutput().' '.$up->output());
                        $restoreError = $restoreError === null
                            ? new RuntimeException('DPanel restore finished, but '.$message)
                            : new RuntimeException($restoreError->getMessage().' '.$message, previous: $restoreError);
                    }
                }
            }
            if ($restoreError !== null) {
                throw $restoreError;
            }

            // The restored database may replace the migration record itself.
            // Update the status only if the record is still present afterward.
            MigrationImport::query()->where('provider', 'dpanel')->whereKey($this->importId)
                ->update(['status' => 'completed', 'last_error' => null]);
        } catch (Throwable $exception) {
            MigrationImport::query()->where('provider', 'dpanel')->whereKey($this->importId)
                ->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
