<?php

namespace App\Http\Controllers;

use App\Jobs\InspectMigrationImportJob;
use App\Jobs\RestoreGenericWebsiteJob;
use App\Jobs\RestoreMigrationImportJob;
use App\Models\DatabaseRequest;
use App\Models\MigrationImport;
use App\Models\MigrationSshConnection;
use App\Services\Migration\CyberPanelSshInventoryService;
use App\Services\Migration\GenericWebsiteMigrationProvider;
use App\Services\Php\PhpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MigrationController extends Controller
{
    public function index(Request $request, string $token): Response
    {
        return Inertia::render('Migrations/Index', [
            'provider' => null,
            'imports' => [],
        ]);
    }

    public function cpanel(Request $request, string $token): Response
    {
        return Inertia::render('Migrations/Index', [
            'provider' => 'cpanel',
            'imports' => MigrationImport::visibleTo($request->user())
                ->where('provider', 'cpanel')
                ->latest()
                ->get(),
        ]);
    }

    public function cyberpanelSsh(Request $request, string $token): Response
    {
        return Inertia::render('Migrations/Index', [
            'provider' => 'cyberpanel-ssh',
            'imports' => [],
            'phpVersions' => PhpService::getPhpVersions(),
            'savedSshConnections' => MigrationSshConnection::query()->where('user_id', $request->user()->id)
                ->latest()->get(['id', 'name', 'host', 'port', 'username', 'auth_type']),
        ]);
    }

    public function inspectCyberpanelSsh(Request $request, string $token, CyberPanelSshInventoryService $inventory): JsonResponse
    {
        $credentials = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'],
            'auth_type' => ['required', 'in:password,key'],
            'password' => ['nullable', 'required_if:auth_type,password', 'string', 'max:1000'],
            'private_key' => ['nullable', 'required_if:auth_type,key', 'string', 'max:32768'],
            'key_passphrase' => ['nullable', 'string', 'max:1000'],
            'remember_access' => ['nullable', 'boolean'],
            'connection_name' => ['nullable', 'required_if:remember_access,true', 'string', 'max:100'],
        ]);

        try {
            $result = $inventory->discover($credentials);
            $savedConnection = null;
            if ((bool) ($credentials['remember_access'] ?? false)) {
                $connection = MigrationSshConnection::query()->firstOrNew([
                    'user_id' => $request->user()->id, 'host' => $credentials['host'], 'port' => $credentials['port'], 'username' => $credentials['username'],
                ]);
                if (! $connection->exists) {
                    $connection->id = (string) Str::uuid();
                }
                $connection->fill([
                    'name' => $credentials['connection_name'], 'auth_type' => $credentials['auth_type'],
                    'password' => $credentials['auth_type'] === 'password' ? $credentials['password'] : null,
                    'private_key' => $credentials['auth_type'] === 'key' ? $credentials['private_key'] : null,
                    'key_passphrase' => $credentials['auth_type'] === 'key' ? ($credentials['key_passphrase'] ?? null) : null,
                ])->save();
                $savedConnection = $connection->only(['id', 'name', 'host', 'port', 'username', 'auth_type']);
            }

            return response()->json(['inventory' => $result, 'saved_connection' => $savedConnection]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function showCyberpanelSshConnection(Request $request, string $token, string $connection): JsonResponse
    {
        $connection = MigrationSshConnection::query()->where('user_id', $request->user()->id)->findOrFail($connection);

        return response()->json(['connection' => [
            'id' => $connection->id, 'connection_name' => $connection->name,
            'host' => $connection->host, 'port' => $connection->port, 'username' => $connection->username,
            'auth_type' => $connection->auth_type, 'password' => $connection->password ?? '',
            'private_key' => $connection->private_key ?? '', 'key_passphrase' => $connection->key_passphrase ?? '',
            'remember_access' => true,
        ]]);
    }

    public function destroyCyberpanelSshConnection(Request $request, string $token, string $connection): JsonResponse
    {
        $connection = MigrationSshConnection::query()->where('user_id', $request->user()->id)->findOrFail($connection);
        $connection->delete();

        return response()->json(['message' => 'Saved SSH connection deleted.']);
    }

    public function downloadCyberpanelSsh(Request $request, string $token, CyberPanelSshInventoryService $inventory): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253'], 'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'], 'auth_type' => ['required', 'in:password,key'],
            'password' => ['nullable', 'required_if:auth_type,password', 'string', 'max:1000'],
            'private_key' => ['nullable', 'required_if:auth_type,key', 'string', 'max:32768'], 'key_passphrase' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'in:files,database'], 'source_path' => ['nullable', 'required_if:type,files', 'string', 'max:4096'],
            'database' => ['nullable', 'required_if:type,database', 'string', 'max:64'],
        ]);
        try {
            $download = $inventory->download($data, (string) $data['type'], $data['source_path'] ?? null, $data['database'] ?? null);

            return response()->download($download['path'], $download['name'], ['Content-Type' => $download['mime']])->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function prepareCyberpanelSsh(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/'],
            'source_path' => ['required', 'string', 'max:4096', 'regex:#^/(?:home|www/wwwroot|var/www/vhosts)/(?!.*(?:^|/)\.\.(?:/|$)).+#'],
            'database' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'php_version' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
        ]);
        $id = (string) Str::uuid();
        $directory = storage_path('app/migrations/'.$id);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            abort(500, 'Could not prepare migration temp storage.');
        }
        $actor = $request->user();
        $steps = collect(['prepare', 'download', 'website', 'restore', 'verify'])->mapWithKeys(fn (string $step): array => [$step => ['status' => $step === 'prepare' ? 'completed' : 'waiting', 'message' => $step === 'prepare' ? 'Temporary storage prepared.' : null]])->all();
        $import = MigrationImport::create([
            'id' => $id, 'provider' => 'cyberpanel-ssh', 'original_name' => $data['domain'],
            'archive_path' => $directory.'/website.tar.gz', 'archive_size' => 0, 'status' => 'prepared',
            'inventory' => $data + ['sql_path' => filled($data['database'] ?? null) ? $directory.'/database.sql' : null, 'steps' => $steps],
            'created_by' => $actor->id, 'assigned_reseller_id' => $actor->hasRole('reseller') ? $actor->id : null,
        ]);

        return response()->json(['message' => 'Temporary migration storage prepared.', 'run' => $import]);
    }

    public function stageCyberpanelSsh(Request $request, string $token, string $migrationImport, CyberPanelSshInventoryService $inventory): JsonResponse
    {
        $import = MigrationImport::visibleTo($request->user())->where('provider', 'cyberpanel-ssh')->findOrFail($migrationImport);
        abort_unless(in_array($import->status, ['prepared', 'download_failed', 'downloaded'], true), 422, 'This migration cannot download source data now.');
        $credentials = $request->validate([
            'host' => ['required', 'string', 'max:253'], 'port' => ['required', 'integer', 'between:1,65535'], 'username' => ['required', 'string', 'max:64'],
            'auth_type' => ['required', 'in:password,key'], 'password' => ['nullable', 'required_if:auth_type,password', 'string', 'max:1000'],
            'private_key' => ['nullable', 'required_if:auth_type,key', 'string', 'max:32768'], 'key_passphrase' => ['nullable', 'string', 'max:1000'],
        ]);
        $settings = (array) $import->inventory;
        try {
            $import->update(['status' => 'downloading', 'last_error' => null]);
            $files = $inventory->download($credentials, 'files', (string) $settings['source_path'], null);
            rename($files['path'], $import->archive_path);
            if (filled($settings['database'] ?? null)) {
                $database = $inventory->download($credentials, 'database', null, (string) $settings['database']);
                rename($database['path'], (string) $settings['sql_path']);
            }
            data_set($settings, 'steps.download', ['status' => 'completed', 'message' => 'Source files and database downloaded.']);
            $import->update(['status' => 'downloaded', 'archive_size' => filesize($import->archive_path), 'inventory' => $settings]);

            return response()->json(['message' => 'Source download completed.', 'run' => $import->fresh()]);
        } catch (\Throwable $exception) {
            data_set($settings, 'steps.download', ['status' => 'failed', 'message' => $exception->getMessage()]);
            $import->update(['status' => 'download_failed', 'inventory' => $settings, 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function restoreCyberpanelSsh(Request $request, string $token, string $migrationImport, GenericWebsiteMigrationProvider $provider): JsonResponse
    {
        $import = MigrationImport::visibleTo($request->user())->where('provider', 'cyberpanel-ssh')->findOrFail($migrationImport);
        abort_unless(in_array($import->status, ['downloaded', 'restore_failed'], true), 422, 'Complete the source download first.');
        $data = $request->validate(['website_id' => ['required', 'uuid']]);
        $website = \App\Models\Website::visibleTo($request->user())->findOrFail($data['website_id']);
        $settings = (array) $import->inventory;
        $sqlPath = filled($settings['sql_path'] ?? null) ? (string) $settings['sql_path'] : null;
        $owner = (string) $website->site_owner;
        $domainPrefix = substr(trim(strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', explode('.', (string) $website->domain)[0])), '_') ?: 'site', 0, 16);
        $database = $sqlPath ? new DatabaseRequest([
            'database_name' => substr($owner.'_'.$domainPrefix.'_db', 0, 64), 'database_user' => substr($owner.'_'.$domainPrefix.'_user', 0, 64),
            'database_password' => Str::password(24), 'database_host' => '127.0.0.1',
        ]) : null;
        $payload = ['archive_path' => $import->archive_path, 'domain' => $website->domain, 'site_owner' => $owner,
            'php_version' => $website->php_version, 'target_root' => $website->root_path, 'sql_path' => $sqlPath,
            'database_host' => $database?->database_host ?? '127.0.0.1', 'database_port' => 3306,
            'database_name' => $database?->database_name ?? '', 'database_user' => $database?->database_user ?? '',
            'database_password' => $database?->database_password ?? '', 'overwrite_database' => false];
        try {
            $import->update(['status' => 'restoring', 'last_error' => null]);
            $result = $provider->restore($payload);
            DB::transaction(function () use ($database, $website): void {
                if ($database === null) {
                    return;
                }
                $database->forceFill(['id' => (string) Str::uuid(), 'domain' => $website->domain, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                    'status' => 'active', 'assigned_user_id' => $website->assigned_user_id])->save();
            });
            data_set($settings, 'steps.website', ['status' => 'completed', 'message' => 'Website account created.']);
            data_set($settings, 'steps.restore', ['status' => 'completed', 'message' => 'Files and database restored.']);
            data_set($settings, 'steps.verify', ['status' => 'completed', 'message' => 'Website is live.']);
            $import->update(['status' => 'completed', 'inventory' => $settings + ['website_id' => $website->id] + $result]);

            return response()->json(['message' => 'Website migration completed and is live.', 'run' => $import->fresh(), 'website' => $website]);
        } catch (\Throwable $exception) {
            data_set($settings, 'steps.restore', ['status' => 'failed', 'message' => $exception->getMessage()]);
            $import->update(['status' => 'restore_failed', 'inventory' => $settings, 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function websiteImport(Request $request, string $token, string $id): Response
    {
        $website = \App\Models\Website::visibleTo($request->user())->findOrFail($id);
        $databases = DatabaseRequest::query()
            ->visibleTo($request->user())
            ->whereRaw('LOWER(domain) = ?', [strtolower((string) $website->domain)])
            ->where('status', 'active')
            ->latest()
            ->get();

        $otherWebsites = \App\Models\Website::query()->visibleTo($request->user())
            ->where('id', '!=', $id)
            ->orderBy('domain')
            ->get(['id', 'domain'])
            ->values();

        return Inertia::render('Websites/QuickImport', [
            'website' => $website,
            'otherWebsites' => $otherWebsites,
            'databaseConnection' => [
                'available' => $databases->isNotEmpty(),
                'database_name' => $databases->first()?->database_name,
                'databases' => $databases->map(fn (DatabaseRequest $database): array => [
                    'id' => $database->id,
                    'database_name' => $database->database_name,
                ])->values()->all(),
            ],
            'suggestedDatabaseName' => $this->suggestedDatabaseName($website),
            'imports' => MigrationImport::visibleTo($request->user())->where('provider', 'generic')->where('inventory->domain', $website->domain)->latest()->get()
                ->each(function (MigrationImport $import): void {
                    $inventory = (array) $import->inventory;
                    unset($inventory['database_password']);
                    $import->setAttribute('inventory', $inventory);
                }),
        ]);
    }

    private function suggestedDatabaseName(\App\Models\Website $website): string
    {
        $owner = (string) $website->site_owner;
        $domainPrefix = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', explode('.', (string) $website->domain)[0]));
        $domainPrefix = substr(trim($domainPrefix, '_') ?: 'site', 0, 16);
        $nameSuffix = $domainPrefix.'_db';

        return substr($owner.'_', 0, 64 - strlen($nameSuffix)).$nameSuffix;
    }

    public function storeWebsiteImport(Request $request, string $token, string $id): JsonResponse
    {
        $website = \App\Models\Website::visibleTo($request->user())->findOrFail($id);
        $data = $request->validate([
            'archive_name' => ['required', 'string', 'max:255'], 'archive_size' => ['required', 'integer', 'between:1,5368709120'],
            'database_name_file' => ['nullable', 'string', 'max:255'], 'database_size' => ['nullable', 'required_with:database_name_file', 'integer', 'between:1,2147483648'],
            'database_id' => ['nullable', 'string'],
            'new_database_name' => ['nullable', 'string', 'max:64'],
            'overwrite_database' => ['nullable', 'boolean'],
        ]);
        $archiveName = basename((string) $data['archive_name']);
        abort_unless(preg_match('/\.(?:zip|tar\.gz|tgz)$/i', $archiveName), 422, 'Upload a .zip, .tar.gz, or .tgz website archive.');
        $databaseFileName = isset($data['database_name_file']) ? basename((string) $data['database_name_file']) : null;
        abort_if($databaseFileName !== null && preg_match('/\.sql$/i', $databaseFileName) !== 1, 422, 'Database dump must be a .sql file.');

        $database = null;
        if ($databaseFileName !== null && ! empty($data['database_id'])) {
            $database = DatabaseRequest::query()
                ->visibleTo($request->user())
                ->whereRaw('LOWER(domain) = ?', [strtolower((string) $website->domain)])
                ->where('status', 'active')
                ->findOrFail($data['database_id']);
            abort_unless((bool) ($data['overwrite_database'] ?? false), 409, 'Confirm database overwrite before uploading the SQL dump.');
        }
        $trackingId = (string) Str::uuid();
        $directory = storage_path('app/migrations/'.$trackingId);
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $archivePath = $directory.'/website-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $archiveName);
        $sqlPath = $databaseFileName !== null ? $directory.'/database.sql' : null;
        $owner = (string) $website->site_owner;
        abort_if($owner === '', 422, 'This website does not have a system user.');
        if ($databaseFileName !== null && $database === null) {
            $domainPrefix = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', explode('.', (string) $website->domain)[0]));
            $domainPrefix = substr(trim($domainPrefix, '_') ?: 'site', 0, 16);
            $userSuffix = $domainPrefix.'_user';
            $customName = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', (string) ($data['new_database_name'] ?? '')));
            $customName = trim($customName, '_');
            $databaseName = $customName !== '' ? substr($customName, 0, 64) : $this->suggestedDatabaseName($website);
            $database = new DatabaseRequest([
                'database_name' => $databaseName,
                'database_user' => substr($owner.'_', 0, 64 - strlen($userSuffix)).$userSuffix,
                'database_password' => Str::password(24),
                'database_host' => '127.0.0.1',
            ]);
        }
        $inventory = ['domain' => strtolower((string) $website->domain), 'website_id' => (string) $website->id, 'site_owner' => $owner, 'php_version' => (string) $website->php_version,
            'assigned_user_id' => $website->assigned_user_id,
            // root_path is the website-specific project directory. project_root can be
            // the shared account home (especially for subdomains), so importing there
            // would unpack one site's files into every site owner's common directory.
            'target_root' => (string) ($website->root_path ?: $website->project_root),
            'target_project_root' => (string) $website->project_root,
            'sql_path' => $sqlPath, 'database_host' => (string) ($database?->database_host ?: '127.0.0.1'), 'database_port' => 3306,
            'database_name' => (string) ($database?->database_name ?? ''), 'database_user' => (string) ($database?->database_user ?? ''),
            'database_password' => (string) ($database?->database_password ?? ''),
            'overwrite_database' => $databaseFileName !== null && $database->exists,
            'uploads' => ['archive' => ['name' => $archiveName, 'size' => (int) $data['archive_size']],
                'database' => $databaseFileName === null ? null : ['name' => $databaseFileName, 'size' => (int) $data['database_size']]]];
        $actor = $request->user();
        $import = MigrationImport::create(['id' => $trackingId, 'provider' => 'generic', 'original_name' => $archiveName, 'archive_path' => $archivePath,
            'archive_size' => (int) $data['archive_size'], 'status' => 'uploading', 'inventory' => $inventory, 'created_by' => $actor->id,
            'assigned_reseller_id' => $actor->hasRole('reseller') ? $actor->id : null]);

        return response()->json(['message' => 'Tracked upload initialized.', 'tracking_id' => $trackingId, 'status' => 'uploading'], 201);
    }

    public function uploadWebsiteImportChunk(Request $request, string $token, string $id, string $tracking, string $kind): JsonResponse
    {
        $import = $this->trackedWebsiteImport($request, $id, $tracking);
        $data = $request->validate(['index' => ['required', 'integer', 'min:0'], 'total' => ['required', 'integer', 'between:1,10000'], 'chunk' => ['required', 'file', 'max:6144']]);
        abort_unless(in_array($kind, ['archive', 'database'], true), 404);
        abort_if($kind === 'database' && data_get($import->inventory, 'uploads.database') === null, 422, 'No database upload was initialized.');
        $directory = dirname($import->archive_path).'/chunks';
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $path = $directory.'/'.$kind.'.'.(int) $data['index'].'.part';
        $incomingHash = hash_file('sha256', $request->file('chunk')->getRealPath());
        $duplicate = is_file($path);
        if ($duplicate) {
            abort_unless(hash_file('sha256', $path) === $incomingHash, 409, 'A different chunk already exists at this index.');
        } else {
            $request->file('chunk')->move($directory, basename($path));
        }

        return response()->json(['tracking_id' => $tracking, 'kind' => $kind, 'index' => (int) $data['index'], 'total' => (int) $data['total'], 'duplicate' => $duplicate]);
    }

    public function completeWebsiteImportUpload(Request $request, string $token, string $id, string $tracking, string $kind): JsonResponse
    {
        $import = $this->trackedWebsiteImport($request, $id, $tracking);
        $data = $request->validate(['total' => ['required', 'integer', 'between:1,10000']]);
        abort_unless(in_array($kind, ['archive', 'database'], true), 404);
        $target = $kind === 'archive' ? $import->archive_path : (string) data_get($import->inventory, 'sql_path');
        abort_if($target === '', 422, 'This upload stage was not initialized.');
        $chunkDirectory = dirname($import->archive_path).'/chunks';
        $lock = fopen(dirname($import->archive_path).'/'.$kind.'.lock', 'c');
        abort_if($lock === false || ! flock($lock, LOCK_EX), 503, 'Upload finalization is busy.');
        try {
            if (! is_file($target)) {
                $temporary = $target.'.assembling';
                $output = fopen($temporary, 'wb');
                abort_if($output === false, 500, 'Cannot assemble upload.');
                for ($index = 0; $index < (int) $data['total']; $index++) {
                    $part = $chunkDirectory.'/'.$kind.'.'.$index.'.part';
                    if (! is_file($part)) {
                        fclose($output);
                        @unlink($temporary);
                        abort(422, "Missing {$kind} chunk {$index}.");
                    }
                    $input = fopen($part, 'rb');
                    stream_copy_to_stream($input, $output);
                    fclose($input);
                }
                fclose($output);
                rename($temporary, $target);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $expected = (int) data_get($import->inventory, 'uploads.'.$kind.'.size', 0);
        abort_if($expected > 0 && filesize($target) !== $expected, 422, 'Assembled upload size does not match the original file.');

        return response()->json(['tracking_id' => $tracking, 'kind' => $kind, 'ready' => true, 'size' => filesize($target)]);
    }

    public function connectWebsiteImport(Request $request, string $token, string $id, string $tracking): JsonResponse
    {
        $this->trackedWebsiteImport($request, $id, $tracking);
        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($id, $tracking): array {
            $import = MigrationImport::query()->lockForUpdate()->findOrFail($tracking);
            abort_unless((string) data_get($import->inventory, 'website_id') === $id, 404);
            if (in_array($import->status, ['restoring', 'completed'], true)) {
                return ['status' => $import->status, 'duplicate' => true];
            }
            abort_unless(is_file($import->archive_path), 422, 'Website file upload is not ready.');
            $sqlPath = data_get($import->inventory, 'sql_path');
            abort_if(is_string($sqlPath) && $sqlPath !== '' && ! is_file($sqlPath), 422, 'Database upload is not ready.');
            $import->update(['status' => 'restoring', 'last_error' => null]);
            RestoreGenericWebsiteJob::dispatch($import->id)->afterCommit();

            return ['status' => 'restoring', 'duplicate' => false];
        });
        if ($result['duplicate']) {
            return response()->json(['tracking_id' => $tracking] + $result);
        }

        return response()->json(['tracking_id' => $tracking, 'status' => 'restoring', 'message' => 'Uploads are ready; connection is running in the heavy queue.'], 202);
    }

    public function websiteImportStatus(Request $request, string $token, string $id, string $tracking): JsonResponse
    {
        $import = $this->trackedWebsiteImport($request, $id, $tracking);
        $sqlPath = data_get($import->inventory, 'sql_path');

        return response()->json(['tracking_id' => $tracking, 'status' => $import->status,
            'database_ready' => ! is_string($sqlPath) || $sqlPath === '' || is_file($sqlPath),
            'database_skipped' => ! is_string($sqlPath) || $sqlPath === '',
            'archive_ready' => is_file($import->archive_path), 'last_error' => $import->last_error]);
    }

    private function trackedWebsiteImport(Request $request, string $websiteId, string $tracking): MigrationImport
    {
        \App\Models\Website::visibleTo($request->user())->findOrFail($websiteId);
        $import = MigrationImport::visibleTo($request->user())->where('provider', 'generic')->findOrFail($tracking);
        abort_unless((string) data_get($import->inventory, 'website_id') === $websiteId, 404);

        return $import;
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $request->validate(['provider' => ['required', 'in:cpanel'], 'archive' => ['required', 'file', 'max:5242880']]);
        $file = $request->file('archive');
        $name = (string) $file->getClientOriginalName();
        abort_unless(preg_match('/\.(?:tar\.gz|tgz)$/i', $name), 422, 'Upload a cpmove-USER.tar.gz or .tgz archive.');

        $id = (string) Str::uuid();
        $directory = storage_path('app/migrations');
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $path = $directory.'/'.$id.'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));
        $file->move($directory, basename($path));

        $actor = $request->user();
        $import = MigrationImport::create([
            'id' => $id, 'provider' => 'cpanel', 'original_name' => $name,
            'archive_path' => $path, 'archive_size' => filesize($path), 'status' => 'inspecting',
            'created_by' => $actor->id, 'assigned_reseller_id' => $actor->hasRole('reseller') ? $actor->id : null,
        ]);
        InspectMigrationImportJob::dispatch($import->id)->afterCommit();

        return response()->json(['message' => 'Backup uploaded. Inspection is running in the background.', 'import' => $import], 202);
    }

    public function restore(Request $request, string $token, string $migrationImport): JsonResponse
    {
        $import = MigrationImport::visibleTo($request->user())->findOrFail($migrationImport);
        abort_unless($import->status === 'ready', 422, 'This archive is not ready.');
        $selection = $request->validate([
            'domains' => ['array'], 'domains.*' => ['string'], 'files' => ['array'], 'files.*' => ['string'],
            'databases' => ['array'], 'databases.*' => ['string'], 'full_account' => ['boolean'],
        ]);
        $selection += ['domains' => [], 'files' => [], 'databases' => [], 'full_account' => false];
        abort_if(! $selection['full_account'] && empty($selection['domains']) && empty($selection['files']) && empty($selection['databases']), 422, 'Select at least one item.');

        $import->update(['status' => 'restoring', 'last_error' => null]);
        RestoreMigrationImportJob::dispatch(
            $import->id,
            $selection,
            $request->user()->hasRole('reseller') ? $request->user()->id : null,
        )->afterCommit();

        return response()->json(['message' => 'Selected restore is running in the background.'], 202);
    }

    public function destroy(Request $request, string $token, string $migrationImport): JsonResponse
    {
        $import = MigrationImport::visibleTo($request->user())->findOrFail($migrationImport);
        if (is_file($import->archive_path)) {
            unlink($import->archive_path);
        }
        if ($import->provider === 'generic') {
            $sqlPath = data_get($import->inventory, 'sql_path');
            if (is_string($sqlPath) && is_file($sqlPath)) {
                unlink($sqlPath);
            }
            if (is_dir(dirname($import->archive_path))) {
                @rmdir(dirname($import->archive_path));
            }
        }
        $import->delete();

        return response()->json(['message' => 'Migration archive removed.']);
    }
}
