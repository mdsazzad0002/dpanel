<?php

namespace App\Services\Website;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\PreOverwriteBackupService;
use App\Services\EdgeGatewayReloader;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One-click installers for Joomla and CodeIgniter 4. Like the Laravel
 * installer, existing files go to the File Manager trash first, every shell
 * step runs in drust as the site owner on the website's PHP version, and the
 * panel records the database and switches the document root.
 */
class AppInstallService
{
    public const APPS = [
        'joomla' => [
            'label' => 'Joomla',
            'description' => 'Joomla CMS, installed with an administrator account and database.',
            'document_root' => '',
            'database' => 'required',
        ],
        'codeigniter' => [
            'label' => 'CodeIgniter 4',
            'description' => 'CodeIgniter 4 app starter from Composer, with .env configured.',
            'document_root' => 'public',
            'database' => 'optional',
        ],
    ];

    /** Minimum and maximum PHP for each Joomla major; unknown majors use the last entry's range. */
    public const JOOMLA_PHP = [
        '4' => ['min_php' => '7.2', 'max_php' => '8.3'],
        '5' => ['min_php' => '8.1', 'max_php' => '8.5'],
        '6' => ['min_php' => '8.3', 'max_php' => '8.5'],
    ];

    public const CODEIGNITER_PHP = ['min_php' => '8.1', 'max_php' => '8.5'];

    private const JOOMLA_RELEASES_URL = 'https://api.github.com/repos/joomla/joomla-cms/releases?per_page=40';

    public function __construct(
        private readonly WebsiteResolverService $resolver,
        private readonly FilemanagerService $filemanager,
        private readonly PreOverwriteBackupService $preOverwriteBackup,
        private readonly WebsiteTemplateCatalogService $templateCatalog,
        private readonly EdgeGatewayReloader $gatewayReloader,
        private readonly WebsiteDatabaseProvisioner $databases,
    ) {
    }

    /**
     * Installable versions for the page, each with the PHP version it would run on.
     *
     * @return array{versions: array<int, array<string, mixed>>, error: string|null}
     */
    public function catalog(string $app, Website $website): array
    {
        $current = (string) ($website->php_version ?? '');

        if ($app === 'codeigniter') {
            return ['versions' => [[
                'value' => '4',
                'label' => 'CodeIgniter 4 (latest)',
                ...self::CODEIGNITER_PHP,
                'php_version' => $this->resolvePhpVersion($current, self::CODEIGNITER_PHP),
            ]], 'error' => null];
        }

        try {
            $releases = $this->joomlaReleases();
        } catch (\Throwable $e) {
            return ['versions' => [], 'error' => 'Could not load Joomla releases from GitHub: '.$e->getMessage()];
        }

        return ['versions' => collect($releases)->map(function (array $release) use ($current): array {
            $range = $this->joomlaPhpRange($release['version']);

            return [
                'value' => $release['version'],
                'label' => 'Joomla '.$release['version'],
                ...$range,
                'php_version' => $this->resolvePhpVersion($current, $range),
            ];
        })->values()->all(), 'error' => null];
    }

    /**
     * Newest stable Full Package of each Joomla major, newest major first.
     *
     * @return array<int, array{version: string, url: string}>
     */
    public function joomlaReleases(): array
    {
        return Cache::remember('app-installer.joomla.releases', now()->addHours(6), function (): array {
            $response = Http::timeout(20)->acceptJson()->withHeaders(['User-Agent' => 'dPanel'])->get(self::JOOMLA_RELEASES_URL);
            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            return $this->pickJoomlaReleases((array) $response->json());
        });
    }

