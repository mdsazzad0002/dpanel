<?php

namespace App\Services\Website\Apps;

use App\Models\CronJob;
use App\Models\Website;
use App\Services\Cron\CronSystemService;
use App\Services\Filemanager\FilemanagerService;
use App\Support\Platform\LocalCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Work the app installers share: drust installer steps as the site owner,
 * verified downloads, unpacking a package into the site root, and cron jobs.
 */
class AppInstallSupport
{
    public function __construct(
        public readonly FilemanagerService $filemanager,
        private readonly CronSystemService $cron,
        private readonly LocalCommand $local,
    ) {
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{success: bool, output: string}
     */
    public function step(AppInstallContext $context, string $action, array $extra, int $timeout): array
    {
        return $this->stepAs($context->siteOwner, $context->rootPath, $context->phpVersion, $action, $extra, $timeout);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{success: bool, output: string}
     */
    public function stepAs(string $siteOwner, string $rootPath, string $phpVersion, string $action, array $extra, int $timeout): array
    {
        return $this->filemanager->runLaravelInstallerStep($siteOwner, $rootPath, $phpVersion, $action, $extra, $timeout);
    }

    /**
     * Fail unless every module is loaded for the site's PHP (names as `php -m` prints them).
     *
     * @param  array<int, string>  $modules
     */
    public function requireModules(AppInstallContext $context, string $label, array $modules): void
    {
        $result = $this->step($context, 'php_modules', [], 60);
        $loaded = array_map('strtolower', array_map('trim', preg_split('/\R/', $result['output']) ?: []));
        $missing = array_values(array_filter($modules, fn (string $m): bool => ! in_array(strtolower($m), $loaded, true)));
        if (! $result['success'] || $missing !== []) {
            throw new AppInstallFailed("{$label} needs these PHP {$context->phpVersion} extensions, which are not loaded: "
                .($missing === [] ? implode(', ', $modules) : implode(', ', $missing)).'. Enable them in PHP Manager and try again.');
        }
    }

    /** Fail when a folder the app would create outside the site root already exists. */
    public function requireAbsent(string $path, string $what): void
    {
        if ($this->filemanager->directoryExists($path)) {
            throw new AppInstallFailed("{$what} {$path} already exists. Move or delete it in the File Manager first; nothing was changed.");
        }
    }

    /**
     * Download into a temporary file and check its SHA-256 when one is known.
     * The caller deletes the returned file.
     */
    public function download(string $url, ?string $sha256, int $minBytes = 1024 * 1024, string $extension = ''): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'app_');
        if ($tmp === false) {
            throw new AppInstallFailed('Cannot create a temporary file.');
        }
        if ($extension !== '') {
            @unlink($tmp);
            $tmp .= $extension;
        }

        try {
            $response = Http::timeout(300)->withHeaders(['User-Agent' => 'dPanel'])->sink($tmp)->get($url);
            if (! $response->successful() || filesize($tmp) < $minBytes) {
                throw new AppInstallFailed('Could not download '.basename($url).' (HTTP '.$response->status().').');
            }
            if ($sha256 !== null && ! hash_equals(strtolower($sha256), (string) hash_file('sha256', $tmp))) {
                throw new AppInstallFailed('The SHA-256 checksum of '.basename($url).' does not match the one the publisher lists; the download was rejected.');
            }

            return $tmp;
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e instanceof AppInstallFailed ? $e : new AppInstallFailed($e->getMessage(), 0, $e);
        }
    }

    /** First 64-hex token of a published checksum file (`<hash>  <name>`). */
    public function fetchSha256(string $url): string
    {
        $response = Http::timeout(30)->withHeaders(['User-Agent' => 'dPanel'])->get($url);
        if (! $response->successful() || preg_match('/\b([a-f0-9]{64})\b/i', $response->body(), $m) !== 1) {
            throw new AppInstallFailed('Could not load the checksum '.basename($url).' (HTTP '.$response->status().').');
        }

        return strtolower($m[1]);
    }

    /**
     * Check a detached OpenPGP signature with keys from $keysUrl, in a
     * throwaway keyring so the server's own keyrings are not touched.
     */
    public function verifySignature(string $file, string $signatureUrl, string $keysUrl): void
    {
        $home = sys_get_temp_dir().'/app_gpg_'.Str::random(12);
        $signature = $home.'/package.sig';
        try {
            @mkdir($home, 0700, true);
            foreach ([$signatureUrl => $signature, $keysUrl => $home.'/keys.asc'] as $url => $target) {
                $response = Http::timeout(60)->withHeaders(['User-Agent' => 'dPanel'])->get($url);
                if (! $response->successful()) {
                    throw new AppInstallFailed('Could not load '.basename($url).' to verify the download (HTTP '.$response->status().').');
                }
                file_put_contents($target, $response->body());
            }
            $gpg = ['gpg', '--batch', '--no-tty', '--homedir', $home];
            $import = $this->local->run([...$gpg, '--import', $home.'/keys.asc'], null, 60);
            $verify = $import->successful() ? $this->local->run([...$gpg, '--verify', $signature, $file], null, 120) : $import;
            if (! $verify->successful()) {
                throw new AppInstallFailed('The download signature could not be verified, so the package was rejected: '
                    .trim($verify->errorOutput() ?: $verify->output() ?: 'gpg is not available.'));
            }
        } catch (AppInstallFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AppInstallFailed('The download signature could not be verified (is gpg installed?): '.$e->getMessage(), 0, $e);
        } finally {
            $this->deleteLocalTree($home);
        }
    }

