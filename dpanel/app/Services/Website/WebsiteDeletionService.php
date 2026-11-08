<?php

namespace App\Services\Website;

use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Website;
use App\Models\WebsiteTrashBackup;
use App\Services\Cron\CronSystemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deletes a primary website: its files are archived to the trash first, then
 * its cron jobs, queue workers, directory and (when no other site shares it)
 * system user go. Each step that fails puts the cron jobs back and stops.
 */
class WebsiteDeletionService
{
    public function __construct(private readonly CronSystemService $cron) {}

    /**
     * @return list<string> what was cleaned up, for the success message
     *
     * @throws WebsiteDeletionFailed
     */
    public function delete(Website $website, ?int $deletedBy): array
    {
        $id = (string) $website->id;
        $domain = strtolower(trim((string) ($website->domain ?? '')));

        $databaseRequests = DatabaseRequest::query()
            ->where('domain', $domain)
            ->get(['id', 'domain', 'database_name', 'database_user', 'database_password', 'database_host', 'charset', 'collation', 'status', 'assigned_user_id'])
            ->map(fn (DatabaseRequest $item): array => [
                'id' => (string) $item->id,
                'domain' => (string) $item->domain,
                'name' => (string) $item->database_name,
                'user' => (string) $item->database_user,
                'password' => (string) $item->database_password,
                'host' => (string) ($item->database_host ?: '127.0.0.1'),
                'charset' => (string) $item->charset,
                'collation' => (string) $item->collation,
                'status' => (string) $item->status,
                'assigned_user_id' => $item->assigned_user_id,
            ])
            ->all();
        $cronJobModels = CronJob::query()
            ->where('website_id', $id)
            ->get(['id', 'website_id', 'domain', 'name', 'expression', 'command', 'status', 'description']);
        $cronJobs = $cronJobModels->map(fn (CronJob $job): array => [
            'id' => (string) $job->id,
            'website_id' => (string) $job->website_id,
            'domain' => (string) ($job->domain ?? ''),
            'name' => (string) ($job->name ?? ''),
            'expression' => (string) ($job->expression ?? ''),
            'command' => (string) ($job->command ?? ''),
            'status' => (string) ($job->status ?? ''),
            'description' => (string) ($job->description ?? ''),
        ])
            ->all();
        $cleanupMessages = [];

        try {
            $archive = $this->archiveWebsiteViaDrust($website, $databaseRequests, $cronJobs);
            $cleanupMessages[] = 'Website files archived to '.$archive['zip_name'].'.';
        } catch (\Throwable $e) {
            Log::error('Website trash archive failed', [
                'website_id' => $id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            throw new WebsiteDeletionFailed('Website archive failed.', 'archive', $e);
        }

        $removedCronJobs = collect();
        try {
            foreach ($cronJobModels as $cronJob) {
                $this->cron->delete((string) $cronJob->id);
                $removedCronJobs->push($cronJob);
            }
        } catch (\Throwable $e) {
            $this->restoreSystemCronJobs($removedCronJobs, $website);
            Log::error('Website system cron cleanup failed', [
                'website_id' => $id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            throw new WebsiteDeletionFailed('Website cron cleanup failed.', 'cron_jobs', $e);
        }

        $this->removeQueueWorkers($website);

        try {
            $ownerIsShared = Website::query()
                ->where('site_owner', (string) $website->site_owner)
                ->whereKeyNot($id)
                ->exists();
            $this->deleteWebsiteViaDrust($website, ! $ownerIsShared);
            $cleanupMessages[] = $ownerIsShared
                ? 'Website directory removed.'
                : 'Website directory and system user removed.';
        } catch (\Throwable $e) {
            $this->restoreSystemCronJobs($removedCronJobs, $website);

            throw new WebsiteDeletionFailed('Website system cleanup failed.', 'system_cleanup', $e);
        }

        try {
            DB::transaction(function () use ($deletedBy, $website, $domain, $id, $archive, $databaseRequests, $cronJobs): void {
                WebsiteTrashBackup::query()->create([
                    'id' => (string) str()->uuid(),
                    'website_id' => (string) $website->id,
                    'domain' => $domain,
                    'file_name' => (string) $archive['zip_name'],
                    'file_path' => (string) $archive['zip_path'],
                    'file_size' => (int) (@filesize((string) $archive['zip_path']) ?: 0),
                    'metadata' => [
                        'website' => $website->toArray(),
                        'database_requests' => $databaseRequests,
                        'cron_jobs' => $cronJobs,
                    ],
                    'assigned_user_id' => $website->assigned_user_id,
                    'assigned_reseller_id' => $website->assigned_reseller_id,
                    'deleted_by' => $deletedBy,
                ]);

                if ($domain !== '') {
                    DatabaseRequest::query()->where('domain', $domain)->delete();
                }

                CronJob::query()->where('website_id', $id)->delete();
                $website->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Website destroy failed', [
                'website_id' => $id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            $this->restoreSystemCronJobs($cronJobModels, $website);

            throw new WebsiteDeletionFailed('Website destroy failed.', 'destroy', $e);
        }

        return $cleanupMessages;
    }

    private function restoreSystemCronJobs(iterable $cronJobs, Website $website): void
    {
        foreach ($cronJobs as $cronJob) {
            try {
                $this->cron->sync($cronJob, $website);
            } catch (\Throwable $e) {
                Log::critical('System cron rollback failed', [
                    'website_id' => (string) $website->id,
                    'cron_job_id' => (string) $cronJob->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function archiveWebsiteViaDrust(Website $website, array $databaseRequests, array $cronJobs): array
    {
        $drustUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($drustUrl === '') {
            throw new \RuntimeException('drust archive API is not configured.');
        }

        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token === '') {
            throw new \RuntimeException('drust archive API token is missing.');
        }

        $trashDir = storage_path('app/website-trash');
        if (! is_dir($trashDir) && ! @mkdir($trashDir, 0775, true) && ! is_dir($trashDir)) {
            throw new \RuntimeException('Unable to prepare website trash directory.');
        }

        $zipName = sprintf(
            'deleted-%s-%s.zip',
            $this->sanitizeFilename((string) ($website->domain ?? 'site')),
            $this->sanitizeFilename((string) ($website->id ?? 'unknown'))
        );
        $zipPath = rtrim($trashDir, '/').'/'.$zipName;

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
            ->withToken($token)
            ->post(rtrim($drustUrl, '/').'/api/v1/website/archive', [
                'zip_path' => $zipPath,
                'website' => [
                    'id' => (string) $website->id,
                    'domain' => (string) ($website->domain ?? ''),
                    'root_path' => (string) ($website->root_path ?? ''),
                    'project_root' => (string) ($website->project_root ?? ''),
                    'start_directory' => (string) ($website->start_directory ?? ''),
                    'site_owner' => (string) ($website->site_owner ?? ''),
                    'php_version' => (string) ($website->php_version ?? ''),
                    'status' => (string) ($website->status ?? ''),
                    'type_field' => (string) ($website->type ?? ''),
                    'enable_ssl' => (bool) ($website->enable_ssl ?? false),
                    'database_requests' => $databaseRequests,
                    'cron_jobs' => $cronJobs,
                ],
            ]);

        if (! $response->ok()) {
            $error = $response->json('message');
            throw new \RuntimeException(
                is_string($error) && trim($error) !== ''
                    ? trim($error)
                    : (trim((string) $response->body()) ?: 'Website archive failed.')
            );
        }

        $json = $response->json();
        if (! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? 'Website archive failed.'));
        }

        return [
            'zip_path' => $zipPath,
            'zip_name' => $zipName,
        ];
    }

    private function deleteWebsiteViaDrust(Website $website, bool $deleteUser): void
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Rust website cleanup API is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('serverpanel.execution_api_timeout', 60))
            ->withToken($token)
            ->post(rtrim($baseUrl, '/').'/api/v1/website/delete', [
                'site_owner' => (string) $website->site_owner,
                'paths' => array_values(array_unique(array_filter([
                    (string) $website->project_root,
                    (string) $website->root_path,
                ]))),
                'delete_user' => $deleteUser,
            ]);

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Rust website cleanup failed.'));
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($filename)) ?? '';
        $filename = trim($filename, '.-_');

        return $filename !== '' ? $filename : 'site';
    }

    private function removeQueueWorkers(Website $website): void
    {
        if (! $website->queueWorkers()->exists()) {
            return;
        }

        try {
            app(LaravelQueueService::class)->removeAll($website);
        } catch (\Throwable $e) {
            Log::warning('Queue worker cleanup failed', [
                'website_id' => (string) $website->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
