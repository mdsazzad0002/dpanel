<?php

namespace App\Console\Commands;

use App\Services\Backup\CpanelMigrationArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

class RestoreCpanelBackupCommand extends Command
{
    protected $signature = 'serverpanel:restore
        {archive : Path to a dPanel cPanel-style .tar.gz package}
        {--dry-run : Validate the archive without changing this server}
        {--force : Confirm replacement of panel data and database}';

    protected $description = 'Validate or restore a cPanel-style dPanel migration package';

    public function handle(): int
    {
        $archive = realpath((string) $this->argument('archive'));
        if (! is_string($archive) || ! is_file($archive)) {
            $this->error('Backup archive not found.');

            return self::FAILURE;
        }

        $temporary = storage_path('app/restore-staging/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($temporary);

        try {
            $this->assertSafeTar($archive);
            $extract = Process::timeout(1200)->run(['tar', '-xzf', $archive, '-C', $temporary, '--no-same-owner', '--no-same-permissions']);
            if (! $extract->successful()) {
                throw new RuntimeException('Archive extraction failed: '.trim($extract->errorOutput()));
            }

            $manifest = $this->validatePackage($temporary);
            $this->info('Valid migration package: '.($manifest['created_at'] ?? 'unknown date'));

            if ((bool) $this->option('dry-run')) {
                $this->info('Dry run completed. No files or databases were changed.');

                return self::SUCCESS;
            }

            if (! (bool) $this->option('force')) {
                $this->error('Restore replaces application data and the configured panel database. Re-run with --force.');

                return self::FAILURE;
            }

            $this->restoreFiles($temporary.DIRECTORY_SEPARATOR.'homedir'.DIRECTORY_SEPARATOR.'app-data.zip');
            $this->restoreDatabase($this->databaseArtifact($temporary));
            $this->call('optimize:clear');
            $this->info('Restore completed. Review server-specific DNS, SSL and service configuration before switching traffic.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($temporary);
        }
    }

    private function assertSafeTar(string $archive): void
    {
        $result = Process::timeout(120)->run(['tar', '-tzf', $archive]);
        if (! $result->successful()) {
            throw new RuntimeException('Invalid gzip/tar archive.');
        }

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $entry) {
            $entry = str_replace('\\', '/', $entry);
            if ($entry === '' || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry) === 1) {
                throw new RuntimeException('Unsafe path detected in archive.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function validatePackage(string $temporary): array
    {
        $manifestPath = $temporary.DIRECTORY_SEPARATOR.'meta'.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('Package manifest is missing.');
        }

        $manifest = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== CpanelMigrationArchive::FORMAT || (int) ($manifest['version'] ?? 0) !== CpanelMigrationArchive::VERSION) {
            throw new RuntimeException('Unsupported migration package format or version.');
        }

        foreach (($manifest['artifacts'] ?? []) as $relative => $metadata) {
            if (! is_string($relative) || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
                throw new RuntimeException('Invalid artifact path in manifest.');
            }
            $path = $temporary.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($path) || ! hash_equals((string) ($metadata['sha256'] ?? ''), hash_file('sha256', $path))) {
                throw new RuntimeException('Checksum validation failed for '.$relative.'.');
            }
        }

        return $manifest;
    }

    private function restoreFiles(string $archive): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is required to restore application files.');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Cannot open application data archive.');
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
                $zip->close();
                throw new RuntimeException('Unsafe path in application data archive.');
            }
        }
        if (! $zip->extractTo(base_path())) {
            $zip->close();
            throw new RuntimeException('Could not restore application files.');
        }
        $zip->close();
    }

    private function databaseArtifact(string $temporary): string
    {
        $files = File::files($temporary.DIRECTORY_SEPARATOR.'mysql');
        if (count($files) !== 1) {
            throw new RuntimeException('Migration package must contain exactly one panel database artifact.');
        }

        return $files[0]->getPathname();
    }

    private function restoreDatabase(string $artifact): void
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);
        $driver = (string) ($config['driver'] ?? $connection);

        if ($driver === 'sqlite') {
            $target = (string) ($config['database'] ?? '');
            if ($target === '' || pathinfo($artifact, PATHINFO_EXTENSION) !== 'sqlite') {
                throw new RuntimeException('SQLite backup does not match the configured database.');
            }
            File::ensureDirectoryExists(dirname($target));
            File::copy($artifact, $target);

            return;
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
        $user = (string) ($config['username'] ?? '');
        $database = (string) ($config['database'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $args = [trim((string) env('MYSQL_PATH', 'mysql')), '--host='.$host, '--port='.$port, '--user='.$user];
            if ($password !== '') {
                $args[] = '--password='.$password;
            }
            $args[] = $database;
            $result = Process::timeout(1800)->input(File::get($artifact))->run($args);
        } elseif ($driver === 'pgsql') {
            $result = Process::timeout(1800)->env(['PGPASSWORD' => $password])->input(File::get($artifact))->run([
                trim((string) env('PSQL_PATH', 'psql')), '--host='.$host, '--port='.$port, '--username='.$user, '--dbname='.$database,
            ]);
        } else {
            throw new RuntimeException('Unsupported configured database driver: '.$driver);
        }

        if (! $result->successful()) {
            throw new RuntimeException('Database restore failed: '.trim($result->errorOutput().' '.$result->output()));
        }
    }
}
