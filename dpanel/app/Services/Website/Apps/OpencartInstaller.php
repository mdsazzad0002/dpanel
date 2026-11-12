<?php

namespace App\Services\Website\Apps;

use App\Models\User;
use App\Models\Website;

/**
 * OpenCart 4 release zip from GitHub (checked against GitHub's SHA-256 when
 * the asset has one), its upload/ folder unpacked into the site root, then
 * install/cli_install.php. Afterwards the install folder is removed and
 * system/storage (logs, cache, sessions) moves next to the site root, since
 * the gateway does not read .htaccess.
 */
class OpencartInstaller extends BaseAppInstaller
{
    public const PHP = [
        '4.0' => ['min_php' => '8.0', 'max_php' => '8.2'],
        '4.1' => ['min_php' => '8.0', 'max_php' => '8.4'],
    ];

    private const MODULES = ['curl', 'gd', 'mbstring', 'mysqli', 'openssl', 'zip', 'zlib'];

    public function key(): string
    {
        return 'opencart';
    }

    public function definition(): array
    {
        return [
            'label' => 'OpenCart',
            'description' => 'OpenCart 4 online store, installed with an admin account and a database.',
            'document_root' => '',
            'database' => 'new',
            'fields' => ['admin_username', 'admin_email', 'admin_password'],
            'password_file' => 'config.php',
        ];
    }

    public function rules(): array
    {
        return [
            'admin_username' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'admin_email' => self::ADMIN_EMAIL,
            // cli_install.php reads "--option value" pairs, so a value may not start with "-".
            'admin_password' => ['required', 'string', 'min:12', 'max:40', 'regex:/^[^\x00-\x1F\x7F-][^\x00-\x1F\x7F]*$/u'],
        ];
    }

    public function versions(): array
    {
        return array_map(fn (array $release): array => [
            'value' => $release['version'],
            'label' => 'OpenCart '.$release['version'],
            ...self::rangeFor(self::PHP, $release['series']),
        ], $this->releases());
    }

    /** @return array<int, array{series: string, version: string, url: string, sha256: string|null}> */
    public function releases(): array
    {
        try {
            return $this->pickReleases($this->support->githubReleases('opencart/opencart', 'app-installer.opencart.github'));
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Could not load OpenCart releases from GitHub: '.$e->getMessage());
        }
    }

    /**
     * Newest stable 4.x release zip per minor series.
     *
     * @param  array<int, mixed>  $releases
     * @return array<int, array{series: string, version: string, url: string, sha256: string|null}>
     */
    public function pickReleases(array $releases): array
    {
        $candidates = [];
        foreach ($releases as $release) {
            if (! is_array($release) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                continue;
            }
            foreach ((array) ($release['assets'] ?? []) as $asset) {
                $url = (string) ($asset['browser_download_url'] ?? '');
                if (preg_match('/^opencart-((4\.\d+)\.\d+\.\d+)\.zip$/', (string) ($asset['name'] ?? ''), $m) === 1
                    && str_starts_with($url, 'https://github.com/opencart/opencart/releases/download/')) {
                    $candidates[] = ['series' => $m[2], 'version' => $m[1], 'url' => $url, 'sha256' => AppInstallSupport::assetSha256((array) $asset)];
                }
            }
        }

        return self::newestPerSeries($candidates);
    }

    public function plan(array $input, Website $website, ?User $actor): array
    {
        $release = collect($this->releases())->firstWhere('version', (string) ($input['version'] ?? ''));
        if ($release === null) {
            throw new AppInstallFailed('The selected OpenCart version is not available.');
        }

        return ['range' => self::rangeFor(self::PHP, $release['series']), 'label' => 'OpenCart '.$release['version'], 'package' => $release];
    }

    public function preflight(AppInstallContext $context): void
    {
        $this->support->requireModules($context, 'OpenCart', self::MODULES);
        $this->support->requireAbsent($this->storageDirectory($context), 'The OpenCart storage folder');
    }

    public function download(AppInstallContext $context): array
    {
        $package = $context->plan['package'];
        try {
            $tmp = $this->support->download($package['url'], $package['sha256']);
        } catch (AppInstallFailed $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
        try {
            $base = AppInstallSupport::zipBase($tmp, 'install/cli_install.php');
            if ($base === null) {
                return ['success' => false, 'output' => 'The download is not an OpenCart release package.'];
            }

            return $this->support->deployArchiveDirectory($context->siteOwner, $context->rootPath, $tmp, $base);
        } finally {
            @unlink($tmp);
        }
    }

    public function configure(AppInstallContext $context): void
    {
        $filemanager = $this->support->filemanager;
        $root = $context->rootPath;
        try {
            // cli_install.php writes into config.php files the package only ships as templates.
            foreach (['config.php', 'admin/config.php'] as $config) {
                $dist = preg_replace('/\.php$/', '-dist.php', $config);
                if (! $filemanager->fileExists("{$root}/{$config}") && $filemanager->fileExists("{$root}/{$dist}")) {
                    $filemanager->copyPath($context->siteOwner, "{$root}/{$dist}", "{$root}/{$config}");
                }
            }
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Preparing config.php failed: '.$e->getMessage());
        }

        $database = $context->database;
        $this->run($context, 'opencart_install', ['opencart' => [
            'username' => $context->input('admin_username'),
            'password' => $context->input('admin_password'),
            'email' => $context->input('admin_email'),
            'http_server' => $context->siteUrl().'/',
            'db_hostname' => $database['database_host'],
            'db_port' => $database['database_port'],
            'db_username' => $database['database_user'],
            'db_password' => $database['database_password'],
            'db_database' => $database['database_name'],
            'db_prefix' => 'oc_',
        ]], 'OpenCart installation');
        $this->removeIfPresent($context, 'install');

        try {
            $storage = $this->storageDirectory($context);
            if ($filemanager->directoryExists("{$root}/system/storage")) {
                $filemanager->movePath($context->siteOwner, "{$root}/system/storage", $storage);
                foreach (['config.php', 'admin/config.php'] as $config) {
                    $content = $filemanager->readTextFile($context->siteOwner, "{$root}/{$config}")['content'];
                    $filemanager->writeTextFile($context->siteOwner, "{$root}/{$config}", $this->withStorageDirectory($content, $storage.'/'));
                }
            }
        } catch (\Throwable $e) {
            throw new AppInstallFailed('OpenCart is installed, but moving system/storage out of the web root failed: '.$e->getMessage());
        }
    }

    public function complete(AppInstallContext $context): string
    {
        return " Log in at /admin as {$context->input('admin_username')}.";
    }

    /** Point DIR_STORAGE at $directory in an OpenCart config.php. */
    public function withStorageDirectory(string $config, string $directory): string
    {
        $line = "define('DIR_STORAGE', ".var_export($directory, true).');';
        $pattern = "/^\\s*define\\(\\s*'DIR_STORAGE'\\s*,.*\\);\\s*$/m";

        return preg_match($pattern, $config) === 1
            ? (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $config, 1)
            : rtrim($config)."\n".$line."\n";
    }

    public function storageDirectory(AppInstallContext $context): string
    {
        return $context->siblingPath('opencart-storage');
    }
}
