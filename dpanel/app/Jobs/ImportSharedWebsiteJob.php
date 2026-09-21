<?php

namespace App\Jobs;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\Migration\GenericWebsiteMigrationProvider;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Receiving end of a cross-server clone: downloads the package produced by
 * ShareWebsitePackageJob on the other server (via its shareable download URL)
 * and restores it into a website owned on *this* server, the same way
 * CloneWebsiteJob restores a same-server clone.
 */
class ImportSharedWebsiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $cloneId,
        public string $sourceUrl,
        public string $targetWebsiteId,
        public int $userId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(GenericWebsiteMigrationProvider $restorer, NotificationService $notifications): void
    {
        CloneShareJobStatus::set($this->cloneId, ['stage' => 'downloading']);

        $user = User::find($this->userId);
        $target = $user ? Website::query()->visibleTo($user)->find($this->targetWebsiteId) : null;
        if (! $user || ! $target) {
            CloneShareJobStatus::set($this->cloneId, ['stage' => 'failed', 'message' => 'Target website was not found.']);

            return;
        }

        // The dRust "migration/generic/restore" endpoint only reads archive_path/sql_path
        // under storage/app/migrations (see CloneWebsiteJob for the matching note on the
        // "website/archive" endpoint's separate storage/app/backups allowlist).
        $migrationsDir = storage_path('app/migrations/clone-share');
        if (! is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0750, true);
        }
        $packagePath = $migrationsDir.'/'.Str::uuid().'-incoming.zip';
        $archivePath = $migrationsDir.'/'.Str::uuid().'-files.zip';
        $sqlPath = null;

        try {
            $response = Http::timeout(3600)->sink($packagePath)->get($this->sourceUrl);
            if (! $response->successful() || ! is_file($packagePath) || filesize($packagePath) === 0) {
                throw new RuntimeException('The share link could not be downloaded — it may have expired.');
            }

            $package = new ZipArchive;
            if ($package->open($packagePath) !== true) {
                throw new RuntimeException('The downloaded package is not a valid clone archive.');
            }
            $filesEntry = $package->getFromName('files.zip');
            if ($filesEntry === false) {
                $package->close();
                throw new RuntimeException('The downloaded package does not contain website files.');
            }
            File::put($archivePath, $filesEntry);
            $databaseSql = $package->getFromName('database.sql');
            if ($databaseSql !== false) {
                $sqlPath = $migrationsDir.'/'.Str::uuid().'.sql';
                File::put($sqlPath, $databaseSql);
            }
            $package->close();

            CloneShareJobStatus::set($this->cloneId, ['stage' => 'restoring']);

            $owner = (string) $target->site_owner;
            if ($owner === '') {
                throw new RuntimeException('The target website does not have a system user.');
            }

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

            $restorer->restore([
                'archive_path' => $archivePath,
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
            ]);

            if ($database instanceof DatabaseRequest) {
                DB::transaction(function () use ($database, $target): void {
                    if (! $database->exists) {
                        $database->forceFill(['id' => (string) Str::uuid(), 'assigned_user_id' => $target->assigned_user_id]);
                    }
                    $database->forceFill(['domain' => $target->domain, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active'])->save();
                });
            }
        } catch (Throwable $e) {
            CloneShareJobStatus::set($this->cloneId, ['stage' => 'failed', 'message' => $e->getMessage()]);
            $notifications->notifyUser(
                actor: $user,
                type: 'clone_website',
                status: 'blocked',
                title: "Clone import failed: {$target->domain}",
                message: $e->getMessage(),
                subject: $target,
            );

            return;
        } finally {
            File::delete($packagePath);
            File::delete($archivePath);
            if ($sqlPath !== null) {
                File::delete($sqlPath);
            }
        }

        CloneShareJobStatus::set($this->cloneId, ['stage' => 'ready']);
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'completed',
            title: "Clone imported: {$target->domain}",
            subject: $target,
        );
    }
}
