<?php

namespace App\Services\Backup;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Http;

/**
 * Builds a website's zip/tar.gz archive via the dRust execution API. Shared by
 * BackupController (full/per-website "Run Now" backups) and QuickExportJob
 * (per-website quick export), so it takes a User rather than a Request —
 * QuickExportJob runs on a queue worker with no HTTP request of its own.
 */
class WebsiteArchiver
{
    /** @return array{ok:bool,message:string} */
    public function archive(User $user, Website $website, string $content, string $baseUrl, string $token, ?string $targetPath = null, ?string $onlyDatabaseId = null): array
    {
        $timestamp = now()->format('Ymd_His');
        $runDirectory = storage_path('app/backups/'.$timestamp);
        $safeOwner = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($website->site_owner ?: 'account')) ?: 'account';
        $zipPath = $targetPath ?: $runDirectory.DIRECTORY_SEPARATOR.'backup-'.now()->format('m.d.Y_H-i-s').'_'.$safeOwner.'.tar.gz';
        $databases = DatabaseRequest::query()
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

        if ($content === 'database' && $databases === []) {
            return ['ok' => false, 'message' => 'No approved database is linked to this main domain.'];
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
                    'assigned_user_id' => $website->assigned_user_id,
                    'assigned_reseller_id' => $website->assigned_reseller_id,
                    'content' => $content,
                    'database_requests' => $databases,
                ],
            ]);

        if (! $response->successful() || ! (bool) $response->json('success')) {
            return ['ok' => false, 'message' => (string) ($response->json('message') ?: 'Website backup failed.')];
        }

        return ['ok' => true, 'message' => ''];
    }
}
