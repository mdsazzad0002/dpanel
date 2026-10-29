<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\Website;
use App\Services\Backup\BackupRestoreStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Restores one website archive from a backup run. This used to run inline in
 * the HTTP request, so a multi-GB archive held the request open for the whole
 * tar/cp/mysql run and any failure (or proxy/worker timeout) surfaced as a
 * bare 500. Now the request returns a restore_id at once and the page polls
 * BackupRestoreStatus, which reports the dRust step that is running or failed.
 */
class RestoreBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $restoreId,
        public string $archive,
        public ?int $userId = null,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(): void
    {
        BackupRestoreStatus::set($this->restoreId, ['stage' => 'running', 'step' => 'validating', 'percent' => 1, 'message' => 'Starting restore']);

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            $this->markFailed('validating', 'dRust backup API is not configured.');

            return;
        }

        File::ensureDirectoryExists(dirname(BackupRestoreStatus::progressPath($this->restoreId)));

        try {
            $response = Http::acceptJson()->asJson()->withToken($apiToken)
                ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
                ->post(rtrim($baseUrl, '/').'/api/v1/website/archive/restore', [
                    'zip_path' => $this->archive,
                    'progress_path' => BackupRestoreStatus::progressPath($this->restoreId),
                ]);
        } catch (Throwable $e) {
            $this->markFailed(null, 'Restore API request failed: '.$e->getMessage());

            return;
        }
        if (! $response->successful() || ! (bool) $response->json('success')) {
            $this->markFailed(null, (string) ($response->json('message') ?: 'Restore failed (dRust HTTP '.$response->status().').'));

            return;
        }

        BackupRestoreStatus::set($this->restoreId, ['stage' => 'running', 'step' => 'finalizing', 'percent' => 98, 'message' => 'Saving panel records']);
        BackupRestoreStatus::clearProgress($this->restoreId);

        try {
            $this->syncPanelRecords((array) $response->json('data.website', []));
        } catch (Throwable $e) {
            $this->markFailed('finalizing', 'Files and databases were restored, but panel records could not be saved: '.$e->getMessage());

            return;
        }

        BackupRestoreStatus::set($this->restoreId, [
            'stage' => 'completed',
            'step' => 'completed',
            'percent' => 100,
            'message' => 'Website files, databases and panel record restored from this version.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        // Reached when the worker kills the job (e.g. the 3600s timeout) — the
        // last step dRust reported is the one that was still running.
        $this->markFailed(null, 'Restore job stopped: '.($exception?->getMessage() ?: 'worker timeout or crash'));
    }

    private function markFailed(?string $step, string $message): void
    {
        $progress = BackupRestoreStatus::readProgress($this->restoreId) ?? [];
        $step ??= $progress['failed_stage'] ?? $progress['stage'] ?? null;
        if ($step === 'failed') {
            $step = null;
        }
        BackupRestoreStatus::set($this->restoreId, [
            'stage' => 'failed',
            'failed_step' => $step,
            'percent' => (int) ($progress['percent'] ?? 0),
            'message' => $message,
        ]);
        BackupRestoreStatus::clearProgress($this->restoreId);
    }

    private function syncPanelRecords(array $website): void
    {
        if (($website['domain'] ?? '') === '') {
            return;
        }
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
                'assigned_user_id' => $website['assigned_user_id'] ?? $this->userId,
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
                'assigned_user_id' => $website['assigned_user_id'] ?? $this->userId,
            ])->save();
        }
    }
}
