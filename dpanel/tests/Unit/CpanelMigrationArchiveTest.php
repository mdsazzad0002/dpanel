<?php

namespace Tests\Unit;

use App\Services\Backup\CpanelMigrationArchive;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class CpanelMigrationArchiveTest extends TestCase
{
    public function test_it_creates_a_versioned_cpanel_style_archive_with_checksums(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is unavailable.');
        }

        $run = storage_path('framework/testing/cpanel-archive-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($run);
        File::put($run.'/database-mysql.sql', 'SELECT 1;');

        $zip = new ZipArchive;
        $zip->open($run.'/app-data.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('storage/app/example.txt', 'example');
        $zip->close();

        try {
            $archive = app(CpanelMigrationArchive::class)->create($run, '20260805_123456');

            $this->assertSame('backup-08.05.2026_12-34-56_dpanel.tar.gz', basename($archive));
            $listing = shell_exec('tar -tzf '.escapeshellarg($archive));
            $this->assertStringContainsString('homedir/app-data.zip', (string) $listing);
            $this->assertStringContainsString('mysql/database-mysql.sql', (string) $listing);
            $this->assertStringContainsString('meta/manifest.json', (string) $listing);

            $this->artisan('serverpanel:restore', [
                'archive' => $archive,
                '--dry-run' => true,
            ])->expectsOutputToContain('Dry run completed')->assertSuccessful();

            $extract = $run.'/extract';
            File::ensureDirectoryExists($extract);
            exec('tar -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($extract), $output, $status);
            $this->assertSame(0, $status);
            $manifest = json_decode(File::get($extract.'/meta/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(CpanelMigrationArchive::FORMAT, $manifest['format']);
            $this->assertSame(hash_file('sha256', $extract.'/mysql/database-mysql.sql'), $manifest['artifacts']['mysql/database-mysql.sql']['sha256']);
        } finally {
            File::deleteDirectory($run);
        }
    }
}
