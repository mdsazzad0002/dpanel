<?php

namespace App\Services\PanelLink;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ClonePackageBuilder;
use App\Services\SitePush\FileSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Builds what a linked panel takes from this one, reusing the existing
 * formats so the receiving side restores them with code that already exists:
 *
 *  - website: the Clone & Share / Push to Live package (files.zip + database.sql);
 *  - files:   a .tar.gz of only the given files (Push to Live "files only");
 *  - panel:   the full `dpanel:backup` archive (…_dpanel.tar.gz), the same
 *             file the "DPanel to DPanel" upload takes.
 */
class PanelPackageBuilder
{
    public function __construct(
        private readonly ClonePackageBuilder $clone,
        private readonly FileSyncService $files,
    ) {}

    /** @return array{path: string, extension: string, has_database: bool, warning: string, name: string} */
    public function build(User $actor, string $kind, ?Website $website, array $paths, string $packagePath): array
    {
        return match ($kind) {
            'website' => $this->website($actor, $this->requireWebsite($website), $packagePath),
            'files' => $this->changedFiles($this->requireWebsite($website), $paths, $packagePath),
            'panel' => $this->panel($packagePath),
            default => throw new RuntimeException("Unknown package kind {$kind}."),
        };
    }

    public static function extension(string $kind): string
    {
        return match ($kind) {
            'website' => 'zip',
            default => 'tar.gz',
        };
    }

    private function website(User $actor, Website $website, string $packagePath): array
    {
        $result = $this->clone->build($actor, $website, $packagePath);

        return ['path' => $packagePath, 'extension' => 'zip', 'has_database' => $result['has_database'], 'warning' => $result['warning'], 'name' => $website->domain.'.zip'];
    }

    private function changedFiles(Website $website, array $paths, string $packagePath): array
    {
        if ($paths === []) {
            throw new RuntimeException('No files to send.');
        }
        $this->files->pack($website, $paths, $packagePath);
        if (! is_file($packagePath)) {
            throw new RuntimeException('dRust did not write the files package.');
        }

        return ['path' => $packagePath, 'extension' => 'tar.gz', 'has_database' => false, 'warning' => '', 'name' => $website->domain.'-files.tar.gz'];
    }

    /**
     * Runs the panel's own backup and moves its archive into the package.
     * The backup also keeps a copy under storage/app/backups as usual.
     */
    private function panel(string $packagePath): array
    {
        $backupRoot = storage_path('app/backups');
        $before = is_dir($backupRoot) ? File::directories($backupRoot) : [];
        $exitCode = Artisan::call('dpanel:backup', ['--only' => 'all']);
        $output = trim(Artisan::output());

        $archive = null;
        foreach (array_diff(is_dir($backupRoot) ? File::directories($backupRoot) : [], $before) as $directory) {
            $found = glob($directory.'/backup-*_dpanel.tar.gz') ?: [];
            if ($found !== []) {
                $archive = $found[0];
            }
        }
        if ($archive === null) {
            throw new RuntimeException('The panel backup did not produce an archive'.($exitCode !== 0 ? ': '.$output : '.'));
        }
        File::copy($archive, $packagePath);

        return ['path' => $packagePath, 'extension' => 'tar.gz', 'has_database' => true, 'warning' => $exitCode !== 0 ? $output : '', 'name' => basename($archive)];
    }

    private function requireWebsite(?Website $website): Website
    {
        if (! $website instanceof Website) {
            throw new RuntimeException('Website not found on the source panel.');
        }

        return $website;
    }
}