    /**
     * @param  array<int, mixed>  $releases  GitHub releases API payload
     * @return array<int, array{version: string, url: string}>
     */
    public function pickJoomlaReleases(array $releases): array
    {
        $byMajor = [];
        foreach ($releases as $release) {
            if (! is_array($release) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                continue;
            }
            foreach ((array) ($release['assets'] ?? []) as $asset) {
                $name = (string) ($asset['name'] ?? '');
                $url = (string) ($asset['browser_download_url'] ?? '');
                if (preg_match('/^Joomla_(\d+)\.(\d+)\.(\d+)-Stable-Full_Package\.zip$/', $name, $m) !== 1
                    || ! str_starts_with($url, 'https://github.com/joomla/joomla-cms/releases/download/')) {
                    continue;
                }
                $version = "{$m[1]}.{$m[2]}.{$m[3]}";
                if (! isset($byMajor[$m[1]]) || version_compare($version, $byMajor[$m[1]]['version'], '>')) {
                    $byMajor[$m[1]] = ['version' => $version, 'url' => $url];
                }
            }
        }
        krsort($byMajor, SORT_NUMERIC);

        return array_values($byMajor);
    }

    /**
     * @return array{min_php: string, max_php: string}
     */
    public function joomlaPhpRange(string $version): array
    {
        $major = explode('.', $version)[0];
        $ranges = self::JOOMLA_PHP;

        return $ranges[$major] ?? end($ranges);
    }

    /**
     * Keep the website's PHP version when it fits, otherwise the newest installed one in range.
     *
     * @param  array{min_php: string, max_php: string}  $range
     */
    public function resolvePhpVersion(string $current, array $range): ?string
    {
        $inRange = static fn (string $php): bool => version_compare($php, $range['min_php'], '>=')
            && version_compare($php, $range['max_php'], '<=');
        $available = array_values(array_filter($this->templateCatalog->availablePhpVersions(), $inRange));
        if ($available === []) {
            return null;
        }
        if (in_array($current, $available, true)) {
            return $current;
        }
        usort($available, static fn (string $a, string $b): int => version_compare($b, $a));

        return $available[0];
    }

