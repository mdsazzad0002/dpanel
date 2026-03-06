<?php

namespace App\Console\Commands;

use App\Support\BackupSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ZipArchive;

class RunDailyBackupCommand extends Command
{
    protected $signature = 'serverpanel:backup {--only=all : all|db|files}';

    protected $description = 'Create backup for database and application data';

    public function handle(): int
    {
        $only = strtolower((string) $this->option('only'));
        if (! in_array($only, ['all', 'db', 'files'], true)) {
            $this->error('Invalid --only value. Use all, db, or files.');

            return self::FAILURE;
        }

        $timestamp = now()->format('Ymd_His');
        $backupRoot = storage_path('app/backups');
        $runDirectory = $backupRoot.DIRECTORY_SEPARATOR.$timestamp;
        File::ensureDirectoryExists($runDirectory);
        $settings = app(BackupSettings::class)->read();

        $hasFailure = false;

        if (in_array($only, ['all', 'db'], true)) {
            $databaseResult = $this->backupDatabase($runDirectory);
            if ($databaseResult['success']) {
                $this->info('Database backup created: '.$databaseResult['path']);
            } else {
                $hasFailure = true;
                $this->error('Database backup failed: '.$databaseResult['message']);
            }
        }

        if (in_array($only, ['all', 'files'], true)) {
            $filesResult = $this->backupAppFiles($runDirectory);
            if ($filesResult['success']) {
                $this->info('File backup created: '.$filesResult['path']);
            } else {
                $hasFailure = true;
                $this->error('File backup failed: '.$filesResult['message']);
            }
        }

        $this->cleanupOldBackups($backupRoot, (int) ($settings['retention_days'] ?? 7));

        if (! $hasFailure && (bool) ($settings['remote_upload_enabled'] ?? false)) {
            $uploadResult = $this->uploadRunDirectory($runDirectory, $settings);
            if ($uploadResult['success']) {
                $this->info('Remote backup upload completed.');
            } else {
                $hasFailure = true;
                $this->error('Remote backup upload failed: '.$uploadResult['message']);
            }
        }

        if ($hasFailure) {
            return self::FAILURE;
        }

        $this->info('Backup completed successfully.');

        return self::SUCCESS;
    }

