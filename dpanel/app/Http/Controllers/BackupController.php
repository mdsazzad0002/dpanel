<?php

namespace App\Http\Controllers;

use App\Jobs\QuickExportJob;
use App\Jobs\RunAccountBackupsJob;
use App\Models\DatabaseRequest;
use App\Models\Website;
use App\Services\Backup\QuickExportJobStatus;
use App\Services\Backup\WebsiteArchiver;
use App\Support\BackupSettings;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    private const STORAGE_DRIVERS = ['local', 'google_drive', 's3', 's3_compatible', 'sftp', 'custom'];

    public function __construct(private readonly BackupSettings $settings, private readonly WebsiteArchiver $archiver) {}

    public function index(): Response
    {
        $backupRoot = storage_path('app/backups');
        $state = $this->settings->read();

        return Inertia::render('Backups/Index', [
            'backupRoot' => $backupRoot,
            'retentionDays' => (int) $state['retention_days'],
            'backupSchedule' => [
                'enabled' => (bool) $state['schedule_enabled'],
                'time' => (string) $state['schedule_time'],
                'account_delay_seconds' => (int) $state['account_delay_seconds'],
            ],
            'storageDriver' => (string) $state['storage_driver'],
            'remoteUpload' => [
                'enabled' => (bool) $state['remote_upload_enabled'],
                'host' => (string) $state['remote_host'],
                'path' => (string) $state['remote_path'],
                'user' => (string) $state['remote_user'],
                'port' => (string) $state['remote_port'],
                'ssh_key_path' => (string) $state['remote_ssh_key_path'],
                'strict_host_checking' => (bool) $state['remote_strict_host_checking'],
                'ssh_path' => (string) $state['remote_ssh_path'],
                'scp_path' => (string) $state['remote_scp_path'],
            ],
            'scpStatus' => $this->scpStatus(),
            'websites' => Website::query()
                ->visibleTo(request()->user())
                ->whereNull('parent_id')
                ->whereIn('type', ['main', 'primary'])
                ->where(function ($query): void {
                    $query->whereNull('site_owner')->orWhere('site_owner', '!=', 'system');
                })
                ->where('project_root', '!=', base_path())
                ->orderBy('domain')
                ->get(['id', 'domain'])
                ->map(fn (Website $website): array => ['id' => (string) $website->id, 'domain' => (string) $website->domain])
                ->values(),
            'runs' => $this->listRuns($backupRoot),
        ]);
    }

    public function scp(): Response
    {
        $state = $this->settings->read();

        return Inertia::render('Backups/Scp', [
            'backupSchedule' => [
                'enabled' => (bool) $state['schedule_enabled'],
                'time' => (string) $state['schedule_time'],
                'retention_days' => (int) $state['retention_days'],
                'account_delay_seconds' => (int) $state['account_delay_seconds'],
            ],
            'storageDriver' => (string) $state['storage_driver'],
            'remoteUpload' => [
                'enabled' => (bool) $state['remote_upload_enabled'],
                'host' => (string) $state['remote_host'],
                'path' => (string) $state['remote_path'],
                'user' => (string) $state['remote_user'],
                'port' => (string) $state['remote_port'],
                'ssh_key_path' => (string) $state['remote_ssh_key_path'],
                'strict_host_checking' => (bool) $state['remote_strict_host_checking'],
                'ssh_path' => (string) $state['remote_ssh_path'],
                'scp_path' => (string) $state['remote_scp_path'],
            ],
            'scpStatus' => $this->scpStatus(),
        ]);
    }

    public function storageIndex(): Response
    {
        $state = $this->settings->read();

        return Inertia::render('Backups/Storage/Index', [
            'activeDriver' => (string) $state['storage_driver'],
        ]);
    }

    public function storageConfigure(string $token, string $driver): Response
    {
        abort_unless(in_array($driver, self::STORAGE_DRIVERS, true), 404);
        $state = $this->settings->read();

        return Inertia::render('Backups/Storage/Configure', [
            'driver' => $driver,
            'active' => $state['storage_driver'] === $driver,
            'config' => (array) (($state['storage_configs'] ?? [])[$driver] ?? []),
            'accountDelaySeconds' => (int) $state['account_delay_seconds'],
        ]);
    }

    public function updateStorage(Request $request, string $token, string $driver): RedirectResponse
    {
        abort_unless(in_array($driver, self::STORAGE_DRIVERS, true), 404);
        $validated = $request->validate([
            'activate' => ['required', 'boolean'],
            'path' => ['nullable', 'string', 'max:2000'],
            'endpoint' => ['nullable', 'string', 'max:2000'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:100'],
            'remote_name' => ['nullable', 'string', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'key_path' => ['nullable', 'string', 'max:2000'],
            'account_delay_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
        ]);

        $state = $this->settings->read();
        $configs = (array) ($state['storage_configs'] ?? []);
        $configs[$driver] = collect($validated)->except(['activate', 'account_delay_seconds'])->all();
        $state['storage_configs'] = $configs;
        $state['account_delay_seconds'] = $validated['account_delay_seconds'];
        if ($validated['activate']) {
            $state['storage_driver'] = $driver;
        }
        $this->settings->write($state);

        return redirect()->route('backups.storage.configure', ['driver' => $driver])
            ->with('success', 'Storage configuration saved'.($validated['activate'] ? ' and activated.' : '.'));
    }

    public function updateScpSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'remote_upload_enabled' => ['required', 'boolean'],
            'remote_host' => ['nullable', 'string', 'max:255'],
            'remote_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'remote_user' => ['nullable', 'string', 'max:120'],
            'remote_path' => ['nullable', 'string', 'max:2000'],
            'remote_ssh_key_path' => ['nullable', 'string', 'max:2000'],
            'remote_strict_host_checking' => ['required', 'boolean'],
            'remote_ssh_path' => ['required', 'string', 'max:200'],
            'remote_scp_path' => ['required', 'string', 'max:200'],
        ]);

        if ((bool) $validated['remote_upload_enabled'] && (
            trim((string) ($validated['remote_host'] ?? '')) === '' ||
            trim((string) ($validated['remote_user'] ?? '')) === '' ||
            trim((string) ($validated['remote_path'] ?? '')) === ''
        )) {
            return redirect()->route('backups.scp')
                ->with('error', 'Host, user and remote path are required when SCP upload is enabled.');
        }

        $this->settings->write(array_merge($this->settings->read(), $validated));

        return redirect()->route('backups.scp')->with('success', 'SCP backup settings saved.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'schedule_enabled' => ['required', 'boolean'],
            'schedule_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'storage_driver' => ['sometimes', 'required', 'in:local,google_drive,s3,s3_compatible,sftp,custom'],
            'account_delay_seconds' => ['sometimes', 'required', 'integer', 'min:0', 'max:3600'],
        ]);

        $this->settings->write(array_merge($this->settings->read(), $validated));

        return redirect()
            ->route('backups.scp')
            ->with('success', 'Backup settings saved.');
    }

    public function data(): JsonResponse
    {
        $runs = $this->listRuns(storage_path('app/backups'));

        return response()->json([
            'ok' => true,
            'runs' => $runs,
            'batch' => Cache::get('account-backup-batch:'.request()->user()->id),
        ]);
    }

    public function runNow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['required', 'in:all,website'],
            'content' => ['required', 'in:all,files,database'],
            'website_id' => ['nullable', 'string', 'required_if:filter,website'],
        ]);

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            return response()->json(['ok' => false, 'message' => 'dRust backup API is not configured.'], 503);
        }

        // php.ini's default max_execution_time (30s) would otherwise kill this
        // worker mid-mysqldump/zip on any non-trivial site — this call blocks on
        // dRust for as long as that same execution API timeout allows.
        set_time_limit((int) config('serverpanel.execution_api_upload_timeout', 3600));

        try {
            if ($validated['filter'] === 'website') {
                return $this->runWebsiteBackup($request, (string) $validated['website_id'], (string) $validated['content'], $baseUrl, $token);
            }

            $websites = $this->backupWebsites($request);
            if ($websites->isEmpty()) {
                return response()->json(['ok' => false, 'message' => 'No website accounts are available to back up.'], 422);
            }

            $batchId = (string) Str::uuid();
            $delay = (int) ($this->settings->read()['account_delay_seconds'] ?? 5);
            Cache::put('account-backup-batch:'.$request->user()->id, [
                'id' => $batchId, 'stage' => 'queued', 'completed' => 0,
                'total' => $websites->count(), 'message' => 'Account backups are queued and will run one at a time.',
            ], now()->addDay());
            RunAccountBackupsJob::dispatch(
                $batchId,
                (int) $request->user()->id,
                $websites->pluck('id')->map(fn ($id) => (string) $id)->all(),
                (string) $validated['content'],
                $delay,
            );

            return response()->json([
                'ok' => true, 'queued' => true, 'batch_id' => $batchId,
                'message' => $websites->count().' account backup(s) queued. They will run one at a time.',
            ], 202);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'dRust backup API request failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Kicks off the export on the 'heavy' queue and returns immediately with an
     * export_id — the zip/mysqldump can take minutes on a large site, and doing
     * that inline blocked the request (and the browser tab) for just as long.
     * QuickExport.vue polls quickExportStatus() every 5s for this id while the
     * page stays open; if the user navigates away, the same job still raises a
     * Notification on completion/failure so the export isn't lost track of.
     */
    public function quickExport(Request $request, string $token, string $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:files,database'],
            'database_id' => ['nullable', 'string', 'required_if:type,database'],
        ]);

        $website = Website::query()->visibleTo($request->user())->find($id);
        if (! $website instanceof Website) {
            return response()->json(['ok' => false, 'message' => 'Website account not found.'], 404);
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            return response()->json(['ok' => false, 'message' => 'dRust backup API is not configured.'], 503);
        }

        $content = (string) $validated['type'];
        if ($content === 'database') {
            $exists = DatabaseRequest::query()
                ->visibleTo($request->user())
                ->where('domain', $website->domain)
                ->where('status', 'active')
                ->where('id', (string) $validated['database_id'])
                ->exists();
            if (! $exists) {
                return response()->json(['ok' => false, 'message' => 'Selected database was not found.'], 404);
            }
        }

        $exportId = (string) Str::uuid();
        QuickExportJobStatus::set($exportId, ['stage' => 'queued']);

        QuickExportJob::dispatch($exportId, (string) $id, (int) $request->user()->id, $content, $validated['database_id'] ?? null);

        return response()->json(['ok' => true, 'export_id' => $exportId]);
    }

    public function quickExportStatus(Request $request, string $token, string $id, string $exportId): JsonResponse
    {
        $website = Website::query()->visibleTo($request->user())->find($id);
        if (! $website instanceof Website) {
            return response()->json(['ok' => false, 'message' => 'Website account not found.'], 404);
        }

        $status = QuickExportJobStatus::get($exportId);
        if ($status === null) {
            return response()->json(['ok' => false, 'message' => 'Export job not found.'], 404);
        }

        return response()->json(['ok' => true, ...$status]);
    }

    public function quickExportDownload(Request $request, string $downloadToken): BinaryFileResponse|JsonResponse
    {
        $cacheKey = 'quick-export-download:'.$downloadToken;
        $entry = Cache::get($cacheKey);

        if (! is_array($entry) || ! is_file($entry['path'])) {
            return response()->json(['ok' => false, 'message' => 'This download link has expired.'], 404);
        }

        // No forget/deleteFileAfterSend here — the link and file both stay usable for
        // the full TTL so a missed auto-download or a manual retry keeps working; the
        // queued DeleteQuickExportFileJob is solely responsible for cleanup.
        return response()->download($entry['path'], $entry['file_name']);
    }

    private function runWebsiteBackup(Request $request, string $websiteId, string $content, string $baseUrl, string $token): JsonResponse
    {
        $website = $this->backupWebsites($request)->firstWhere('id', $websiteId);
        if (! $website instanceof Website) {
            return response()->json(['ok' => false, 'message' => 'Website account not found.'], 404);
        }
        $result = $this->createWebsiteArchive($request, $website, $content, $baseUrl, $token);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['ok'] ? 'Website backup completed: '.$website->domain : $result['message'],
            'runs' => $result['ok'] ? $this->listRuns(storage_path('app/backups')) : null,
        ], $result['ok'] ? 201 : 500);
    }

    /** @return array{ok:bool,message:string} */
    private function createWebsiteArchive(Request $request, Website $website, string $content, string $baseUrl, string $token, ?string $targetPath = null, ?string $onlyDatabaseId = null): array
    {
        return $this->archiver->archive($request->user(), $website, $content, $baseUrl, $token, $targetPath, $onlyDatabaseId);
    }

    private function backupWebsites(Request $request)
    {
        return Website::query()->visibleTo($request->user())
            ->whereNull('parent_id')->whereIn('type', ['main', 'primary'])
            ->where(fn ($query) => $query->whereNull('site_owner')->orWhere('site_owner', '!=', 'system'))
            ->where('project_root', '!=', base_path())->orderBy('domain')->get();
    }

    public function download(string $token, string $run, string $file): BinaryFileResponse
    {
        return $this->downloadFile($run, $file);
    }

    public function downloadFromQuery(Request $request, string $token, string $run): BinaryFileResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\/]+$/'],
        ]);

        return $this->downloadFile($run, (string) $validated['file']);
    }

    public function downloadEncoded(string $token, string $run, string $encoded): BinaryFileResponse
    {
        $normalized = strtr($encoded, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $file = base64_decode($normalized, true);
        abort_if(! is_string($file) || $file === '', 404, 'Invalid backup file token.');

        return $this->downloadFile($run, $file);
    }

    private function downloadFile(string $run, string $file): BinaryFileResponse
    {
        $runPath = $this->resolveRunPath($run);
        abort_if($runPath === null, 404, 'Backup run not found.');

        if (str_contains($file, '/') || str_contains($file, '\\')) {
            abort(404, 'Invalid file name.');
        }

        $target = realpath($runPath.DIRECTORY_SEPARATOR.$file);
        if (! is_string($target) || $target === '' || ! is_file($target) || dirname($target) !== $runPath) {
            abort(404, 'Backup file not found.');
        }

        return response()->download($target, basename($target));
    }

    public function destroyRun(string $token, string $run): JsonResponse
    {
        $runPath = $this->resolveRunPath($run);
        if ($runPath === null) {
            return response()->json(['ok' => false, 'message' => 'Backup run not found.'], 404);
        }

        try {
            $response = Http::acceptJson()->asJson()
                ->withToken((string) config('serverpanel.execution_api_token', ''))
                ->timeout(120)
                ->post(rtrim((string) config('serverpanel.execution_api_base_url', ''), '/').'/api/v1/backup/delete', [
                    'run_path' => $runPath,
                ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'dRust delete request failed: '.$e->getMessage()], 500);
        }
        if (! $response->successful() || ! (bool) $response->json('success')) {
            return response()->json(['ok' => false, 'message' => (string) ($response->json('message') ?: 'dRust could not delete the backup run.')], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Backup run deleted.', 'runs' => $this->listRuns(storage_path('app/backups'))]);
    }

    public function restore(Request $request, string $token, string $run, string $encoded): JsonResponse
    {
        $file = $this->decodeFileToken($encoded);
        $runPath = $this->resolveRunPath($run);
        if ($runPath === null || $file === null || ! str_ends_with(strtolower($file), '.tar.gz')) {
            return response()->json(['ok' => false, 'message' => 'Restorable website archive not found.'], 404);
        }
        $archive = realpath($runPath.DIRECTORY_SEPARATOR.$file);
        if (! is_string($archive) || dirname($archive) !== $runPath) {
            return response()->json(['ok' => false, 'message' => 'Invalid backup archive path.'], 422);
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        // php.ini's default max_execution_time (30s) would otherwise kill this
        // worker mid-restore on any non-trivial archive — this call blocks on
        // dRust for as long as that same execution API timeout allows.
        set_time_limit((int) config('serverpanel.execution_api_upload_timeout', 3600));
        try {
            $response = Http::acceptJson()->asJson()->withToken($apiToken)
                ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
                ->post(rtrim($baseUrl, '/').'/api/v1/website/archive/restore', ['zip_path' => $archive]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Restore API request failed: '.$e->getMessage()], 500);
        }
        if (! $response->successful() || ! (bool) $response->json('success')) {
            return response()->json(['ok' => false, 'message' => (string) ($response->json('message') ?: 'Restore failed.')], 500);
        }

        $website = (array) $response->json('data.website', []);
        if (($website['domain'] ?? '') !== '') {
            Website::query()->updateOrCreate(
                ['domain' => (string) $website['domain'], 'parent_id' => null],
                array_filter([
                    'id' => (string) ($website['id'] ?? str()->uuid()),
                    'hostname' => (string) $website['domain'],
                    'root_path' => $website['root_path'] ?? null,
                    'project_root' => $website['project_root'] ?? null,
                    'start_directory' => $website['start_directory'] ?? null,
                    'site_owner' => $website['site_owner'] ?? null,
                    'php_version' => $website['php_version'] ?? null,
                    'status' => $website['status'] ?? 'active',
                    'type' => $website['type'] ?? 'main',
                    'enable_ssl' => (bool) ($website['enable_ssl'] ?? false),
                    'assigned_user_id' => $website['assigned_user_id'] ?? $request->user()?->id,
                    'assigned_reseller_id' => $website['assigned_reseller_id'] ?? null,
                ], static fn ($value) => $value !== null)
            );

            foreach ((array) ($website['databases'] ?? []) as $database) {
                if (! is_array($database) || trim((string) ($database['name'] ?? '')) === '') {
                    continue;
                }
                $databaseRecord = DatabaseRequest::query()->firstOrNew(['database_name' => (string) $database['name']]);
                if (! $databaseRecord->exists) {
                    $databaseRecord->id = (string) str()->uuid();
                }
                $databaseRecord->fill([
                    'domain' => (string) $website['domain'],
                    'database_user' => (string) ($database['user'] ?? ''),
                    'database_password' => (string) ($database['password'] ?? ''),
                    'database_host' => (string) ($database['host'] ?? '127.0.0.1'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'status' => 'active',
                    'assigned_user_id' => $website['assigned_user_id'] ?? $request->user()?->id,
                ])->save();
            }
        }

        return response()->json(['ok' => true, 'message' => 'Website files, databases and panel record restored from this version.']);
    }

    private function decodeFileToken(string $encoded): ?string
    {
        $normalized = strtr($encoded, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $file = base64_decode($normalized, true);

        return is_string($file) && $file !== '' && ! str_contains($file, '/') && ! str_contains($file, '\\') ? $file : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listRuns(string $backupRoot): array
    {
        if (! File::isDirectory($backupRoot)) {
            return [];
        }

        return collect(File::directories($backupRoot))
            ->map(function (string $directory): ?array {
                $realDirectory = realpath($directory);
                if (! is_string($realDirectory) || $realDirectory === '' || ! is_dir($realDirectory)) {
                    return null;
                }

                $runName = basename($realDirectory);
                $createdAt = null;
                try {
                    $createdAt = Carbon::createFromFormat('Ymd_His', $runName)->toDateTimeString();
                } catch (\Throwable) {
                    $createdAt = null;
                }

                $files = collect(File::files($realDirectory))
                    ->reject(fn (\SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.tar.gz.json'))
                    ->map(function (\SplFileInfo $file): array {
                        $realPath = $file->getRealPath();
                        $metadata = is_string($realPath) ? $this->websiteArchiveMetadata($realPath) : null;

                        return [
                            'name' => $file->getFilename(),
                            'label' => $metadata['label'] ?? $file->getFilename(),
                            'domain' => $metadata['domain'] ?? null,
                            'owner' => $metadata['owner'] ?? null,
                            'restorable' => $metadata !== null,
                            'size_bytes' => is_string($realPath) ? (int) @filesize($realPath) : 0,
                            'updated_at' => Carbon::createFromTimestamp($file->getMTime())->toDateTimeString(),
                        ];
                    })
                    ->sortBy('name')
                    ->values()
                    ->all();

                return [
                    'name' => $runName,
                    'created_at' => $createdAt,
                    'file_count' => count($files),
                    'total_size_bytes' => collect($files)->sum('size_bytes'),
                    'files' => $files,
                ];
            })
            ->filter()
            ->sortByDesc('name')
            ->values()
            ->all();
    }

    /** @return array{label:string,domain:string,owner:string}|null */
    private function websiteArchiveMetadata(string $path): ?array
    {
        if (! str_ends_with(strtolower($path), '.tar.gz')) {
            return null;
        }
        $sidecar = $path.'.json';
        $raw = File::isFile($sidecar) ? File::get($sidecar) : null;
        if (! is_string($raw)) {
            return null;
        }
        try {
            $website = (array) (json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['website'] ?? []);
        } catch (\Throwable) {
            return null;
        }
        $domain = trim((string) ($website['domain'] ?? ''));
        if ($domain === '') {
            return null;
        }
        $owner = trim((string) ($website['site_owner'] ?? 'account'));

        return ['label' => $domain.' account backup', 'domain' => $domain, 'owner' => $owner];
    }

    private function resolveRunPath(string $run): ?string
    {
        if (preg_match('/^\d{8}_\d{6}$/', $run) !== 1) {
            return null;
        }

        $backupRoot = storage_path('app/backups');
        $root = realpath($backupRoot);
        if (! is_string($root) || $root === '' || ! is_dir($root)) {
            return null;
        }

        $runPath = realpath($root.DIRECTORY_SEPARATOR.$run);
        if (! is_string($runPath) || $runPath === '' || ! is_dir($runPath)) {
            return null;
        }

        return str_starts_with($runPath, $root.DIRECTORY_SEPARATOR) ? $runPath : null;
    }

    /** @return array{status:string,run:?string,message:?string,updated_at:?string} */
    private function scpStatus(): array
    {
        $path = storage_path('app/backup-status/scp.json');
        if (! File::isFile($path)) {
            return ['status' => 'never', 'run' => null, 'message' => null, 'updated_at' => null];
        }

        try {
            $data = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);

            return [
                'status' => in_array($data['status'] ?? '', ['success', 'failed'], true) ? $data['status'] : 'never',
                'run' => isset($data['run']) ? (string) $data['run'] : null,
                'message' => isset($data['message']) ? (string) $data['message'] : null,
                'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            ];
        } catch (\Throwable) {
            return ['status' => 'never', 'run' => null, 'message' => null, 'updated_at' => null];
        }
    }
}
