<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\MigrationImport;
use App\Services\Migration\GenericWebsiteMigrationProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RestoreGenericWebsiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $importId)
    {
        $this->onQueue('heavy');
    }

    public function handle(GenericWebsiteMigrationProvider $provider): void
    {
        $import = MigrationImport::find($this->importId);
        if (! $import) {
            return;
        }
        try {
            $settings = (array) $import->inventory;
            $result = $provider->restore(['archive_path' => $import->archive_path] + $settings);
            DB::transaction(function () use ($settings): void {
                if (! empty($settings['sql_path'])) {
                    $database = DatabaseRequest::firstOrNew(['database_name' => $settings['database_name']]);
                    if (! $database->exists) {
                        $database->id = (string) Str::uuid();
                    }
                    $database->fill([
                        'domain' => $settings['domain'], 'database_user' => $settings['database_user'], 'database_password' => $settings['database_password'],
                        'database_host' => $settings['database_host'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active',
                        'assigned_user_id' => $settings['assigned_user_id'] ?? null,
                    ])->save();
                }
            });
            $import->update(['status' => 'completed', 'inventory' => $settings + $result, 'last_error' => null]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
            throw $e;
        }
    }
}