    /**
     * @return array{success:bool,path:string,message:string}
     */
    private function backupDatabase(string $runDirectory): array
    {
        $connection = (string) config('database.default', env('DB_CONNECTION', 'sqlite'));
        $config = (array) config("database.connections.{$connection}", []);
        $driver = strtolower((string) ($config['driver'] ?? $connection));

        if ($driver === 'sqlite') {
            return $this->backupSqliteDatabase($config, $connection, $runDirectory);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $this->backupMysqlDatabase($config, $connection, $runDirectory);
        }

        if ($driver === 'pgsql') {
            return $this->backupPostgresDatabase($config, $connection, $runDirectory);
        }

        return [
            'success' => false,
            'path' => '',
            'message' => "Unsupported DB driver: {$driver}",
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{success:bool,path:string,message:string}
     */
    private function backupSqliteDatabase(array $config, string $connection, string $runDirectory): array
    {
        $databasePath = (string) ($config['database'] ?? '');
        if ($databasePath === '' || ! File::exists($databasePath)) {
            return [
                'success' => false,
                'path' => '',
                'message' => 'SQLite file not found.',
            ];
        }

        $targetPath = $runDirectory.DIRECTORY_SEPARATOR."database-{$connection}.sqlite";
        File::copy($databasePath, $targetPath);

        return [
            'success' => true,
            'path' => $targetPath,
            'message' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{success:bool,path:string,message:string}
     */
    private function backupMysqlDatabase(array $config, string $connection, string $runDirectory): array
    {
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        if ($database === '' || $username === '') {
            return [
                'success' => false,
                'path' => '',
                'message' => 'Database name or username is empty.',
            ];
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $password = (string) ($config['password'] ?? '');
        $socket = (string) ($config['unix_socket'] ?? '');
        $mysqldumpPath = trim((string) env('MYSQLDUMP_PATH', 'mysqldump'));
        $targetPath = $runDirectory.DIRECTORY_SEPARATOR."database-{$connection}.sql";

        $arguments = [
            $mysqldumpPath,
            '--single-transaction',
            '--skip-lock-tables',
            '--routines',
            '--events',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            $database,
        ];

        if ($password !== '') {
            $arguments[] = '--password='.$password;
        }

        if ($socket !== '') {
            $arguments[] = '--socket='.$socket;
        }

        $result = Process::timeout(600)->run($arguments);
        if (! $result->successful()) {
            return [
                'success' => false,
                'path' => '',
                'message' => trim($result->errorOutput().' '.$result->output()),
            ];
        }

        File::put($targetPath, $result->output());

        return [
            'success' => true,
            'path' => $targetPath,
            'message' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{success:bool,path:string,message:string}
     */
    private function backupPostgresDatabase(array $config, string $connection, string $runDirectory): array
    {
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        if ($database === '' || $username === '') {
            return [
                'success' => false,
                'path' => '',
                'message' => 'Database name or username is empty.',
            ];
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');
        $password = (string) ($config['password'] ?? '');
        $pgDumpPath = trim((string) env('PG_DUMP_PATH', 'pg_dump'));
        $targetPath = $runDirectory.DIRECTORY_SEPARATOR."database-{$connection}.sql";

        $arguments = [
            $pgDumpPath,
            '--host='.$host,
            '--port='.$port,
            '--username='.$username,
            '--no-owner',
            '--no-acl',
            '--format=plain',
            $database,
        ];

        $runner = Process::timeout(600);
        if ($password !== '') {
            $runner = $runner->env(['PGPASSWORD' => $password]);
        }

        $result = $runner->run($arguments);
        if (! $result->successful()) {
            return [
                'success' => false,
                'path' => '',
                'message' => trim($result->errorOutput().' '.$result->output()),
            ];
        }

        File::put($targetPath, $result->output());

        return [
            'success' => true,
            'path' => $targetPath,
            'message' => '',
        ];
    }

    /**
     * @return array{success:bool,path:string,message:string}
     */
    private function backupAppFiles(string $runDirectory): array
    {
        if (! class_exists(ZipArchive::class)) {
            return [
                'success' => false,
                'path' => '',
                'message' => 'ZipArchive extension is not installed.',
            ];
        }

        $zipPath = $runDirectory.DIRECTORY_SEPARATOR.'app-data.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [
                'success' => false,
                'path' => '',
                'message' => 'Cannot create zip archive.',
            ];
        }

        $envPath = base_path('.env');
        if (File::exists($envPath)) {
            $zip->addFile($envPath, '.env');
        }

        $storageAppPath = storage_path('app');
        if (File::isDirectory($storageAppPath)) {
            $this->addDirectoryToZip($zip, $storageAppPath, 'storage/app', [
                DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR,
            ]);
        }

        $sqlitePath = database_path('database.sqlite');
        if (File::exists($sqlitePath)) {
            $zip->addFile($sqlitePath, 'database/database.sqlite');
        }

        $zip->close();

        return [
            'success' => true,
            'path' => $zipPath,
            'message' => '',
        ];
    }

    /**
     * @param  array<int, string>  $skipContains
     */
    private function addDirectoryToZip(ZipArchive $zip, string $sourceDirectory, string $zipRoot, array $skipContains = []): void
    {
        $sourceDirectory = rtrim($sourceDirectory, DIRECTORY_SEPARATOR);
        $zipRoot = trim(str_replace('\\', '/', $zipRoot), '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo || ! $item->isFile()) {
                continue;
            }

            $filePath = $item->getRealPath();
            if (! is_string($filePath) || $filePath === '') {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $filePath);
            $shouldSkip = false;
            foreach ($skipContains as $part) {
                $normalizedPart = str_replace('\\', '/', $part);
                if ($normalizedPart !== '' && str_contains($normalizedPath, $normalizedPart)) {
                    $shouldSkip = true;
                    break;
                }
            }

            if ($shouldSkip) {
                continue;
            }

            $relativePath = ltrim(substr($filePath, strlen($sourceDirectory)), DIRECTORY_SEPARATOR);
            $zipPath = $zipRoot.'/'.str_replace('\\', '/', $relativePath);
            $zip->addFile($filePath, $zipPath);
        }
    }

    private function cleanupOldBackups(string $backupRoot, int $retentionDays): void
    {
        $retentionDays = max(1, $retentionDays);
        $threshold = now()->subDays($retentionDays);

        if (! File::isDirectory($backupRoot)) {
            return;
        }

        foreach (File::directories($backupRoot) as $directory) {
            $directoryName = basename($directory);
            try {
                $createdAt = Carbon::createFromFormat('Ymd_His', $directoryName);
            } catch (\Throwable) {
                continue;
            }

            if ($createdAt->lessThan($threshold)) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function uploadRunDirectory(string $runDirectory, array $settings): array
    {
        $remoteHost = trim((string) ($settings['remote_host'] ?? ''));
        $remoteUser = trim((string) ($settings['remote_user'] ?? ''));
        $remotePath = trim((string) ($settings['remote_path'] ?? ''));
        $remotePort = (string) max(1, (int) ($settings['remote_port'] ?? 22));
        $sshPath = trim((string) ($settings['remote_ssh_path'] ?? 'ssh'));
        $scpPath = trim((string) ($settings['remote_scp_path'] ?? 'scp'));
        $sshKeyPath = trim((string) ($settings['remote_ssh_key_path'] ?? ''));
        $strictHostChecking = ((bool) ($settings['remote_strict_host_checking'] ?? true)) ? 'yes' : 'no';

        if ($remoteHost === '' || $remoteUser === '' || $remotePath === '') {
            return [
                'success' => false,
                'message' => 'BACKUP_REMOTE_HOST, BACKUP_REMOTE_USER, BACKUP_REMOTE_PATH are required.',
            ];
        }

        $realRunDirectory = realpath($runDirectory);
        if (! is_string($realRunDirectory) || $realRunDirectory === '' || ! is_dir($realRunDirectory)) {
            return [
                'success' => false,
                'message' => 'Backup run directory not found.',
            ];
        }

        $files = File::files($realRunDirectory);
        if ($files === []) {
            return [
                'success' => false,
                'message' => 'No backup files found for upload.',
            ];
        }

        $runName = basename($realRunDirectory);
        $remoteRunPath = rtrim(str_replace('\\', '/', $remotePath), '/').'/'.$runName;
        $remoteTarget = $remoteUser.'@'.$remoteHost;

        $sshArgs = [
            $sshPath,
            '-p',
            $remotePort,
            '-o',
            'StrictHostKeyChecking='.$strictHostChecking,
        ];
        if ($sshKeyPath !== '') {
            $sshArgs[] = '-i';
            $sshArgs[] = $sshKeyPath;
        }
        $sshArgs[] = $remoteTarget;
        $sshArgs[] = 'mkdir -p '.escapeshellarg($remoteRunPath);

        $mkdirResult = Process::timeout(120)->run($sshArgs);
        if (! $mkdirResult->successful()) {
            return [
                'success' => false,
                'message' => trim($mkdirResult->errorOutput().' '.$mkdirResult->output()),
            ];
        }

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $realPath = $file->getRealPath();
            if (! is_string($realPath) || $realPath === '') {
                continue;
            }

            $scpArgs = [
                $scpPath,
                '-P',
                $remotePort,
                '-o',
                'StrictHostKeyChecking='.$strictHostChecking,
            ];
            if ($sshKeyPath !== '') {
                $scpArgs[] = '-i';
                $scpArgs[] = $sshKeyPath;
            }
            $scpArgs[] = $realPath;
            $scpArgs[] = $remoteTarget.':'.$remoteRunPath.'/'.$file->getFilename();

            $uploadResult = Process::timeout(600)->run($scpArgs);
            if (! $uploadResult->successful()) {
                return [
                    'success' => false,
                    'message' => trim($uploadResult->errorOutput().' '.$uploadResult->output()),
                ];
            }
        }

        return [
            'success' => true,
            'message' => '',
        ];
    }

}
