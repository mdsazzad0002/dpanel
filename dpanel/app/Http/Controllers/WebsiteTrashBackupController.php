<?php

namespace App\Http\Controllers;

use App\Models\WebsiteTrashBackup;
use App\Models\CronJob;
use App\Support\BackupSettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WebsiteTrashBackupController extends Controller
{
    public function __construct(private readonly BackupSettings $settings)
    {
    }

    public function index(Request $request): Response
    {
        $backups = WebsiteTrashBackup::query()
            ->visibleTo($request->user())
            ->latest()
            ->get()
            ->map(fn (WebsiteTrashBackup $backup): array => [
                'id' => (string) $backup->id,
                'website_id' => (string) $backup->website_id,
                'domain' => (string) $backup->domain,
                'file_name' => (string) $backup->file_name,
                'file_size' => (int) $backup->file_size,
                'available' => $this->validArchivePath($backup) !== null,
                'created_at' => $backup->created_at?->toDateTimeString(),
            ]);

        $settings = $this->settings->read();

        return Inertia::render('TrashBackups/Index', [
            'backups' => $backups,
            'retention' => [
                'enabled' => (bool) $settings['trash_retention_enabled'],
                'days' => (int) $settings['trash_retention_days'],
                'time' => (string) $settings['trash_retention_time'],
            ],
            'canManageRetention' => (bool) ($request->user()?->hasAnyRole(['admin', 'reseller']) ?? false),
        ]);
    }

    public function updateRetention(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'time' => ['required', 'date_format:H:i'],
        ]);
        $settings = $this->settings->read();
        $settings['trash_retention_enabled'] = (bool) $validated['enabled'];
        $settings['trash_retention_days'] = (int) $validated['days'];
        $settings['trash_retention_time'] = (string) $validated['time'];

        try {
            $this->syncRetentionCron($settings);
        } catch (\Throwable $e) {
            return back()->with('error', 'Trash Backup schedule update failed. '.$e->getMessage());
        }
        $this->settings->write($settings);

        return back()->with('success', (bool) $validated['enabled']
            ? 'Trash Backup auto cleanup updated.'
            : 'Trash Backup auto cleanup disabled.');
    }

    /** @param array<string, mixed> $settings */
    private function syncRetentionCron(array $settings): void
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        [$hour, $minute] = array_map('intval', explode(':', (string) $settings['trash_retention_time']));
        $days = max(1, (int) $settings['trash_retention_days']);

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('serverpanel.execution_api_timeout', 60))
            ->withToken($token)
            ->post(rtrim($baseUrl, '/').'/api/v1/cron-job', [
                'action' => 'upsert',
                'id' => 'trash-backups-prune',
                'user' => 'root',
                'expression' => "{$minute} {$hour} * * *",
                'command' => 'cd '.base_path().' && '.PHP_BINARY.' artisan serverpanel:trash-backups-prune --days='.$days.' >> /dev/null 2>&1',
                'enabled' => (bool) $settings['trash_retention_enabled'],
            ]);
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Rust cron API failed.'));
        }

        CronJob::query()->updateOrCreate(
            ['id' => 'trash-backups-prune'],
            [
                'website_id' => '1',
                'domain' => 'dpanel.localhost',
                'name' => 'Trash Backup Cleanup',
                'expression' => "{$minute} {$hour} * * *",
                'command' => 'cd '.base_path().' && '.PHP_BINARY.' artisan serverpanel:trash-backups-prune --days='.$days.' >> /dev/null 2>&1',
                'status' => (bool) $settings['trash_retention_enabled'] ? 'active' : 'disabled',
                'description' => 'Automatically removes expired website trash backups.',
            ],
        );
    }

    public function download(Request $request, string $token, string $id): BinaryFileResponse|JsonResponse
    {
        $backup = WebsiteTrashBackup::query()
            ->visibleTo($request->user())
            ->find($id);
        if ($backup === null) {
            return response()->json(['message' => 'Trash backup was not found or is not accessible.'], 404);
        }

        $archive = $this->validArchivePath($backup);
        if ($archive === null) {
            return response()->json(['message' => 'Trash backup file is not available.'], 404);
        }

        return response()->download($archive, (string) $backup->file_name, [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function validArchivePath(WebsiteTrashBackup $backup): ?string
    {
        $root = realpath(storage_path('app/website-trash'));
        $archive = realpath((string) $backup->file_path);
        if (! is_string($root) || ! is_string($archive) || ! is_file($archive)) {
            return null;
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($archive, $prefix) ? $archive : null;
    }
}
