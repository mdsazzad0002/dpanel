<?php

namespace App\Services\Website\Apps;

use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Str;

/**
 * PrestaShop release zip from GitHub (checked against GitHub's SHA-256 when
 * the asset has one). The release zip wraps the shop in an inner
 * prestashop.zip; that is unpacked, then install/index_cli.php runs. The
 * install folder is removed and the admin folder renamed, as PrestaShop asks.
 */
class PrestashopInstaller extends BaseAppInstaller
{
    public const PHP = [
        '8' => ['min_php' => '7.2', 'max_php' => '8.1'],
        '9' => ['min_php' => '8.1', 'max_php' => '8.4'],
    ];

    private const MODULES = ['curl', 'dom', 'fileinfo', 'gd', 'intl', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'simplexml', 'zip'];

    public function key(): string
    {
        return 'prestashop';
    }

    /** From the folders' own .htaccess (Require all denied); img, js, modules and themes only serve assets. */
    public function deniedPaths(): array
    {
        return ['/app', '/bin', '/cache', '/classes', '/config', '/controllers', '/docs', '/download', '/localization', '/mails', '/override', '/src', '/upload', '/var', '/vendor'];
    }

    /**
     * Root .htaccess denies composer.lock; modules/.htaccess denies these
     * types (and .php, a no-PHP folder already); themes/.htaccess allows only
     * assets, so its common non-asset types are denied.
     */
    public function deniedExtensions(): array
    {
        $modules = ['log', 'txt', 'lock', 'json', 'yml', 'yaml', 'tpl', 'twig'];
        $themes = ['tpl', 'twig', 'yml', 'yaml', 'json', 'xml', 'txt', 'log', 'lock', 'md', 'html', 'scss', 'sh'];

        return [
            'lock',
            ...array_map(fn (string $ext) => "/modules/*.{$ext}", $modules),
            ...array_map(fn (string $ext) => "/themes/*.{$ext}", $themes),
        ];
    }

    public function noPhpPaths(): array
    {
        return ['/img', '/js', '/modules', '/themes'];
    }

    public function definition(): array
    {
        return [
            'label' => 'PrestaShop',
            'description' => 'PrestaShop online store, installed with a back-office account and a database.',
            'document_root' => '',
            'database' => 'new',
            'fields' => ['site_name', 'admin_name', 'admin_email', 'admin_password'],
            'password_file' => 'app/config/parameters.php',
        ];
    }

    public function rules(): array
    {
        // PrestaShop's isName: no digits or the punctuation it rejects.
        $name = ['required', 'string', 'max:64', 'regex:/^[^0-9!<>,;?=+()@#"°{}_$%:¤|\x00-\x1F\x7F]+$/u'];

        return [
            'site_name' => ['required', 'string', 'max:64', 'regex:/^[^<>;=#{}\x00-\x1F\x7F]+$/u'],
            'admin_name' => $name,
            'admin_email' => self::ADMIN_EMAIL,
            'admin_password' => ['required', 'string', 'min:12', 'max:72', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
        ];
    }

    public function versions(): array
    {
        return array_map(fn (array $release): array => [
            'value' => $release['version'],
            'label' => 'PrestaShop '.$release['version'],
            ...self::rangeFor(self::PHP, $release['series']),
        ], $this->releases());
    }

    /** @return array<int, array{series: string, version: string, url: string, sha256: string|null}> */
    public function releases(): array
    {
        try {
            return $this->pickReleases($this->support->githubReleases('PrestaShop/PrestaShop', 'app-installer.prestashop.github'));
        } catch (\Throwable $e) {
            throw new AppInstallFailed('Could not load PrestaShop releases from GitHub: '.$e->getMessage());
        }
    }

    /**
     * Newest stable 8.x and 9.x release zip.
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
                if (preg_match('/^prestashop_((8|9)\.\d+\.\d+)\.zip$/', (string) ($asset['name'] ?? ''), $m) === 1
                    && str_starts_with($url, 'https://github.com/PrestaShop/PrestaShop/releases/download/')) {
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
            throw new AppInstallFailed('The selected PrestaShop version is not available.');
        }

        return ['range' => self::rangeFor(self::PHP, $release['series']), 'label' => 'PrestaShop '.$release['version'], 'package' => $release];
    }

    public function preflight(AppInstallContext $context): void
    {
        $this->support->requireModules($context, 'PrestaShop', self::MODULES);
    }

    public function download(AppInstallContext $context): array
    {
        $package = $context->plan['package'];
        $inner = null;
        try {
            $tmp = $this->support->download($package['url'], $package['sha256']);
        } catch (AppInstallFailed $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
        try {
            $inner = $this->extractInnerZip($tmp);
            $base = $inner === null ? null : AppInstallSupport::zipBase($inner, 'install/index_cli.php');
            if ($base === null) {
                return ['success' => false, 'output' => 'The download is not a PrestaShop release package.'];
            }

            return $this->support->deployArchiveDirectory($context->siteOwner, $context->rootPath, $inner, $base);
        } finally {
            @unlink($tmp);
            if ($inner !== null) {
                @unlink($inner);
            }
        }
    }

    /** The release zip carries the shop as prestashop.zip; older ones are the shop itself. */
    public function extractInnerZip(string $zipPath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            $target = tempnam(sys_get_temp_dir(), 'ps_inner_');
            $stream = $zip->getStream('prestashop.zip');
            if ($stream === false) {
                @unlink($target);

                return AppInstallSupport::zipBase($zipPath, 'install/index_cli.php') !== null && copy($zipPath, $target) ? $target : null;
            }
            $out = fopen($target, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            return $target;
        } finally {
            $zip->close();
        }
    }

    public function configure(AppInstallContext $context): void
    {
        $database = $context->database;
        [$first, $last] = array_pad(preg_split('/\s+/', trim($context->input('admin_name', 'Shop Admin')), 2), 2, 'Admin');
        $this->run($context, 'prestashop_install', ['prestashop' => [
            'domain' => $context->domain,
            'ssl' => (bool) $context->website->enable_ssl,
            'shop_name' => $context->input('site_name', $context->domain),
            'firstname' => $first,
            'lastname' => $last,
            'email' => $context->input('admin_email'),
            'password' => $context->input('admin_password'),
            'db_server' => $database['database_host'].':'.$database['database_port'],
            'db_user' => $database['database_user'],
            'db_password' => $database['database_password'],
            'db_name' => $database['database_name'],
            'db_prefix' => 'ps'.Str::lower(Str::random(3)).'_',
        ]], 'PrestaShop installation');

        $this->removeIfPresent($context, 'install');
        $this->removeIfPresent($context, 'var/cache/prod');
        $admin = $this->adminFolder($context);
        if ($this->support->filemanager->directoryExists($context->rootPath.'/admin')) {
            $this->support->filemanager->movePath($context->siteOwner, $context->rootPath.'/admin', $context->rootPath.'/'.$admin);
        }
    }

    public function complete(AppInstallContext $context): string
    {
        return " Log in at /{$this->adminFolder($context)} as {$context->input('admin_email')}.";
    }

    /** Stable per install run so configure() and complete() agree. */
    private function adminFolder(AppInstallContext $context): string
    {
        return $context->state['admin_folder'] ??= 'admin'.Str::lower(Str::random(10));
    }
}
