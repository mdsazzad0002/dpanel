<?php

namespace App\Http\Controllers;

use App\Support\BackupSettings;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(private readonly BackupSettings $settings)
    {
    }

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
            'runs' => $this->listRuns($backupRoot),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'schedule_enabled' => ['required', 'boolean'],
            'schedule_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
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

        if ((bool) $validated['remote_upload_enabled']) {
            if (trim((string) ($validated['remote_host'] ?? '')) === '' ||
                trim((string) ($validated['remote_user'] ?? '')) === '' ||
                trim((string) ($validated['remote_path'] ?? '')) === '') {
                return redirect()
                    ->route('backups.index')
                    ->with('error', 'Remote upload is enabled, so host, user and path are required.');
            }
        }

        $this->settings->write($validated);

        return redirect()
            ->route('backups.index')
            ->with('success', 'Backup settings saved.');
    }

    public function runNow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'only' => ['required', 'in:all,db,files'],
        ]);

        $only = (string) $validated['only'];
        $result = Process::path(base_path())
            ->timeout(1200)
            ->run([PHP_BINARY, 'artisan', 'serverpanel:backup', '--only='.$only]);

        if (! $result->successful()) {
            $output = trim($result->errorOutput()."\n".$result->output());
            $message = $output !== '' ? $output : 'Backup command failed.';

            return redirect()
                ->route('backups.index')
                ->with('error', $message);
        }

        return redirect()
            ->route('backups.index')
            ->with('success', 'Backup completed successfully.');
    }

    public function download(string $run, string $file): BinaryFileResponse
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

    public function destroyRun(string $run): RedirectResponse
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

}
