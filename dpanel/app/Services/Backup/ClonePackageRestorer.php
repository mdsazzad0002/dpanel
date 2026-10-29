<?php

namespace App\Services\Backup;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Migration\GenericWebsiteMigrationProvider;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Restores a ClonePackageBuilder package into a website on this server.
 * Shared by ImportSharedWebsiteJob (package pulled from a share link) and
 * RestorePushedWebsiteJob (package pushed here by another panel).
 */
class ClonePackageRestorer
{
    public function __construct(
        private readonly GenericWebsiteMigrationProvider $restorer,
        private readonly PreOverwriteBackupService $trash,
    ) {}

    /**
     * @param  string|null  $snapshotReason  when set, the target's current files are first moved to its .trash
     * @param  Closure(string):void|null  $onStage  called with 'extracting', 'backing_up_target', 'restoring'
     */
    public function restore(User $user, Website $target, string $packagePath, ?string $snapshotReason = null, ?Closure $onStage = null): void
    {
        $owner = (string) $target->site_owner;
        if ($owner === '') {
            throw new RuntimeException('The target website does not have a system user.');
        }

        // The dRust "migration/generic/restore" endpoint only reads archive_path/sql_path
        // under storage/app/migrations.
        $migrationsDir = storage_path('app/migrations/clone-share');
        File::ensureDirectoryExists($migrationsDir, 0750);
        $archivePath = $migrationsDir.'/'.Str::uuid().'-files.zip';
        $sqlPath = null;

        try {
            $onStage?->__invoke('extracting');
            $package = new ZipArchive;
            if ($package->open($packagePath) !== true) {
                throw new RuntimeException('The package is not a valid clone archive.');
            }
            try {
                if (! $this->extractEntry($package, 'files.zip', $archivePath)) {
                    throw new RuntimeException('The package does not contain website files.');
                }
                $candidate = $migrationsDir.'/'.Str::uuid().'.sql';
                if ($this->extractEntry($package, 'database.sql', $candidate)) {
                    $sqlPath = $candidate;
                }
            } finally {
                $package->close();
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

            $databaseBackupDir = null;
            if ($snapshotReason !== null) {
                $onStage?->__invoke('backing_up_target');
                $databaseBackupDir = $this->trash->snapshot($target, $snapshotReason)['database'];
            }

            $onStage?->__invoke('restoring');
            $payload = [
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
                // The package is a throwaway transport copy — don't leave one in
                // the owner's home/migration_uploads on every clone or push.
                'preserve_uploads' => false,
            ];
            if ($target->project_root) {
                // Lets the restore put the archive's .env/composer.json extras
                // in the app root instead of the docroot (see dRust website.rs).
                $payload['target_project_root'] = (string) $target->project_root;
            }
            if ($databaseBackupDir !== null) {
                $payload['database_backup_dir'] = $databaseBackupDir;
            }
            $this->restorer->restore($payload);

            if ($database instanceof DatabaseRequest) {
                DB::transaction(function () use ($database, $target): void {
                    if (! $database->exists) {
                        $database->forceFill(['id' => (string) Str::uuid(), 'assigned_user_id' => $target->assigned_user_id]);
                    }
                    $database->forceFill(['domain' => $target->domain, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active'])->save();
                });
            }
        } finally {
            File::delete($archivePath);
            if ($sqlPath !== null) {
                File::delete($sqlPath);
            }
        }
    }

    /** Streams one entry to disk instead of loading a multi-GB entry into memory. */
    private function extractEntry(ZipArchive $package, string $name, string $target): bool
    {
        $source = $package->getStream($name);
        if ($source === false) {
            return false;
        }
        $output = fopen($target, 'wb');
        stream_copy_to_stream($source, $output);
        fclose($output);
        fclose($source);

        return true;
    }
}
