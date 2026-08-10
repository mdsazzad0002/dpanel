<?php

namespace App\Jobs;

use App\Models\MigrationImport;
use App\Models\Website;
use App\Services\Migration\CpanelMigrationProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RestoreMigrationImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    /** @param array{domains:array<int,string>,files:array<int,string>,databases:array<int,string>,full_account:bool} $selection */
    public function __construct(public string $importId, public array $selection, public ?int $resellerId)
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
            $provider->restore($import->archive_path, $this->selection);
            $inventory = collect($import->inventory['domains'] ?? [])->keyBy('id');
            $domains = $this->selection['full_account'] ? $inventory->keys()->all() : $this->selection['domains'];
            DB::transaction(function () use ($domains, $inventory, $import): void {
                foreach ($domains as $domain) {
                    if (Website::where('domain', $domain)->exists()) {
                        continue;
                    }
                    $root = (string) ($inventory->get($domain)['document_root'] ?? '/home/'.($import->inventory['account'] ?? '').'/public_html');
                    Website::create([
                        'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
                        'scope' => 'local', 'project_root' => dirname($root), 'root_path' => $root,
                        'start_directory' => '', 'site_owner' => $import->inventory['account'] ?? null,
                        'php_version' => '8.3', 'enable_ssl' => false, 'manage_dns' => false,
                        'assigned_reseller_id' => $this->resellerId ?: $import->assigned_reseller_id,
                        'status' => 'live', 'type' => 'primary', 'ssl_mode' => 'none',
                    ]);
                }
            });
            $import->update(['status' => 'completed', 'last_error' => null]);
        } catch (Throwable $exception) {
            $import->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