    /**
     * GitHub releases of $repo, cached for six hours.
     *
     * @return array<int, mixed>
     */
    public function githubReleases(string $repo, string $cacheKey): array
    {
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($repo): array {
            $response = Http::timeout(20)->acceptJson()->withHeaders(['User-Agent' => 'dPanel'])
                ->get("https://api.github.com/repos/{$repo}/releases?per_page=40");
            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            return (array) $response->json();
        });
    }

    /** The SHA-256 GitHub records for a release asset ("sha256:<hex>"), when it has one. */
    public static function assetSha256(array $asset): ?string
    {
        return preg_match('/^sha256:([a-f0-9]{64})$/i', (string) ($asset['digest'] ?? ''), $m) === 1 ? strtolower($m[1]) : null;
    }

    /**
     * The folder in a zip ("" or "name/" up to two levels) that holds $marker.
     */
    public static function zipBase(string $zipPath, string $marker): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            $best = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^((?:[^/]+/){0,2})'.preg_quote($marker, '#').'$#', $name, $m) === 1
                    && ! str_contains($m[1], '..')
                    && ($best === null || strlen($m[1]) < strlen($best))) {
                    $best = $m[1];
                }
            }

            return $best;
        } finally {
            $zip->close();
        }
    }

    /**
     * Unpack $base of a local zip or .tar.gz into the site root as the owner:
     * flat zips go up as they are, others are extracted here and repacked flat.
     *
     * @return array{success: bool, output: string}
     */
    public function deployArchiveDirectory(string $siteOwner, string $rootPath, string $archivePath, string $base): array
    {
        $isZip = ! str_ends_with(strtolower($archivePath), '.tar.gz');
        if ($base === '' && $isZip) {
            return $this->uploadArchive($siteOwner, $rootPath, $archivePath);
        }

        $workDir = sys_get_temp_dir().'/app_pkg_'.Str::random(12);
        $flatZip = $workDir.'.zip';
        try {
            if ($isZip) {
                $zip = new \ZipArchive();
                if ($zip->open($archivePath) !== true || ! $zip->extractTo($workDir)) {
                    return ['success' => false, 'output' => 'Cannot extract the package.'];
                }
                $zip->close();
            } else {
                (new \PharData($archivePath))->extractTo($workDir, null, true);
            }

            $source = realpath($workDir.'/'.rtrim($base, '/'));
            if ($source === false || ! str_starts_with($source.DIRECTORY_SEPARATOR, realpath($workDir).DIRECTORY_SEPARATOR)) {
                return ['success' => false, 'output' => 'Unexpected package layout.'];
            }
            $flat = new \ZipArchive();
            if ($flat->open($flatZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return ['success' => false, 'output' => 'Cannot repack the package.'];
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $flat->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1)));
                }
            }
            $flat->close();

            return $this->uploadArchive($siteOwner, $rootPath, $flatZip);
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        } finally {
            @unlink($flatZip);
            $this->deleteLocalTree($workDir);
        }
    }

    /**
     * Upload a local flat zip into the site root and extract it as the owner.
     *
     * @return array{success: bool, output: string}
     */
    public function uploadArchive(string $siteOwner, string $rootPath, string $localZip): array
    {
        $remote = $rootPath.'/.dpanel-app-package.zip';
        try {
            $this->filemanager->uploadFile($siteOwner, $remote, $localZip);
            $this->filemanager->unzipFile($siteOwner, $remote, $rootPath);

            return ['success' => true, 'output' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        } finally {
            try {
                $this->filemanager->deletePath($siteOwner, $remote);
            } catch (\Throwable) {
                // Cleanup must not hide the real result.
            }
        }
    }

    /**
     * Add a cron job for the app. The install already succeeded, so a failure
     * here only changes the message.
     *
     * @param  list<string>  $argv
     */
    public function addCron(Website $website, string $name, string $expression, array $argv, string $description, string $added, string $manual): string
    {
        $command = $this->local->shellLine($argv);
        $job = CronJob::query()->create([
            'id' => (string) Str::uuid(),
            'website_id' => $website->id,
            'domain' => (string) $website->domain,
            'name' => $name,
            'expression' => $expression,
            'command' => $command,
            'status' => 'active',
            'description' => $description,
        ]);
        try {
            $this->cron->sync($job, $website);
        } catch (\Throwable $e) {
            $job->delete();

            return " {$manual}: {$command} — automatic setup failed: {$e->getMessage()}";
        }

        return ' '.$added;
    }

    public function phpCli(string $phpVersion): string
    {
        return $this->local->phpCli($phpVersion);
    }

    public function deleteLocalTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
