<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\MigrationImport;
use App\Models\Website;
use App\Services\Migration\AccountArchiveMigrationProvider;
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

    public function handle(AccountArchiveMigrationProvider $provider): void
    {
        $import = MigrationImport::find($this->importId);
        if (! $import || ! file_exists($import->archive_path)) {
            return;
        }

        try {
            $credentials = $this->credentials($import);
            $result = $provider->restore($import, $this->selection, $credentials);
            $inventory = collect($import->inventory['domains'] ?? [])->keyBy('id');
            $domains = $this->selection['full_account'] ? $inventory->keys()->all() : $this->selection['domains'];
            DB::transaction(function () use ($domains, $inventory, $import, $credentials, $result): void {
                $this->registerDatabases($import, $credentials, $result, $domains);
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
            $import->update([
                'status' => 'completed',
                'last_error' => null,
                // What was connected, and anything left to do by hand.
                'inventory' => (array) $import->inventory + ['restore_result' => [
                    'connected' => $result['connected'] ?? [],
                    'warnings' => $result['warnings'] ?? [],
                ]],
            ]);
        } catch (Throwable $exception) {
            $import->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    /**
     * A login for every database being restored. A database the panel
     * already knows keeps its login, so sites using it keep working;
     * others get a user named after the database and a new password.
     *
     * @return array<string, array{user: string, password: string}>
     */
    private function credentials(MigrationImport $import): array
    {
        $names = $this->selection['full_account']
            ? collect($import->inventory['databases'] ?? [])->pluck('id')->all()
            : $this->selection['databases'];
        $credentials = [];
        foreach ($names as $name) {
            $known = DatabaseRequest::query()->where('database_name', $name)->first();
            if ($known && $known->database_user && $known->database_password) {
                $credentials[$name] = ['user' => $known->database_user, 'password' => $known->database_password];

                continue;
            }
            $base = substr(preg_replace('/[^A-Za-z0-9_]/', '_', $name), 0, 32);
            $user = $base;
            for ($n = 2; DatabaseRequest::query()->where('database_user', $user)->where('database_name', '!=', $name)->exists(); $n++) {
                $user = substr($base, 0, 32 - strlen("_{$n}"))."_{$n}";
            }
            $credentials[$name] = ['user' => $user, 'password' => Str::password(24, symbols: false)];
        }

        return $credentials;
    }

    /**
     * List restored databases on the Databases page, under the domain whose
     * site was connected to them (else the account's first domain).
     *
     * @param  array<string, array{user: string, password: string}>  $credentials
     * @param  array<int, string>  $domains
     */
    private function registerDatabases(MigrationImport $import, array $credentials, array $result, array $domains): void
    {
        $siteOf = collect($result['connected'] ?? [])->pluck('domain', 'database');
        $fallback = $domains[0] ?? collect($import->inventory['domains'] ?? [])->pluck('id')->first() ?? (string) ($import->inventory['account'] ?? '');
        foreach ((array) ($result['databases'] ?? []) as $name) {
            if (! isset($credentials[$name])) {
                continue;
            }
            $database = DatabaseRequest::firstOrNew(['database_name' => $name]);
            if (! $database->exists) {
                $database->id = (string) Str::uuid();
                $database->domain = (string) ($siteOf[$name] ?? $fallback);
            }
            $database->fill([
                'engine' => 'mariadb', 'database_user' => $credentials[$name]['user'], 'database_password' => $credentials[$name]['password'],
                'database_host' => '127.0.0.1', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active',
            ])->save();
        }
    }
}