    /**
     * @param  array<string, mixed>  $input  version, database_id ("new", "none" or an id), database_suffix,
     *                                       and for Joomla: site_name, admin_name, admin_username, admin_email, admin_password
     * @param  (\Closure(string): void)|null  $onStage
     * @return array{success: bool, message: string}
     */
    public function install(string $app, Website $website, array $input, ?User $actor = null, ?\Closure $onStage = null): array
    {
        $report = static function (string $stage) use ($onStage): void {
            if ($onStage !== null) {
                $onStage($stage);
            }
        };

        if (! isset(self::APPS[$app])) {
            return $this->fail('Unknown application.');
        }
        $meta = self::APPS[$app];

        $domain = $this->resolver->normalizeDomain((string) $website->domain);
        $rootPath = rtrim(str_replace('\\', '/', trim((string) $website->root_path)), '/');
        $siteOwner = trim((string) $website->site_owner);
        if ($domain === '' || $rootPath === '' || $siteOwner === '') {
            return $this->fail('Domain, root path or site owner is missing for this website.');
        }

        // Resolve version, PHP and database before anything destructive happens.
        $package = null;
        if ($app === 'joomla') {
            $version = (string) ($input['version'] ?? '');
            $package = collect($this->joomlaReleases())->firstWhere('version', $version);
            if ($package === null) {
                return $this->fail('The selected Joomla version is not available.');
            }
            $range = $this->joomlaPhpRange($version);
        } else {
            $range = self::CODEIGNITER_PHP;
        }
        $phpVersion = $this->resolvePhpVersion((string) $website->php_version, $range);
        if ($phpVersion === null) {
            return $this->fail("{$meta['label']} needs PHP {$range['min_php']}–{$range['max_php']}, but none is installed on this server.");
        }

        $databaseId = trim((string) ($input['database_id'] ?? 'new'));
        $useDatabase = $databaseId !== 'none';
        if (! $useDatabase && $meta['database'] === 'required') {
            return $this->fail("{$meta['label']} needs a database.");
        }
        $existing = null;
        if ($useDatabase && $databaseId !== 'new') {
            $existing = $this->databases->find($website, $actor, $databaseId);
            if ($existing === null) {
                return $this->fail('The selected database is not available or has no stored credentials.');
            }
        }

        $report('backing_up');
        if ($this->filemanager->directoryExists($rootPath)) {
            $this->preOverwriteBackup->snapshot($website, $app.'_install');
            if ($this->filemanager->directoryExists($rootPath)) {
                return $this->fail('Could not move the existing website files to the trash; nothing was changed.');
            }
        }

        $report('downloading');
        if ($app === 'joomla') {
            $download = $this->deployJoomlaPackage($siteOwner, $rootPath, $package['url']);
        } else {
            $download = $this->step($siteOwner, $rootPath, $phpVersion, 'create_project', ['stack' => 'codeigniter', 'version' => '4'], 960);
        }
        if (! $download['success']) {
            return $this->fail("{$meta['label']} download failed: ".$download['output']);
        }

        $database = null;
        if ($useDatabase) {
            $report('creating_database');
            $database = $this->databases->resolveConfig($domain, $existing, (string) ($input['database_suffix'] ?? ''), $app);
            $provision = $this->databases->provision($database);
            if (! $provision['success']) {
                return $this->fail($provision['output'] ?: 'Database provisioning failed.');
            }
            if ($existing === null) {
                $this->databases->record($domain, $database, $website, $actor);
            }
        }

        $report('configuring');
        if ($app === 'joomla') {
            $prefix = 'j'.Str::lower(Str::random(4)).'_';
            $step = $this->step($siteOwner, $rootPath, $phpVersion, 'joomla_install', ['joomla' => [
                'site_name' => (string) ($input['site_name'] ?? $domain),
                'admin_user' => (string) ($input['admin_name'] ?? 'Administrator'),
                'admin_username' => (string) ($input['admin_username'] ?? ''),
                'admin_password' => (string) ($input['admin_password'] ?? ''),
                'admin_email' => (string) ($input['admin_email'] ?? ''),
                'db_host' => $database['database_host'],
                'db_user' => $database['database_user'],
                'db_pass' => $database['database_password'],
                'db_name' => $database['database_name'],
                'db_prefix' => $prefix,
            ]], 660);
            if (! $step['success']) {
                return $this->fail('Joomla installation failed: '.$step['output']);
            }
            // The web installer must not stay reachable once the site is set up.
            if ($this->filemanager->directoryExists($rootPath.'/installation')) {
                $this->filemanager->deletePath($siteOwner, $rootPath.'/installation');
            }
        } else {
            try {
                $this->writeCodeIgniterEnvironment($siteOwner, $rootPath, $website, $database);
            } catch (\Throwable $e) {
                return $this->fail('Writing .env failed: '.$e->getMessage());
            }
        }

        $report('finalizing');
        $step = $this->step($siteOwner, $rootPath, $phpVersion, 'finalize', ['stack' => $app], 120);
        if (! $step['success']) {
            return $this->fail('Preparing file permissions failed: '.$step['output']);
        }
        $this->applyRuntime($website, $phpVersion, $meta['document_root']);

        $databaseNote = $database === null
            ? 'without a database'
            : sprintf('with %s database %s', $existing ? 'the existing' : 'a new', $database['database_name']);
        $label = $app === 'joomla' ? 'Joomla '.$package['version'] : $meta['label'];
        $login = $app === 'joomla' ? " Log in at /administrator as {$input['admin_username']}." : '';

        return [
            'success' => true,
            'message' => "{$label} installed on PHP {$phpVersion} {$databaseNote}.{$login}",
        ];
    }

