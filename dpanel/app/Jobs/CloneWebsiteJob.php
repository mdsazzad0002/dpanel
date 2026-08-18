<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\Backup\WebsiteArchiver;
use App\Services\Migration\GenericWebsiteMigrationProvider;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Same-server "Clone" half of Clone & Share: archives the source website's
 * files + database via the dRust API (same path QuickExportJob uses), then
 * restores that package into an already-owned target website via
 * GenericWebsiteMigrationProvider — the same restore call the generic
 * website-import flow uses, just skipping its chunked-upload step since the
 * archive is already local.
 */
class CloneWebsiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $cloneId,
        public string $sourceWebsiteId,
        public string $targetWebsiteId,
        public int $userId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(WebsiteArchiver $archiver, GenericWebsiteMigrationProvider $restorer, NotificationService $notifications): void
    {
        CloneShareJobStatus::set($this->cloneId, ['stage' => 'archiving']);

        $user = User::find($this->userId);
        $source = $user ? Website::query()->visibleTo($user)->find($this->sourceWebsiteId) : null;
        $target = $user ? Website::query()->visibleTo($user)->find($this->targetWebsiteId) : null;
        if (! $user || ! $source || ! $target) {
            $this->fail($notifications, $user, $source, 'Source or target website was not found.');

            return;
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            $this->fail($notifications, $user, $source, 'dRust backup API is not configured.');

            return;
        }

        $archivePath = storage_path('app/backups/clone-share/'.Str::uuid().'.zip');
        $sqlPath = null;
        $restoreArchivePath = null;

        try {
            $filesResult = $archiver->archive($user, $source, 'quick_files', $baseUrl, $apiToken, $archivePath);
            if (! $filesResult['ok'] || ! is_file($archivePath)) {
                $this->fail($notifications, $user, $source, $filesResult['message'] ?: 'Source files could not be archived.', $target);

                return;
            }

            $hasDatabase = DatabaseRequest::query()->visibleTo($user)->where('domain', $source->domain)->where('status', 'active')->exists();
            if ($hasDatabase) {
                CloneShareJobStatus::set($this->cloneId, ['stage' => 'exporting_database']);
                $databasePackagePath = storage_path('app/backups/clone-share/'.Str::uuid().'-db.zip');
                $dbResult = $archiver->archive($user, $source, 'database', $baseUrl, $apiToken, $databasePackagePath);
                if (! $dbResult['ok'] || ! is_file($databasePackagePath)) {
                    File::delete($databasePackagePath);
                    $this->fail($notifications, $user, $source, $dbResult['message'] ?: 'Source database could not be exported.', $target);

                    return;
                }

                $archive = new ZipArchive;
                if ($archive->open($databasePackagePath) === true) {
                    for ($index = 0; $index < $archive->numFiles; $index++) {
                        $entry = (string) $archive->getNameIndex($index);
                        if (str_ends_with(strtolower($entry), '.sql')) {
                            $sqlPath = storage_path('app/migrations/clone-share/'.Str::uuid().'.sql');
                            if (! is_dir(dirname($sqlPath))) {
                                mkdir(dirname($sqlPath), 0750, true);
                            }
                            File::put($sqlPath, (string) $archive->getFromIndex($index));
                            break;
                        }
                    }
                    $archive->close();
                }
                File::delete($databasePackagePath);
            }

            CloneShareJobStatus::set($this->cloneId, ['stage' => 'restoring']);

            $owner = (string) $target->site_owner;
            if ($owner === '') {
                $this->fail($notifications, $user, $source, 'The target website does not have a system user.', $target);

                return;
            }

            // The dRust "website/archive" endpoint only writes under storage/app/backups,
            // but the "migration/generic/restore" endpoint only reads under
            // storage/app/migrations — the two dRust allowlists don't overlap, so the
            // freshly archived zip has to be relocated before it can be restored.
            $restoreArchivePath = storage_path('app/migrations/clone-share/'.Str::uuid().'.zip');
            if (! is_dir(dirname($restoreArchivePath))) {
                mkdir(dirname($restoreArchivePath), 0750, true);
            }
            File::copy($archivePath, $restoreArchivePath);

            $database = null;
            if ($sqlPath !== null) {
                $database = DatabaseRequest::query()->visibleTo($user)->where('domain', $target->domain)->where('status', 'active')->first();
                if (! $database instanceof DatabaseRequest) {
                    $domainPrefix = substr(trim((string) preg_replace('/[^a-z0-9_]/i', '_', explode('.', (string) $target->domain)[0]), '_') ?: 'site', 0, 16);
                    $database = new DatabaseRequest([
                        'database_name' => substr($owner.'_'.$domainPrefix.'_db', 0, 64),
                        'database_user' => substr($owner.'_'.$domainPrefix.'_user', 0, 64),
                        'database_password' => Str::password(24),
                        'database_host' => '127.0.0.1',
                    ]);
                }
            }

            $payload = [
                'archive_path' => $restoreArchivePath,
                'domain' => $target->domain,
                'site_owner' => $owner,
                'php_version' => $target->php_version,
                'target_root' => (string) ($target->root_path ?: $target->project_root),
                'sql_path' => $sqlPath,
                'database_host' => $database?->database_host ?? '127.0.0.1',
                'database_port' => 3306,
                'database_name' => $database?->database_name ?? '',
                'database_user' => $database?->database_user ?? '',
                'database_password' => $database?->database_password ?? '',
                'overwrite_database' => $sqlPath !== null,
            ];

            $restorer->restore($payload);

            if ($database instanceof DatabaseRequest) {
                DB::transaction(function () use ($database, $target): void {
                    if (! $database->exists) {
                        $database->forceFill(['id' => (string) Str::uuid(), 'assigned_user_id' => $target->assigned_user_id]);
                    }
                    $database->forceFill(['domain' => $target->domain, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active'])->save();
                });
            }
        } catch (Throwable $e) {
            $this->fail($notifications, $user, $source, 'Clone failed: '.$e->getMessage(), $target);

            return;
        } finally {
            File::delete($archivePath);
            if ($sqlPath !== null) {
                File::delete($sqlPath);
            }
            if ($restoreArchivePath !== null) {
                File::delete($restoreArchivePath);
            }
        }

        CloneShareJobStatus::set($this->cloneId, ['stage' => 'ready']);
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'completed',
            title: "Clone complete: {$source->domain} → {$target->domain}",
            subject: $target,
        );
    }

    private function fail(NotificationService $notifications, ?User $user, ?Website $source, string $message, ?Website $target = null): void
    {
        CloneShareJobStatus::set($this->cloneId, ['stage' => 'failed', 'message' => $message]);
        if (! $user || ! $source) {
            return;
        }
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'blocked',
            title: $target ? "Clone failed: {$source->domain} → {$target->domain}" : "Clone failed: {$source->domain}",
            message: $message,
            subject: $source,
        );
    }
}
