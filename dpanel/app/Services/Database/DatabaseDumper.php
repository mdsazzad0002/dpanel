<?php

namespace App\Services\Database;

use App\Models\DatabaseRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dumps one database with its own login, for the Databases page export.
 * The dump tool writes straight to disk (never into PHP memory), and SQL is
 * then gzipped in chunks, so a large database costs disk space, not RAM.
 * Passwords go through a 0600 options file or the environment, never the
 * command line, where other users could read them in the process list.
 */
class DatabaseDumper
{
    /** One hour, the same limit as the heavy queue's website exports. */
    private const TIMEOUT_SECONDS = 3600;

    /**
     * @return array{path: string, file_name: string}
     */
    public function dump(DatabaseRequest $database, string $directory): array
    {
        File::ensureDirectoryExists($directory);
        $base = $directory.'/'.Str::uuid();
        $name = (preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $database->database_name) ?: 'database')
            .'-'.now()->format('Y-m-d_H-i-s');

        try {
            if ($database->isMongodb()) {
                $this->mongodump($database, $base.'.archive.gz');

                return ['path' => $base.'.archive.gz', 'file_name' => $name.'.archive.gz'];
            }

            $database->isPostgresql()
                ? $this->pgDump($database, $base.'.sql')
                : $this->mysqlDump($database, $base.'.sql');
            $this->gzip($base.'.sql', $base.'.sql.gz');

            return ['path' => $base.'.sql.gz', 'file_name' => $name.'.sql.gz'];
        } catch (\Throwable $e) {
            File::delete([$base.'.sql', $base.'.sql.gz', $base.'.archive.gz']);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        } finally {
            File::delete($base.'.sql');
        }
    }

    private function mysqlDump(DatabaseRequest $database, string $target): void
    {
        $options = $this->secretFile("[client]\npassword=\"".addcslashes((string) $database->database_password, '\\"')."\"\n");
        try {
            $binary = $this->binary(['mariadb-dump', 'mysqldump']);
            $this->run([
                $binary,
                // Must be the first option.
                '--defaults-extra-file='.$options,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--routines',
                '--triggers',
                // MySQL 8 otherwise needs the PROCESS privilege a site user lacks.
                ...($binary === 'mysqldump' ? ['--no-tablespaces'] : []),
                '--default-character-set=utf8mb4',
                '--host='.((string) $database->database_host ?: '127.0.0.1'),
                '--user='.$database->database_user,
                '--result-file='.$target,
                (string) $database->database_name,
            ]);
        } finally {
            File::delete($options);
        }
    }

    private function pgDump(DatabaseRequest $database, string $target): void
    {
        $this->run([
            $this->binary(['pg_dump']),
            '--host='.((string) $database->database_host ?: (string) config('postgresql.host', '127.0.0.1')),
            '--port='.(int) config('postgresql.port', 5432),
            '--username='.$database->database_user,
            '--no-owner',
            '--no-acl',
            '--format=plain',
            '--file='.$target,
            (string) $database->database_name,
        ], ['PGPASSWORD' => (string) $database->database_password]);
    }

    private function mongodump(DatabaseRequest $database, string $target): void
    {
        // Database users are created in their own database (drust provision_user).
        $config = $this->secretFile('password: "'.addcslashes((string) $database->database_password, '\\"')."\"\n");
        try {
            $this->run([
                $this->binary(['mongodump']),
                '--config='.$config,
                '--host='.config('mongodb.host', '127.0.0.1'),
                '--port='.(int) config('mongodb.port', 27017),
                '--username='.$database->database_user,
                '--authenticationDatabase='.$database->database_name,
                '--db='.$database->database_name,
                '--archive='.$target,
                '--gzip',
                '--quiet',
            ]);
        } finally {
            File::delete($config);
        }
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    private function run(array $command, array $env = []): void
    {
        $result = Process::timeout(self::TIMEOUT_SECONDS)->env($env)->run($command);
        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output()) ?: 'exit code '.$result->exitCode();
            throw new RuntimeException(basename($command[0]).' failed: '.Str::limit($error, 500));
        }
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function binary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (Process::run(['sh', '-c', 'command -v '.escapeshellarg($candidate)])->successful()) {
                return $candidate;
            }
        }

        throw new RuntimeException($candidates[0].' is not installed on this server.');
    }

    private function secretFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dpanel-dump-');
        chmod($path, 0600);
        File::put($path, $content);

        return $path;
    }

    private function gzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException('Could not compress the dump.');
        }
        try {
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false || gzwrite($out, $chunk) === false) {
                    throw new RuntimeException('Could not compress the dump.');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }
}