    /**
     * Joomla ships as a flat zip. Download it here, then upload and extract it
     * as the site owner through the File Manager API.
     *
     * @return array{success: bool, output: string}
     */
    private function deployJoomlaPackage(string $siteOwner, string $rootPath, string $url): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'joomla_');
        if ($tmp === false) {
            return ['success' => false, 'output' => 'Cannot create a temporary file.'];
        }
        $remote = $rootPath.'/.dpanel-joomla-package.zip';

        try {
            $response = Http::timeout(300)->withHeaders(['User-Agent' => 'dPanel'])->sink($tmp)->get($url);
            if (! $response->successful() || filesize($tmp) < 1024 * 1024) {
                return ['success' => false, 'output' => 'Could not download '.basename($url).' (HTTP '.$response->status().').'];
            }
            $this->filemanager->uploadFile($siteOwner, $remote, $tmp);
            $this->filemanager->unzipFile($siteOwner, $remote, $rootPath);

            return ['success' => true, 'output' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        } finally {
            @unlink($tmp);
            try {
                $this->filemanager->deletePath($siteOwner, $remote);
            } catch (\Throwable) {
                // Cleanup must not hide the real result.
            }
        }
    }

    /**
     * Turn CodeIgniter's shipped `env` template into `.env`.
     *
     * @param  array<string, string>|null  $database
     */
    private function writeCodeIgniterEnvironment(string $siteOwner, string $rootPath, Website $website, ?array $database): void
    {
        $template = $this->filemanager->fileExists($rootPath.'/env')
            ? $this->filemanager->readTextFile($siteOwner, $rootPath.'/env')['content']
            : '';

        $scheme = (bool) $website->enable_ssl ? 'https' : 'http';
        $values = [
            'CI_ENVIRONMENT' => 'production',
            'app.baseURL' => $scheme.'://'.$this->resolver->normalizeDomain((string) $website->domain).'/',
            'encryption.key' => 'hex2bin:'.bin2hex(random_bytes(32)),
        ];
        if ($database !== null) {
            $values += [
                'database.default.hostname' => $database['database_host'],
                'database.default.database' => $database['database_name'],
                'database.default.username' => $database['database_user'],
                'database.default.password' => $database['database_password'],
                'database.default.DBDriver' => 'MySQLi',
                'database.default.port' => $database['database_port'],
            ];
        }

        $this->filemanager->writeTextFile($siteOwner, $rootPath.'/.env', $this->setCodeIgniterEnvValues($template, $values));
    }

    /**
     * CodeIgniter's env template has commented `# key = value` lines; enable
     * and set them, or append missing keys.
     *
     * @param  array<string, string>  $values
     */
    public function setCodeIgniterEnvValues(string $content, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.' = '.$this->codeIgniterEnvValue($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'\s*=.*$/m';
            $content = preg_match($pattern, $content) === 1
                ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content, 1)
                : rtrim($content)."\n".$line."\n";
        }

        return $content;
    }

    private function codeIgniterEnvValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:\/@-]*$/', $value) === 1) {
            return $value;
        }
        // CodeIgniter's DotEnv reads single-quoted values literally.
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }
        // Double quotes unescape \\ and \" but expand ${VAR} with no way to escape it.
        if (str_contains($value, '${')) {
            throw new \InvalidArgumentException('Value cannot be written to CodeIgniter .env (contains both \' and ${).');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{success: bool, output: string}
     */
    private function step(string $siteOwner, string $rootPath, string $phpVersion, string $action, array $extra, int $timeout): array
    {
        return $this->filemanager->runLaravelInstallerStep($siteOwner, $rootPath, $phpVersion, $action, $extra, $timeout);
    }

    /** Point the site (and its aliases) at the app's document root on the chosen PHP version. */
    private function applyRuntime(Website $website, string $phpVersion, string $documentRoot): void
    {
        $settings = ['php_version' => $phpVersion, 'start_directory' => $documentRoot, 'updated_at' => now()];
        $domains = [];
        DB::transaction(function () use ($website, $settings, &$domains): void {
            $query = Website::query()
                ->whereKey($website->id)
                ->orWhere(fn ($q) => $q->where('parent_id', $website->id)->whereIn('type', ['alis', 'alias']));
            $domains = $query->pluck('domain')->all();
            $query->update($settings);
        });

        $this->gatewayReloader->reloadDomains($domains);
    }

    /** @return array{success: bool, message: string} */
    private function fail(string $message): array
    {
        return ['success' => false, 'message' => Str::limit(trim($message), 4000)];
    }
}
