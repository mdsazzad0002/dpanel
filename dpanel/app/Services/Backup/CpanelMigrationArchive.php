<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class CpanelMigrationArchive
{
    public const FORMAT = 'dpanel-cpanel-migration';

    public const VERSION = 1;

    public function create(string $runDirectory, string $timestamp): string
    {
        $stage = $runDirectory.DIRECTORY_SEPARATOR.'.cpanel-package';
        File::deleteDirectory($stage);
        File::ensureDirectoryExists($stage.DIRECTORY_SEPARATOR.'homedir');
        File::ensureDirectoryExists($stage.DIRECTORY_SEPARATOR.'mysql');
        File::ensureDirectoryExists($stage.DIRECTORY_SEPARATOR.'meta');

        $artifacts = [];
        foreach (File::files($runDirectory) as $file) {
            $name = $file->getFilename();
            if ($name === 'app-data.zip') {
                $relative = 'homedir/app-data.zip';
            } elseif (preg_match('/^database-.+\.(sql|sqlite)$/', $name) === 1) {
                $relative = 'mysql/'.$name;
            } else {
                continue;
            }

            File::copy($file->getPathname(), $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            $artifacts[$relative] = [
                'sha256' => hash_file('sha256', $file->getPathname()),
                'size' => $file->getSize(),
            ];
        }

        if (! isset($artifacts['homedir/app-data.zip']) || ! collect(array_keys($artifacts))->contains(fn (string $path) => str_starts_with($path, 'mysql/'))) {
            throw new RuntimeException('A migration package requires both the files and database backup.');
        }

        $manifest = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'source' => [
                'panel' => (string) config('app.name', 'dPanel'),
                'hostname' => gethostname() ?: null,
                'php' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
            ],
            'layout' => [
                'homedir' => 'homedir/',
                'databases' => 'mysql/',
                'metadata' => 'meta/',
            ],
            'artifacts' => $artifacts,
        ];

        File::put($stage.DIRECTORY_SEPARATOR.'meta'.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        File::put($stage.DIRECTORY_SEPARATOR.'meta'.DIRECTORY_SEPARATOR.'cpanel-layout', "homedir\nmysql\nmeta\n");

        $date = \DateTimeImmutable::createFromFormat('Ymd_His', $timestamp) ?: new \DateTimeImmutable;
        $archive = $runDirectory.DIRECTORY_SEPARATOR.'backup-'.$date->format('m.d.Y_H-i-s').'_dpanel.tar.gz';
        $result = Process::timeout(1200)->run(['tar', '-C', $stage, '-czf', $archive, 'homedir', 'mysql', 'meta']);
        File::deleteDirectory($stage);

        if (! $result->successful()) {
            throw new RuntimeException('Could not create migration archive: '.trim($result->errorOutput().' '.$result->output()));
        }

        return $archive;
    }
}
