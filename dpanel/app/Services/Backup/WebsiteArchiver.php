<?php

namespace App\Services\Backup;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Builds a website's zip/tar.gz archive via the dRust execution API. Shared by
 * BackupController (full/per-website "Run Now" backups) and QuickExportJob
 * (per-website quick export), so it takes a User rather than a Request —
 * QuickExportJob runs on a queue worker with no HTTP request of its own.
 */
class WebsiteArchiver
{
    /** @return array{ok:bool,message:string,warning?:string} */
    public function archive(User $user, Website $website, string $content, string $baseUrl, string $token, ?string $targetPath = null, ?string $onlyDatabaseId = null): array
    {
        $timestamp = now()->format('Ymd_His');
        $runDirectory = storage_path('app/backups/'.$timestamp);
        $safeOwner = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($website->site_owner ?: 'account')) ?: 'account';
        $zipPath = $targetPath ?: $runDirectory.DIRECTORY_SEPARATOR.'backup-'.now()->format('m.d.Y_H-i-s').'_'.$safeOwner.'.tar.gz';
        $databases = $this->databases($user, $website, $onlyDatabaseId);

        if ($content === 'database' && $databases === []) {
            return ['ok' => false, 'message' => 'No approved database is linked to this main domain.'];
        }

        $response = $this->post($baseUrl, $token, '/api/v1/website/archive', $zipPath, $website, $content, $databases);

        if (! $response->successful() || ! (bool) $response->json('success')) {
            return ['ok' => false, 'message' => (string) ($response->json('message') ?: 'Website backup failed.')];
        }

        return ['ok' => true, 'message' => '', 'warning' => $this->skippedWarning($response)];
    }

    /**
     * Builds a Clone & Share / Push to Live package in one dRust call — as
     * root, since each site's files belong to its own system user.
     *
     * @return array{ok:bool,message:string,has_database?:bool,warning?:string}
     */
    public function clonePackage(User $user, Website $website, string $baseUrl, string $token, string $packagePath): array
    {
        $response = $this->post($baseUrl, $token, '/api/v1/website/clone-package', $packagePath, $website, 'quick_files', $this->databases($user, $website, null));
        if (! $response->successful() || ! (bool) $response->json('success')) {
            return ['ok' => false, 'message' => (string) ($response->json('message') ?: 'Clone package could not be built.')];
        }

        return ['ok' => true, 'message' => '', 'has_database' => (bool) $response->json('data.has_database'), 'warning' => $this->skippedWarning($response)];
    }

    private function databases(User $user, Website $website, ?string $onlyDatabaseId): array
    {
        return DatabaseRequest::query()
            ->visibleTo($user)
            ->where('domain', $website->domain)
            ->where('status', 'active')
            ->when($onlyDatabaseId !== null, fn ($query) => $query->where('id', $onlyDatabaseId))
            ->get()
            ->map(fn (DatabaseRequest $database): array => [
                'name' => (string) $database->database_name,
                'user' => (string) $database->database_user,
                'password' => (string) $database->database_password,
                'host' => (string) ($database->database_host ?: '127.0.0.1'),
            ])->values()->all();
    }

    private function post(string $baseUrl, string $token, string $path, string $zipPath, Website $website, string $content, array $databases): Response
    {
        // A drust restart (an update, a config change) refuses connections or
        // drops the open one for a few seconds. The archive is written to the
        // same path again, so retrying is safe; a timeout is not retried
        // because drust may still be working on it.
        return Http::acceptJson()->asJson()->withToken($token)
            ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
            ->retry(6, fn (int $attempt) => min(10000, 1000 * 2 ** ($attempt - 1)), fn (Throwable $e) => self::isDrustRestart($e), throw: false)
            ->post(rtrim($baseUrl, '/').$path, [
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
                    'assigned_user_id' => $website->assigned_user_id,
                    'assigned_reseller_id' => $website->assigned_reseller_id,
                    'content' => $content,
                    'database_requests' => $databases,
                ],
            ]);
    }

    // dRust skips unreadable entries instead of aborting, but now reports
    // them — surface that so a partial export doesn't pass as complete.
    private function skippedWarning(Response $response): string
    {
        $skipped = (int) $response->json('data.skipped', 0);

        return $skipped > 0
            ? $skipped.' file(s) could not be read and were left out: '.implode('; ', (array) $response->json('data.skipped_samples', []))
            : '';
    }

    /** Connection refused (cURL 7) or dropped mid-request (52, 56). */
    public static function isDrustRestart(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            && preg_match('/cURL error (7|52|56):/', $exception->getMessage()) === 1;
    }
}
