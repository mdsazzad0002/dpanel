<?php

namespace App\Http\Controllers;

use App\Jobs\InspectMigrationImportJob;
use App\Jobs\RestoreGenericWebsiteJob;
use App\Jobs\RestoreMigrationImportJob;
use App\Models\DatabaseRequest;
use App\Models\MigrationImport;
use App\Services\Migration\CyberPanelSshInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MigrationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Migrations/Index', [
            'provider' => null,
            'imports' => [],
        ]);
    }

    public function cpanel(Request $request): Response
    {
        return Inertia::render('Migrations/Index', [
            'provider' => 'cpanel',
            'imports' => MigrationImport::visibleTo($request->user())
                ->where('provider', 'cpanel')
                ->latest()
                ->get(),
        ]);
    }

    public function cyberpanelSsh(): Response
    {
        return Inertia::render('Migrations/Index', [
            'provider' => 'cyberpanel-ssh',
            'imports' => [],
        ]);
    }

    public function inspectCyberpanelSsh(Request $request, CyberPanelSshInventoryService $inventory): JsonResponse
    {
        $credentials = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'],
            'auth_type' => ['required', 'in:password,key'],
            'password' => ['nullable', 'required_if:auth_type,password', 'string', 'max:1000'],
            'private_key' => ['nullable', 'required_if:auth_type,key', 'string', 'max:32768'],
            'key_passphrase' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            return response()->json(['inventory' => $inventory->discover($credentials)]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function websiteImport(Request $request, string $token, string $id): Response
    {
        $website = \App\Models\Website::visibleTo($request->user())->findOrFail($id);
        $database = DatabaseRequest::query()
            ->visibleTo($request->user())
            ->whereRaw('LOWER(domain) = ?', [strtolower((string) $website->domain)])
            ->where('status', 'active')
            ->latest()
            ->first();

        return Inertia::render('Migrations/Index', [
            'provider' => 'generic',
            'website' => $website,
            'panelToken' => $token,
            'databaseConnection' => ['available' => $database !== null, 'database_name' => $database?->database_name],
            'imports' => MigrationImport::visibleTo($request->user())->where('provider', 'generic')->where('inventory->domain', $website->domain)->latest()->get()
                ->each(function (MigrationImport $import): void {
                    $inventory = (array) $import->inventory;
                    unset($inventory['database_password']);
                    $import->setAttribute('inventory', $inventory);
                }),
        ]);
    }

    public function storeWebsiteImport(Request $request, string $token, string $id): JsonResponse
    {
        $website = \App\Models\Website::visibleTo($request->user())->findOrFail($id);
        $data = $request->validate([
            'archive_name' => ['required', 'string', 'max:255'], 'archive_size' => ['required', 'integer', 'between:1,5368709120'],
            'database_name_file' => ['nullable', 'string', 'max:255'], 'database_size' => ['nullable', 'required_with:database_name_file', 'integer', 'between:1,2147483648'],
            'overwrite_database' => ['nullable', 'boolean'],
        ]);
        $archiveName = basename((string) $data['archive_name']);
        abort_unless(preg_match('/\.(?:zip|tar\.gz|tgz)$/i', $archiveName), 422, 'Upload a .zip, .tar.gz, or .tgz website archive.');
        $databaseFileName = isset($data['database_name_file']) ? basename((string) $data['database_name_file']) : null;
        abort_if($databaseFileName !== null && preg_match('/\.sql$/i', $databaseFileName) !== 1, 422, 'Database dump must be a .sql file.');

        $database = null;
        if ($databaseFileName !== null) {
            $database = DatabaseRequest::query()
                ->visibleTo($request->user())
                ->whereRaw('LOWER(domain) = ?', [strtolower((string) $website->domain)])
                ->where('status', 'active')
                ->latest()
                ->first();
            abort_if($database !== null && ! (bool) ($data['overwrite_database'] ?? false), 409, 'Confirm database overwrite before uploading the SQL dump.');
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
            $prefix = $owner.'_';
            $nameSuffix = $domainPrefix.'_db';
            $userSuffix = $domainPrefix.'_user';
            $database = new DatabaseRequest([
                'database_name' => substr($prefix, 0, 64 - strlen($nameSuffix)).$nameSuffix,
                'database_user' => substr($prefix, 0, 64 - strlen($userSuffix)).$userSuffix,
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

    public function store(Request $request): JsonResponse
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

    public function restore(Request $request, string $migrationImport): JsonResponse
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

    public function destroy(Request $request, string $migrationImport): JsonResponse
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
