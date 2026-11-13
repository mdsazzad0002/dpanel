<?php

namespace App\Services\Website;

use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Models\Website;
use App\Models\WebsiteTrashBackup;
use App\Services\Cron\CronSystemService;
use App\Services\Database\DatabaseServerRemover;
use App\Services\Redis\WebsiteRedisExplorer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deletes a main website or a subdomain: its files are archived to the trash
 * first, then its cron jobs, queue workers and directories go, with its
 * aliases (an alias only points at it). See plan() for when the system user
 * goes too. Deleting an alias (MainWebsiteController::destroyLinkedDomain)
 * never touches its main website. Each step that fails puts the cron jobs
 * back and stops. Its Redis keys are removed last, once the website is gone.
 */
class WebsiteDeletionService
{
    public function __construct(
        private readonly CronSystemService $cron,
        private readonly WebsiteRedisExplorer $redis,
    ) {}

    /**
     * @return list<string> what was cleaned up, for the success message
     *
     * @throws WebsiteDeletionFailed
     */
    /**
     * @param  bool  $toTrash  false deletes for good: no archive and no Trash Backup entry
     * @param  bool  $dropDatabases  also drop the website's databases from their server
     *                               (with $toTrash only MariaDB ones, the ones the archive holds)
     */
    public function delete(Website $website, ?int $deletedBy, bool $toTrash = true, bool $dropDatabases = false): array
    {
        $id = (string) $website->id;
        $domain = strtolower(trim((string) ($website->domain ?? '')));
        ['delete_user' => $deleteUser, 'linked' => $linked, 'paths' => $paths, 'kept' => $kept] = $this->plan($website);
        $websiteIds = [$id, ...$linked->map(fn (Website $item): string => (string) $item->id)->all()];

        $databaseModels = DatabaseRequest::query()->where('domain', $domain)->get();
        // The trash archive dumps with the MySQL client, so only MariaDB databases go in it.
        $databaseRequests = $databaseModels
            ->filter(fn (DatabaseRequest $item): bool => ! $item->isPostgresql() && ! $item->isMongodb())
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
                'engine' => DatabaseRequest::ENGINE_MARIADB,
            ])
            ->values()
            ->all();
        $cronJobModels = CronJob::query()
            ->whereIn('website_id', $websiteIds)
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

        $archive = null;
        try {
            if ($toTrash) {
                $archive = $this->archiveWebsiteViaDrust($website, $databaseRequests, $cronJobs);
                $cleanupMessages[] = 'Website files archived to '.$archive['zip_name'].'.';
            } else {
                $cleanupMessages[] = 'Deleted permanently, without a trash copy.';
            }
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
        $linked->each(fn (Website $item) => $this->removeQueueWorkers($item));

        try {
            $this->deleteWebsiteViaDrust($website, $deleteUser, $paths);
            $cleanupMessages[] = $deleteUser
                ? 'Website directory and system user '.$website->site_owner.' removed.'
                : ($paths !== [] ? 'Removed '.implode(', ', $paths).'.' : 'No directory removed.')
                    .' System user '.$website->site_owner.' stays for '.$kept->pluck('domain')->implode(', ').'.';
        } catch (\Throwable $e) {
            $this->restoreSystemCronJobs($removedCronJobs, $website);

            throw new WebsiteDeletionFailed('Website system cleanup failed.', 'system_cleanup', $e);
        }

        try {
            DB::transaction(function () use ($deletedBy, $website, $domain, $id, $archive, $databaseRequests, $cronJobs, $linked, $websiteIds): void {
                $archive === null ? null : WebsiteTrashBackup::query()->create([
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

                CronJob::query()->whereIn('website_id', $websiteIds)->delete();
                $linked->each(fn (Website $item) => $this->forgetLinkedDomain($item));
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

        if ($linked->isNotEmpty()) {
            $cleanupMessages[] = 'Also removed: '.$linked->pluck('domain')->implode(', ').'.';
        }

        if ($dropDatabases) {
            array_push($cleanupMessages, ...$this->dropDatabases($databaseModels, $toTrash));
        } elseif ($databaseModels->isNotEmpty()) {
            $cleanupMessages[] = 'Databases kept on the server: '.$databaseModels->pluck('database_name')->implode(', ').'.';
        }

        $redisKeys = $this->purgeRedisKeys($website);
        if ($redisKeys > 0) {
            $cleanupMessages[] = "{$redisKeys} Redis key(s) removed.";
        }

        return $cleanupMessages;
    }

    /**
     * Best effort: a Redis outage must not undo a finished deletion, and keys
     * left behind are only stale cache and sessions.
     */
    private function purgeRedisKeys(Website $website): int
    {
        try {
            return $this->redis->purge($this->redis->prefixFor($website));
        } catch (\Throwable $e) {
            Log::warning('Website Redis cleanup failed', [
                'website_id' => (string) $website->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
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
        $drustUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($drustUrl === '') {
            throw new \RuntimeException('drust archive API is not configured.');
        }

        $token = trim((string) config('dpanel.execution_api_token', ''));
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
            ->timeout((int) config('dpanel.execution_api_upload_timeout', 3600))
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

    /** @param list<string> $paths directories to remove when the system user stays */
    private function deleteWebsiteViaDrust(Website $website, bool $deleteUser, array $paths): void
    {
        if (! $deleteUser && $paths === []) {
            return;
        }
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        $token = trim((string) config('dpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Rust website cleanup API is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('dpanel.execution_api_timeout', 60))
            ->withToken($token)
            ->post(rtrim($baseUrl, '/').'/api/v1/website/delete', [
                'site_owner' => (string) $website->site_owner,
                'paths' => $paths,
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

    /**
     * What goes with this website:
     *  - linked: its aliases (and aliases left without a main website), which
     *    only point at it;
     *  - delete_user: true when no other website is left on the system user,
     *    which then goes with its whole home;
     *  - otherwise only this website's own directories go (a main website's
     *    public_html), never one that holds or is another website's directory;
     *  - kept: the websites the system user stays for.
     *
     * @return array{delete_user: bool, linked: Collection<int, Website>, paths: list<string>, kept: Collection<int, Website>}
     */
    private function plan(Website $website): array
    {
        $owner = trim((string) $website->site_owner);
        $id = (string) $website->id;
        if ($owner === '') {
            return ['delete_user' => false, 'linked' => collect(), 'paths' => [], 'kept' => collect()];
        }
        $others = Website::query()->where('site_owner', $owner)->whereKeyNot($id)->get();
        $existingIds = Website::query()->whereIn('id', $others->pluck('parent_id')->filter()->unique())->pluck('id')->map(fn ($value) => (string) $value)->all();
        $linked = $others->filter(fn (Website $item): bool => in_array(strtolower((string) $item->type), ['alias', 'alis'], true)
            && ((string) $item->parent_id === $id || $item->parent_id === null || ! in_array((string) $item->parent_id, $existingIds, true)))->values();
        $kept = $others->reject(fn (Website $item): bool => $linked->contains('id', $item->id))->values();

        $paths = array_values(array_unique(array_filter([(string) $website->root_path, (string) $website->project_root])));
        if ($kept->isEmpty()) {
            return ['delete_user' => true, 'linked' => $linked, 'paths' => $paths, 'kept' => $kept];
        }

        $keptDirs = $kept->flatMap(fn (Website $item): array => array_filter([(string) $item->root_path, (string) $item->project_root]))
            ->map(fn (string $path): string => rtrim($path, '/'))->unique()->all();
        $paths = array_values(array_filter($paths, function (string $path) use ($keptDirs): bool {
            $path = rtrim($path, '/');
            foreach ($keptDirs as $dir) {
                // Never remove a directory that is, or holds, another website's.
                // (A kept site's project_root is often the whole home, which
                // holds this one's public_html: that is fine to remove.)
                if (str_starts_with($dir.'/', $path.'/')) {
                    return false;
                }
            }

            return true;
        }));

        return ['delete_user' => false, 'linked' => $linked, 'paths' => $paths, 'kept' => $kept];
    }

    /**
     * Runs after the website is gone, so a failure is reported, not undone.
     * With a trash copy only MariaDB databases go: only they are in the archive.
     *
     * @param  Collection<int, DatabaseRequest>  $databases
     * @return list<string>
     */
    private function dropDatabases(Collection $databases, bool $toTrash): array
    {
        $messages = [];
        foreach ($databases as $database) {
            $name = (string) $database->database_name;
            if ($toTrash && ($database->isPostgresql() || $database->isMongodb())) {
                $messages[] = "{$name} kept on the server: the trash archive holds only MariaDB databases.";

                continue;
            }
            try {
                $messages[] = app(DatabaseServerRemover::class)->drop($database).'.';
            } catch (\Throwable $e) {
                Log::warning('Database drop after website delete failed', ['database' => $name, 'error' => $e->getMessage()]);
                $messages[] = "{$name} could not be dropped: ".$e->getMessage();
            }
        }

        return $messages;
    }

    /** What MainWebsiteController::destroyLinkedDomain removes for an alias or subdomain. */
    private function forgetLinkedDomain(Website $linked): void
    {
        $domain = strtolower(trim((string) $linked->domain));
        SslCertificate::query()
            ->where(function ($query) use ($linked, $domain): void {
                $query->where('website_id', (string) $linked->id);
                if ($domain !== '') {
                    $query->orWhere('domain', $domain);
                }
            })
            ->delete();
        if ($domain !== '') {
            Domain::query()->where('name', $domain)->delete();
        }
        $linked->delete();
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
