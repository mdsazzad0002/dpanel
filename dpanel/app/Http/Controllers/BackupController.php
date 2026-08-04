<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest;
use App\Models\Website;
use App\Support\BackupSettings;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(private readonly BackupSettings $settings) {}

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
            ],
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
            ],
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
        ]);

        $this->settings->write(array_merge($this->settings->read(), $validated));

        return redirect()
            ->route('backups.scp')
            ->with('success', 'Backup settings saved.');
    }

    public function runNow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'filter' => ['required', 'in:all,website'],
            'content' => ['required', 'in:all,files,database'],
            'website_id' => ['nullable', 'string', 'required_if:filter,website'],
        ]);

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            return redirect()
                ->route('backups.index')
                ->with('error', 'dRust backup API is not configured.');
        }

        try {
            if ($validated['filter'] === 'website') {
                return $this->runWebsiteBackup($request, (string) $validated['website_id'], (string) $validated['content'], $baseUrl, $token);
            }

            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
                ->post(rtrim($baseUrl, '/').'/api/v1/backup/run', [
                    'only' => $validated['content'] === 'database' ? 'db' : (string) $validated['content'],
                ]);
        } catch (\Throwable $e) {
            return redirect()
                ->route('backups.index')
                ->with('error', 'dRust backup API request failed: '.$e->getMessage());
        }

        if (! $response->successful() || ! (bool) $response->json('success')) {
            $message = trim((string) ($response->json('message') ?: $response->body()));

            return redirect()
                ->route('backups.index')
                ->with('error', $message !== '' ? $message : 'dRust backup failed.');
        }

        return redirect()
            ->route('backups.index')
            ->with('success', 'Backup completed successfully through dRust.');
    }

    private function runWebsiteBackup(Request $request, string $websiteId, string $content, string $baseUrl, string $token): RedirectResponse
    {
        $website = Website::query()
            ->visibleTo($request->user())
            ->whereNull('parent_id')
            ->whereIn('type', ['main', 'primary'])
            ->where(function ($query): void {
                $query->whereNull('site_owner')->orWhere('site_owner', '!=', 'system');
            })
            ->where('project_root', '!=', base_path())
            ->findOrFail($websiteId);
        $timestamp = now()->format('Ymd_His');
        $runDirectory = storage_path('app/backups/'.$timestamp);
        File::ensureDirectoryExists($runDirectory);
        $safeDomain = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $website->domain) ?: 'website';
        $zipPath = $runDirectory.DIRECTORY_SEPARATOR.'website-'.$safeDomain.'.zip';
        $databases = DatabaseRequest::query()
            ->visibleTo($request->user())
            ->where('domain', $website->domain)
            ->where('status', 'approved')
            ->get()
            ->map(fn (DatabaseRequest $database): array => [
                'name' => (string) $database->database_name,
                'user' => (string) $database->database_user,
                'password' => (string) $database->database_password,
                'host' => (string) ($database->database_host ?: '127.0.0.1'),
            ])->values()->all();

        if ($content === 'database' && $databases === []) {
            File::deleteDirectory($runDirectory);

            return redirect()->route('backups.index')->with('error', 'No approved database is linked to this main domain.');
        }

        $response = Http::acceptJson()->asJson()->withToken($token)
            ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
            ->post(rtrim($baseUrl, '/').'/api/v1/website/archive', [
                'zip_path' => $zipPath,
                'website' => [
                    'id' => (string) $website->id,
                    'domain' => (string) $website->domain,
                    'root_path' => (string) $website->root_path,
                    'project_root' => (string) $website->project_root,
                    'start_directory' => $website->start_directory,
                    'site_owner' => $website->site_owner,
                    'php_version' => $website->php_version,
                    'status' => $website->status,
                    'type_field' => $website->type,
                    'enable_ssl' => (bool) $website->enable_ssl,
                    'content' => $content,
                    'database_requests' => $databases,
                ],
            ]);

        if (! $response->successful() || ! (bool) $response->json('success')) {
            File::deleteDirectory($runDirectory);

            return redirect()->route('backups.index')
                ->with('error', (string) ($response->json('message') ?: 'Website backup failed.'));
        }

        return redirect()->route('backups.index')
            ->with('success', 'Website backup completed through dRust: '.$website->domain);
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

    public function destroyRun(string $token, string $run): RedirectResponse
    {
        $runPath = $this->resolveRunPath($run);
        if ($runPath === null) {
            return redirect()
                ->route('backups.index')
                ->with('error', 'Backup run not found.');
        }

        File::deleteDirectory($runPath);

        return redirect()
            ->route('backups.index')
            ->with('success', 'Backup run deleted.');
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
                    ->map(function (\SplFileInfo $file): array {
                        $realPath = $file->getRealPath();

                        return [
                            'name' => $file->getFilename(),
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
