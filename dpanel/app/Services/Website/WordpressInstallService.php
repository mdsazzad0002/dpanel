<?php

namespace App\Services\Website;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\PreOverwriteBackupService;
use App\Services\Filemanager\FilemanagerService;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use App\Services\EdgeGatewayReloader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class WordpressInstallService
{
    /**
     * PHP range each WordPress branch is compatible with, newest first — from
     * make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/.
     * Branches newer than the table use its first entry.
     */
    public const WORDPRESS_PHP = [
        '7.1' => ['min_php' => '7.4', 'max_php' => '8.5'],
        '7.0' => ['min_php' => '7.4', 'max_php' => '8.5'],
        '6.9' => ['min_php' => '7.2', 'max_php' => '8.5'],
        '6.8' => ['min_php' => '7.2', 'max_php' => '8.4'],
        '6.7' => ['min_php' => '7.2', 'max_php' => '8.4'],
        '6.6' => ['min_php' => '7.2', 'max_php' => '8.3'],
        '6.5' => ['min_php' => '7.0', 'max_php' => '8.3'],
        '6.4' => ['min_php' => '7.0', 'max_php' => '8.3'],
        '6.3' => ['min_php' => '7.0', 'max_php' => '8.2'],
        '6.2' => ['min_php' => '5.6', 'max_php' => '8.2'],
        '6.1' => ['min_php' => '5.6', 'max_php' => '8.2'],
        '6.0' => ['min_php' => '5.6', 'max_php' => '8.1'],
        '5.9' => ['min_php' => '5.6', 'max_php' => '8.1'],
        '5.8' => ['min_php' => '5.6', 'max_php' => '8.0'],
        '5.7' => ['min_php' => '5.6', 'max_php' => '8.0'],
        '5.6' => ['min_php' => '5.6', 'max_php' => '8.0'],
        '5.5' => ['min_php' => '5.6', 'max_php' => '7.4'],
        '5.4' => ['min_php' => '5.6', 'max_php' => '7.4'],
        '5.3' => ['min_php' => '5.6', 'max_php' => '7.4'],
        '5.2' => ['min_php' => '5.6', 'max_php' => '7.3'],
        '5.1' => ['min_php' => '5.2', 'max_php' => '7.3'],
        '5.0' => ['min_php' => '5.2', 'max_php' => '7.3'],
        '4.9' => ['min_php' => '5.2', 'max_php' => '7.2'],
        '4.8' => ['min_php' => '5.2', 'max_php' => '7.1'],
        '4.7' => ['min_php' => '5.2', 'max_php' => '7.1'],
    ];

    public function __construct(
        private readonly WebsiteResolverService $resolver,
        private readonly FilemanagerService $filemanagerService,
        private readonly PreOverwriteBackupService $preOverwriteBackup,
        private readonly WebsiteDatabaseProvisioner $databases,
        private readonly EdgeGatewayReloader $gatewayReloader,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getWordPressVersionOptions(): array
    {
        try {
            return Cache::remember('wordpress.version.options.v3', now()->addHours(6), function (): array {
                // stable-check lists every release ever made; version-check/1.7
                // only returns the handful of currently-offered updates.
                $response = Http::timeout(10)->connectTimeout(4)->get('https://api.wordpress.org/core/stable-check/1.0/');
                $releases = $response->successful() ? $response->json() : null;
                if (! is_array($releases) || $releases === []) {
                    return ['latest'];
                }

                // Newest patch of each major.minor branch from 4.7 on (the oldest
                // in WORDPRESS_PHP), newest first.
                $versions = collect(array_keys($releases))
                    ->map(fn ($version): string => $this->normalizeWordPressVersion((string) $version))
                    ->filter(fn (string $version): bool => $version !== 'latest' && version_compare($version, '4.7', '>='))
                    ->groupBy(fn (string $version): string => implode('.', array_slice(explode('.', $version), 0, 2)))
                    ->map(fn ($branch): string => $branch->sort(fn (string $a, string $b): int => version_compare($b, $a))->first())
                    ->values()
                    ->sort(fn (string $a, string $b): int => version_compare($b, $a))
                    ->values()
                    ->all();

                return array_values(array_merge(['latest'], $versions));
            });
        } catch (\Throwable $e) {
            return ['latest'];
        }
    }

    /**
     * Compatible PHP range for a WordPress version; "latest" is the newest release.
     *
     * @return array{min_php: string, max_php: string}
     */
    public function wordpressPhpRange(string $version): array
    {
        if ($this->normalizeWordPressVersion($version) === 'latest') {
            $version = $this->getWordPressVersionOptions()[1] ?? array_key_first(self::WORDPRESS_PHP);
        }
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        if (isset(self::WORDPRESS_PHP[$branch])) {
            return self::WORDPRESS_PHP[$branch];
        }

        $ranges = self::WORDPRESS_PHP;

        return version_compare($branch, (string) array_key_first($ranges), '>') ? reset($ranges) : end($ranges);
    }

    /**
     * Installable versions, each with its PHP range and the PHP it would run on.
     *
     * @return array<int, array{value: string, min_php: string, max_php: string, php_version: string|null}>
     */
    public function versionCatalog(string $currentPhp): array
    {
        return array_map(function (string $version) use ($currentPhp): array {
            $range = $this->wordpressPhpRange($version);

            return [
                'value' => $version,
                ...$range,
                'php_version' => app(AppInstallService::class)->resolvePhpVersion($currentPhp, $range),
            ];
        }, $this->getWordPressVersionOptions());
    }

    /**
     * Version of the WordPress already in the site root (wp-includes/version.php), or null.
     */
    public function installedVersion(array $website): ?string
    {
        $rootPath = $this->resolveInstallationRoot($website);
        $siteOwner = (string) ($website['site_owner'] ?? $this->resolver->extractSiteOwnerFromRootPath($rootPath));
        try {
            $content = $this->filemanagerService->readTextFile($siteOwner, rtrim($rootPath, '/').'/wp-includes/version.php')['content'];
        } catch (\Throwable) {
            return null;
        }

        return preg_match('/\$wp_version\s*=\s*[\'"](\d+\.\d+(?:\.\d+)?)/', $content, $match) === 1 ? $match[1] : null;
    }

    /**
     * This website's MariaDB databases — WordPress can't run on PostgreSQL.
     *
     * @return array<int, array{id: string, database_name: string, database_user: string, domain: string, engine: string}>
     */
    public function selectableDatabases(Website $website, ?User $actor): array
    {
        return $this->databases->selectable($website, $actor);
    }

    /** @return array{database_name: string, database_user: string, database_host: string, charset: string, collation: string, suffix: string} */
    public function previewNewDatabase(Website $website): array
    {
        return $this->databases->preview($website, 'wp');
    }

    /**
     * Inspect a website root directory without using database or app config state.
     *
     * @return array{
     *   exists: bool,
     *   is_directory: bool,
     *   is_empty: bool,
     *   detected_app: string,
     *   wordpress: bool,
     *   laravel: bool,
     *   codeigniter: bool,
     *   first_directory_exists: bool,
     *   first_directory: string,
     *   summary: string,
     *   signals: array<string, array<int, string>>
     * }
     */
    public function inspectRootDirectory(string $rootPath): array
    {
        $normalizedRootPath = rtrim(str_replace('\\', '/', trim($rootPath)), '/');
        $result = [
            'exists' => false,
            'is_directory' => false,
            'is_empty' => false,
            'detected_app' => 'missing',
            'wordpress' => false,
            'laravel' => false,
            'codeigniter' => false,
            'first_directory_exists' => false,
            'first_directory' => '',
            'summary' => 'Root path is missing.',
            'signals' => [
                'wordpress' => [],
                'laravel' => [],
                'codeigniter' => [],
            ],
        ];

        if ($normalizedRootPath === '' || ! file_exists($normalizedRootPath)) {
            return $result;
        }

        $result['exists'] = true;
        $result['is_directory'] = is_dir($normalizedRootPath);

        if (! $result['is_directory']) {
            $result['summary'] = 'Root path exists but is not a directory.';

            return $result;
        }

        $entries = @scandir($normalizedRootPath);
        $children = is_array($entries)
            ? array_values(array_filter($entries, fn (string $entry): bool => $entry !== '.' && $entry !== '..'))
            : [];

        $result['is_empty'] = count($children) === 0;
        $result['first_directory'] = (string) ($this->firstExistingDirectory($normalizedRootPath, ['wp-admin', 'wp-content', 'wp-includes', 'artisan', 'app', 'application', 'system', 'public']) ?? '');
        $result['first_directory_exists'] = $result['first_directory'] !== '';

        $signals = [
            'wordpress' => $this->detectWordPressSignals($normalizedRootPath),
            'laravel' => $this->detectLaravelSignals($normalizedRootPath),
            'codeigniter' => $this->detectCodeIgniterSignals($normalizedRootPath),
        ];
        $result['signals'] = $signals;
        $result['wordpress'] = count($signals['wordpress']) > 0;
        $result['laravel'] = count($signals['laravel']) > 0;
        $result['codeigniter'] = count($signals['codeigniter']) > 0;

        if ($result['wordpress']) {
            $result['detected_app'] = 'wordpress';
            $result['summary'] = 'WordPress files detected in the first directory scan.';
            return $result;
        }

        if ($result['laravel']) {
            $result['detected_app'] = 'laravel';
            $result['summary'] = 'Laravel project detected.';
            return $result;
        }

        if ($result['codeigniter']) {
            $result['detected_app'] = 'codeigniter';
            $result['summary'] = 'CodeIgniter project detected.';
            return $result;
        }

        if ($result['is_empty']) {
            $result['detected_app'] = 'empty';
            $result['summary'] = 'Directory exists but is empty.';
            return $result;
        }

        $result['detected_app'] = 'unknown';
        $result['summary'] = 'Directory exists, but no common application files were detected.';

        return $result;
    }

    public function hasWordPressFiles(string $rootPath): bool
    {
        return ($this->inspectRootDirectory($rootPath)['detected_app'] ?? 'missing') === 'wordpress';
    }

    /** @param array<string, mixed> $website */
    public function resolveInstallationRoot(array $website): string
    {
        $rootPath = rtrim(str_replace('\\', '/', trim((string) ($website['root_path'] ?? ''))), '/');
        $startDirectory = trim(str_replace('\\', '/', (string) ($website['start_directory'] ?? '')), '/');
        if ($rootPath === '' || $startDirectory === '' || $startDirectory === '.') {
            return $rootPath;
        }
        if (str_contains($startDirectory, '..') || preg_match('/^[A-Za-z0-9._\/-]+$/', $startDirectory) !== 1) {
            throw new \InvalidArgumentException('Invalid website start directory.');
        }

        return $rootPath.'/'.$startDirectory;
    }

    /** @param array<string, mixed> $website */
    private function backupExistingRoot(array $website, string $reason): void
    {
        $model = Website::query()->find($website['id'] ?? null);
        if (! $model) {
            Log::warning('Skipped pre-install backup: website model not found', [
                'website_id' => (string) ($website['id'] ?? ''),
                'reason' => $reason,
            ]);

            return;
        }

        $this->preOverwriteBackup->snapshot($model, $reason);
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $input
     * @param (\Closure(string): void)|null $onStage optional progress callback, invoked with
     *        'downloading', 'creating_database', 'connecting_database' as the install advances
     * @return array{success: bool, message: string, website: array<string, mixed>|null, database_request: array<string, mixed>|null}
     */
    public function install(array $website, array $input, ?User $actor = null, ?\Closure $onStage = null): array
    {
        $report = static function (string $stage) use ($onStage): void {
            if ($onStage !== null) {
                $onStage($stage);
            }
        };

        $domain = $this->resolver->normalizeDomain((string) ($website['domain'] ?? ''));
        $rootPath = $this->resolveInstallationRoot($website);
        $projectRoot = (string) ($website['project_root'] ?? $this->resolver->deriveProjectRootPath($rootPath, $domain));
        $phpVersion = (string) ($website['php_version'] ?? '8.0');
        $wordpressVersion = $this->normalizeWordPressVersion((string) ($input['wordpress_version'] ?? 'latest'));
        $siteOwner = (string) ($website['site_owner'] ?? $this->resolver->extractSiteOwnerFromRootPath($projectRoot));
        $databasePrefix = $this->normalizeWordPressDatabasePrefix(
            (string) ($input['database_prefix'] ?? ($website['wordpress_db_prefix'] ?? '')),
            $domain
        );

        if ($domain === '' || $rootPath === '') {
            return $this->fail('Domain or root path is missing for WordPress installation.');
        }

        // Files already there: fit PHP to the installed version, not the selected one.
        $hasWordPress = $this->hasWordPressFiles($rootPath);
        $phpFor = $hasWordPress ? ($this->installedVersion($website) ?? $wordpressVersion) : $wordpressVersion;
        $phpRange = $this->wordpressPhpRange($phpFor);
        $targetPhp = app(AppInstallService::class)->resolvePhpVersion($phpVersion, $phpRange);
        if ($targetPhp === null) {
            $label = $phpFor === 'latest' ? 'WordPress' : 'WordPress '.$phpFor;

            return $this->fail("{$label} needs PHP {$phpRange['min_php']}–{$phpRange['max_php']}, but none is installed on this server.");
        }

        // database_id: an existing database id, "new", or absent (older callers:
        // reuse the domain's MariaDB database if there is one). Resolved before
        // any files are touched so a bad choice fails fast.
        $databaseId = trim((string) ($input['database_id'] ?? ''));
        $newDatabase = null;
        if ($databaseId === 'new') {
            $existingDatabaseRequest = null;
            $newDatabase = $this->databases->resolveConfig($domain, null, (string) ($input['database_suffix'] ?? ''), 'wp');
        } elseif ($databaseId !== '') {
            $websiteModel = Website::query()->find($website['id'] ?? null);
            $existingDatabaseRequest = $websiteModel ? $this->databases->find($websiteModel, $actor, $databaseId) : null;
            if ($existingDatabaseRequest === null) {
                return $this->fail('The selected database is not available for this website. WordPress needs a MySQL/MariaDB database.');
            }
        } else {
            $existingDatabaseRequest = DatabaseRequest::query()
                ->where('domain', $domain)
                ->where('engine', DatabaseRequest::ENGINE_MARIADB)
                ->first();
        }

        try {
            if ($siteOwner !== '') {
                $this->applyWebsiteFilesystemIsolation($siteOwner, $projectRoot, $rootPath);
            }

            if (! $hasWordPress) {
                // WordPress install requires an empty document root; a non-empty
                // root (leftover files, a placeholder README, a previous app)
                // would otherwise be rejected outright. Move whatever is there
                // into the File Manager trash first, the same safety net
                // Clone/Import/Git-clone already rely on, so nothing is
                // silently destroyed and it stays restorable.
                if (! ($this->inspectRootDirectory($rootPath)['is_empty'] ?? true)) {
                    $this->backupExistingRoot($website, 'wordpress_install');
                }

                $report('downloading');
                $installerResult = $this->installWordPressApplication($rootPath, $wordpressVersion, $siteOwner);
                if (! $installerResult['installed']) {
                    $message = trim((string) ($installerResult['message'] ?? ''));

                    return $this->fail($message !== '' ? $message : 'WordPress installation failed.');
                }
            }

            $report('creating_database');
            $databaseConfig = $newDatabase !== null
                ? [...$newDatabase, 'database_prefix' => $databasePrefix, 'table_prefix' => $databasePrefix.'_']
                : $this->resolveWordPressDatabaseConfig($databasePrefix, $domain, $existingDatabaseRequest);
            $databaseProvisionResult = $this->provisionWordPressDatabase($databaseConfig);
            if (! $databaseProvisionResult['success']) {
                return $this->fail(trim((string) ($databaseProvisionResult['output'] ?? '')) ?: 'WordPress database provisioning failed.');
            }

            $report('connecting_database');
            $configResult = $this->writeWordPressConfig($rootPath, $databaseConfig, $databaseConfig['table_prefix'], $siteOwner);
            if (! $configResult['success']) {
                return $this->fail(trim((string) ($configResult['message'] ?? '')) ?: 'WordPress wp-config.php update failed.');
            }
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $runtimeStatus = strtolower((string) ($website['status'] ?? '')) === 'disabled'
            ? 'disabled'
            : 'live';

        $databaseRequest = $this->syncDatabaseRequest(
            $domain,
            $databaseConfig,
            $website['assigned_user_id'] ?? null,
            $actor,
            $existingDatabaseRequest ?? null
        );
        $phpNote = '';
        if ($targetPhp !== $phpVersion) {
            $this->applyPhpVersion((string) ($website['id'] ?? ''), $targetPhp);
            $phpNote = " PHP switched from {$phpVersion} to {$targetPhp}.";
        }
        $website = array_merge($website, [
            'wordpress_db_prefix' => $databasePrefix,
            'status' => $runtimeStatus,
            'php_version' => $targetPhp,
        ]);

        return [
            'success' => true,
            'message' => ($existingDatabaseRequest ? 'WordPress configuration updated and database synced successfully.' : 'WordPress installed and configured successfully.').$phpNote,
            'website' => $website,
            'database_request' => $databaseRequest?->toArray(),
        ];
    }

    /**
     * Switch the website (and its aliases) to a WordPress-compatible PHP.
     */
    private function applyPhpVersion(string $websiteId, string $phpVersion): void
    {
        if ($websiteId === '') {
            return;
        }
        $domains = [];
        DB::transaction(function () use ($websiteId, $phpVersion, &$domains): void {
            $query = Website::query()
                ->whereKey($websiteId)
                ->orWhere(fn ($q) => $q->where('parent_id', $websiteId)->whereIn('type', ['alis', 'alias']));
            $domains = $query->pluck('domain')->all();
            $query->update(['php_version' => $phpVersion, 'updated_at' => now()]);
        });

        $this->gatewayReloader->reloadDomains($domains);
    }

    /**
     * @return array{success: bool, message: string, website: array<string, mixed>|null, database_request: array<string, mixed>|null}
     */
    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'website' => null,
            'database_request' => null,
        ];
    }

    private function normalizeWordPressVersion(string $version): string
    {
        $normalized = strtolower(trim($version));
        if ($normalized === '' || $normalized === 'latest') {
            return 'latest';
        }

        if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $normalized) === 1) {
            return $normalized;
        }

        return 'latest';
    }

    private function normalizeWordPressDatabasePrefix(string $prefix, string $domain = ''): string
    {
        $normalized = strtolower(trim($prefix));
        if ($normalized === '') {
            $normalized = (string) Str::of(explode('.', $this->resolver->normalizeDomain($domain))[0] ?? '')
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->limit(20, '');
        }

        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? substr($normalized, 0, 32) : 'wp';
    }

    /**
     * @param array<string, string> $databaseConfig
     * @return array<string, string>
     */
    private function resolveWordPressDatabaseConfig(string $databasePrefix, string $domain, ?DatabaseRequest $existingDatabaseRequest = null): array
    {
        $base = $this->normalizeWordPressDatabasePrefix($databasePrefix, $domain);

        if ($existingDatabaseRequest !== null) {
            $storedName = trim((string) $existingDatabaseRequest->database_name);
            $storedUser = trim((string) $existingDatabaseRequest->database_user);
            $storedPassword = trim((string) $existingDatabaseRequest->database_password);
            $storedHost = trim((string) $existingDatabaseRequest->database_host);

            if ($storedName !== '' && $storedUser !== '' && $storedPassword !== '') {
                return [
                    'database_prefix' => $base,
                    'database_name' => $storedName,
                    'database_user' => $storedUser,
                    'database_password' => $storedPassword,
                    'database_host' => $storedHost !== '' ? $storedHost : (string) config('database.connections.mysql.host', config('database.connections.mariadb.host', '127.0.0.1')),
                    'database_port' => (string) config('database.connections.mysql.port', config('database.connections.mariadb.port', '3306')),
                    'charset' => (string) ($existingDatabaseRequest->charset ?: 'utf8mb4'),
                    'collation' => (string) ($existingDatabaseRequest->collation ?: 'utf8mb4_unicode_ci'),
                    'table_prefix' => $base.'_',
                ];
            }
        }

        return [
            'database_prefix' => $base,
            'database_name' => $this->makeWordPressDatabaseIdentifier($base, 'db'),
            'database_user' => $this->makeWordPressDatabaseIdentifier($base, 'user'),
            'database_password' => $this->generateWordPressDatabasePassword(),
            'database_host' => (string) config('database.connections.mysql.host', config('database.connections.mariadb.host', '127.0.0.1')),
            'database_port' => (string) config('database.connections.mysql.port', config('database.connections.mariadb.port', '3306')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'table_prefix' => $base.'_',
        ];
    }

    private function makeWordPressDatabaseIdentifier(string $prefix, string $suffix): string
    {
        $identifier = trim($prefix.'_'.$suffix, '_');
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '_', $identifier) ?? $identifier;

        return substr($identifier, 0, 64);
    }

    private function generateWordPressDatabasePassword(): string
    {
        try {
            return bin2hex(random_bytes(16)).'!A1';
        } catch (\Throwable $e) {
            return Str::random(24).'!A1';
        }
    }

    private function runtimeDatabaseScriptPath(): string
    {
        foreach (ScriptPathResolver::repositorySearchPaths() as $root) {
            $candidate = rtrim((string) $root, '/').'/scripts/database-request.sh';
            if (trim($candidate) !== '') {
                return $candidate;
            }
        }

        return rtrim(dirname(base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dscript'.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'database-request.sh';
    }

    /**
     * @param array<string, string> $databaseConfig
     * @return array{ran: bool, success: bool, output: string}
     */
    private function provisionWordPressDatabase(array $databaseConfig): array
    {
        $scriptPath = $this->runtimeDatabaseScriptPath();
        $result = app(ScriptExecutionGateway::class)->execute($scriptPath, [
            'create',
            (string) ($databaseConfig['database_name'] ?? ''),
            (string) ($databaseConfig['database_user'] ?? ''),
            (string) ($databaseConfig['database_password'] ?? ''),
            (string) ($databaseConfig['database_host'] ?? '127.0.0.1'),
            (string) ($databaseConfig['database_port'] ?? '3306'),
            (string) ($databaseConfig['charset'] ?? 'utf8mb4'),
            (string) ($databaseConfig['collation'] ?? 'utf8mb4_unicode_ci'),
        ]);
        $message = trim((string) ($result['output'] ?? ''));

        return [
            'ran' => (bool) ($result['ran'] ?? true),
            'success' => (bool) ($result['success'] ?? false),
            'output' => $message !== '' ? $message : ((bool) ($result['success'] ?? false) ? 'Database provisioning completed.' : 'Database provisioning failed.'),
        ];
    }

    /**
     * @param array<string, string> $databaseConfig
     * @return array{success: bool, message: string}
     */
    private function writeWordPressConfig(string $rootPath, array $databaseConfig, string $tablePrefix, string $siteOwner): array
    {
        $normalizedRootPath = rtrim(str_replace('\\', '/', trim($rootPath)), '/');
        if ($normalizedRootPath === '') {
            return [
                'success' => false,
                'message' => 'WordPress config failed: empty website root path.',
            ];
        }

        $configPath = $normalizedRootPath.'/wp-config.php';
        $samplePath = $normalizedRootPath.'/wp-config-sample.php';
        $contents = is_file($configPath)
            ? @file_get_contents($configPath)
            : (is_file($samplePath) ? @file_get_contents($samplePath) : false);

        if (! is_string($contents) || trim($contents) === '') {
            return [
                'success' => false,
                'message' => 'WordPress config failed: wp-config-sample.php not found.',
            ];
        }

        $replacements = [
            'database_name_here' => (string) ($databaseConfig['database_name'] ?? ''),
            'username_here' => (string) ($databaseConfig['database_user'] ?? ''),
            'password_here' => (string) ($databaseConfig['database_password'] ?? ''),
        ];

        $updated = str_replace(array_keys($replacements), array_values($replacements), $contents);

        $databaseConstants = [
            'DB_NAME' => (string) ($databaseConfig['database_name'] ?? ''),
            'DB_USER' => (string) ($databaseConfig['database_user'] ?? ''),
            'DB_PASSWORD' => (string) ($databaseConfig['database_password'] ?? ''),
            'DB_HOST' => (string) ($databaseConfig['database_host'] ?? '127.0.0.1'),
        ];

        foreach ($databaseConstants as $constant => $value) {
            $escapedValue = addslashes($value);
            $updated = preg_replace_callback(
                "/define\\(\\s*(['\"])".preg_quote($constant, '/')."\\1\\s*,\\s*(['\"])(.*?)\\2\\s*\\);/",
                static fn (): string => "define('{$constant}', '{$escapedValue}');",
                $updated,
                1
            ) ?? $updated;
        }

        $updated = preg_replace(
            '/\$table_prefix\s*=\s*\'[^\']*\';/',
            "\$table_prefix = '".addslashes($tablePrefix)."';",
            $updated,
            1
        ) ?? $updated;

        $saltKeys = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT',
        ];

        foreach ($saltKeys as $saltKey) {
            $saltValue = addslashes(Str::random(64));
            $updated = preg_replace(
                "/define\\(\\s*'".preg_quote($saltKey, '/')."'\\s*,\\s*'[^']*'\\s*\\);/",
                "define('{$saltKey}', '{$saltValue}');",
                $updated,
                1
            ) ?? $updated;
        }

        try {
            $this->filemanagerService->writeTextFile($siteOwner, $configPath, $updated);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'WordPress config failed: '.$e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => 'WordPress configuration updated successfully.',
        ];
    }

    /**
     * @return array{attempted: bool, installed: bool, message: string}
     */
    private function installWordPressApplication(string $rootPath, string $wordpressVersion, string $siteOwner): array
    {
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        $wordpressVersion = $this->normalizeWordPressVersion($wordpressVersion);
        $versionLabel = $wordpressVersion === 'latest' ? 'latest' : $wordpressVersion;
        if ($rootPath === '') {
            return [
                'attempted' => true,
                'installed' => false,
                'message' => 'WordPress install failed: empty website root path.',
            ];
        }

        try {
            $this->filemanagerService->installWordPress($siteOwner, $rootPath, $wordpressVersion);

            return [
                'attempted' => true,
                'installed' => true,
                'message' => 'WordPress '.$versionLabel.' installed successfully by Rust.',
            ];
        } catch (\Throwable $e) {
            return [
                'attempted' => true,
                'installed' => false,
                'message' => 'WordPress install failed: '.$e->getMessage(),
            ];
        }

        $hasZipArchive = class_exists(ZipArchive::class);
        $hasPharData = class_exists(\PharData::class);
        if (! $hasZipArchive && ! $hasPharData) {
            return [
                'attempted' => true,
                'installed' => false,
                'message' => 'WordPress install failed: neither PHP zip nor phar extensions are available for package extraction.',
            ];
        }

        $tmpArchive = '';
        $packageUrl = '';
        $extractMethod = '';
        $tmpTar = '';

        $tempDir = $this->resolveTemporaryDirectory();

        if ($hasZipArchive) {
            $tmpArchive = $this->buildTemporaryFilePath($tempDir, 'wpzip_', '.zip');
            $packageUrl = $wordpressVersion === 'latest'
                ? 'https://wordpress.org/latest.zip'
                : 'https://wordpress.org/wordpress-'.$wordpressVersion.'.zip';
            $extractMethod = 'zip';
        } else {
            $tmpArchive = $this->buildTemporaryFilePath($tempDir, 'wp_targz_', '.tar.gz');
            $tmpTar = substr($tmpArchive, 0, -3);
            $packageUrl = $wordpressVersion === 'latest'
                ? 'https://wordpress.org/latest.tar.gz'
                : 'https://wordpress.org/wordpress-'.$wordpressVersion.'.tar.gz';
            $extractMethod = 'targz';
        }

        $tmpExtract = $this->buildTemporaryDirectoryPath($tempDir, 'wp_extract_');
        $downloaded = false;

        try {
            $downloaded = @copy($packageUrl, $tmpArchive);
            if (! $downloaded && function_exists('curl_init')) {
                $ch = @curl_init($packageUrl);
                if ($ch !== false) {
                    @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    @curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                    @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                    $body = @curl_exec($ch);
                    $statusCode = (int) @curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    @curl_close($ch);
                    if (is_string($body) && $body !== '' && $statusCode >= 200 && $statusCode < 400) {
                        $downloaded = @file_put_contents($tmpArchive, $body) !== false;
                    }
                }
            }

            if (! $downloaded) {
                return [
                    'attempted' => true,
                    'installed' => false,
                    'message' => 'WordPress install failed: unable to download WordPress '.$versionLabel.' package from wordpress.org.',
                ];
            }

            if (! @mkdir($tmpExtract, 0755, true) && ! is_dir($tmpExtract)) {
                return [
                    'attempted' => true,
                    'installed' => false,
                    'message' => 'WordPress install failed: cannot create extraction directory.',
                ];
            }

            if ($extractMethod === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($tmpArchive) !== true) {
                    return [
                        'attempted' => true,
                        'installed' => false,
                        'message' => 'WordPress install failed: invalid downloaded WordPress '.$versionLabel.' zip package.',
                    ];
                }

                $extractOk = $zip->extractTo($tmpExtract);
                $zip->close();
                if (! $extractOk) {
                    return [
                        'attempted' => true,
                        'installed' => false,
                        'message' => 'WordPress install failed: cannot extract package.',
                    ];
                }
            } else {
                if ($tmpTar !== '' && is_file($tmpTar)) {
                    @unlink($tmpTar);
                }

                $archive = new \PharData($tmpArchive);
                $archive->decompress();
                $tarArchive = new \PharData($tmpTar);
                $tarArchive->extractTo($tmpExtract, null, true);
            }

            $sourceDir = $tmpExtract.'/wordpress';
            if (! is_dir($sourceDir)) {
                return [
                    'attempted' => true,
                    'installed' => false,
                    'message' => 'WordPress install failed: extracted wordpress directory not found.',
                ];
            }

            $deploymentArchive = $this->buildTemporaryFilePath($tempDir, 'wp_deploy_', '.zip');
            $archiveResult = $this->createFlatDeploymentArchive($sourceDir, $deploymentArchive);
            if (! $archiveResult['success']) {
                return [
                    'attempted' => true,
                    'installed' => false,
                    'message' => 'WordPress install failed: '.$archiveResult['message'],
                ];
            }

            $remoteArchive = rtrim($rootPath, '/').'/.serverpanel-wordpress-deploy.zip';
            try {
                $this->filemanagerService->uploadFile($siteOwner, $remoteArchive, $deploymentArchive);
                $this->filemanagerService->unzipFile($siteOwner, $remoteArchive, $rootPath);
            } finally {
                @unlink($deploymentArchive);
                if (is_file($remoteArchive)) {
                    try {
                        $this->filemanagerService->deletePath($siteOwner, $remoteArchive);
                    } catch (\Throwable $e) {
                        // The archive cleanup must not hide a successful extraction.
                    }
                }
            }

            return [
                'attempted' => true,
                'installed' => true,
                'message' => 'WordPress '.$versionLabel.' installed successfully.',
            ];
        } catch (\Throwable $e) {
            return [
                'attempted' => true,
                'installed' => false,
                'message' => 'WordPress install failed: '.$e->getMessage(),
            ];
        } finally {
            if ($tmpTar !== '' && is_file($tmpTar)) {
                @unlink($tmpTar);
            }
            if ($tmpArchive !== '' && is_file($tmpArchive)) {
                @unlink($tmpArchive);
            }
            if (is_dir($tmpExtract)) {
                $this->deleteDirectoryRecursive($tmpExtract);
            }
        }
    }

    /**
     * @param array<string, string> $databaseConfig
     * @return DatabaseRequest|null
     */
    private function syncDatabaseRequest(string $domain, array $databaseConfig, mixed $assignedUserId = null, ?User $actor = null, ?DatabaseRequest $existing = null): ?DatabaseRequest
    {
        $databaseRequest = $existing ?? DatabaseRequest::query()->firstOrNew([
            'domain' => $domain,
            'engine' => DatabaseRequest::ENGINE_MARIADB,
            'database_name' => $databaseConfig['database_name'],
        ]);

        if (! $databaseRequest->exists) {
            $databaseRequest->id = (string) Str::uuid();
        }

        $databaseRequest->fill([
            'domain' => $domain,
            'database_name' => $databaseConfig['database_name'],
            'database_user' => $databaseConfig['database_user'],
            'database_password' => $databaseConfig['database_password'],
            'database_host' => $databaseConfig['database_host'],
            'charset' => $databaseConfig['charset'],
            'collation' => $databaseConfig['collation'],
            'status' => 'active',
            'assigned_user_id' => is_numeric($assignedUserId) && (int) $assignedUserId > 0
                ? (int) $assignedUserId
                : $actor?->id,
        ]);
        $databaseRequest->save();

        return $databaseRequest;
    }

    private function applyWebsiteFilesystemIsolation(string $siteOwner, string $projectRoot, string $rootPath): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $homePath = rtrim($this->resolver->websiteBaseDirectory(), '/')."/{$siteOwner}";
        $projectRoot = trim(str_replace('\\', '/', $projectRoot));
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        $publicRoot = $homePath.'/public_html';
        if ($projectRoot === '' || ! str_starts_with($projectRoot, $homePath)) {
            $projectRoot = $homePath;
        }
        if ($rootPath === '' || ! str_starts_with($rootPath, $homePath.'/')) {
            $rootPath = $publicRoot;
        }

        $this->runSystemCommand("getent group ".escapeshellarg($siteOwner)." >/dev/null 2>&1 || groupadd ".escapeshellarg($siteOwner));
        $this->runSystemCommand("id -u ".escapeshellarg($siteOwner)." >/dev/null 2>&1 || useradd -m -d ".escapeshellarg($homePath)." -s /usr/sbin/nologin -g ".escapeshellarg($siteOwner)." ".escapeshellarg($siteOwner));
        $this->runSystemCommand("mkdir -p ".escapeshellarg($homePath));
        $this->runSystemCommand("chown root:root ".escapeshellarg($homePath));
        $this->runSystemCommand("chmod 711 ".escapeshellarg($homePath));
        $this->runSystemCommand("mkdir -p ".escapeshellarg($projectRoot));
        $this->runSystemCommand("chown -R ".escapeshellarg($siteOwner).":www-data ".escapeshellarg($projectRoot));
        $this->runSystemCommand("find ".escapeshellarg($projectRoot)." -type d -exec chmod 750 {} \\;");
        $this->runSystemCommand("find ".escapeshellarg($projectRoot)." -type f -exec chmod 640 {} \\;");
        $this->runSystemCommand("mkdir -p ".escapeshellarg($publicRoot));
        $this->runSystemCommand("mkdir -p ".escapeshellarg($rootPath));
    }

    private function runSystemCommand(string $command): void
    {
        try {
            @shell_exec($command.' 2>&1');
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return array{success: bool, message: string, files: int}
     */
    private function createFlatDeploymentArchive(string $sourceDirectory, string $archivePath): array
    {
        if (! is_dir($sourceDirectory)) {
            return [
                'success' => false,
                'message' => 'Source directory does not exist.',
                'files' => 0,
            ];
        }

        $archive = new ZipArchive();
        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [
                'success' => false,
                'message' => 'Cannot create the Rust deployment archive.',
                'files' => 0,
            ];
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceDirectory) + 1));
            if ($item->isDir()) {
                $archive->addEmptyDir($relativePath);
                continue;
            }

            if (! $archive->addFile($item->getPathname(), $relativePath)) {
                $archive->close();
                return [
                    'success' => false,
                    'message' => 'Cannot add a WordPress file to the Rust deployment archive.',
                    'files' => $count,
                ];
            }
            $count++;
        }

        if (! $archive->close()) {
            return [
                'success' => false,
                'message' => 'Cannot finalize the Rust deployment archive.',
                'files' => $count,
            ];
        }

        return [
            'success' => true,
            'message' => 'Rust deployment archive prepared.',
            'files' => $count,
        ];
    }

    private function deleteDirectoryRecursive(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    private function resolveTemporaryDirectory(): string
    {
        $candidates = [
            sys_get_temp_dir(),
            storage_path('app/tmp'),
            storage_path('framework/tmp'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = rtrim(str_replace('\\', '/', trim((string) $candidate)), '/');
            if ($candidate === '') {
                continue;
            }

            if (! is_dir($candidate) && ! @mkdir($candidate, 0755, true) && ! is_dir($candidate)) {
                continue;
            }

            if (! is_writable($candidate)) {
                @chmod($candidate, 0775);
            }

            if (is_writable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('WordPress install failed: no writable temporary directory is available.');
    }

    private function buildTemporaryFilePath(string $directory, string $prefix, string $suffix): string
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $prefix) ?: 'tmp_';

        return $directory.'/'.$prefix.bin2hex(random_bytes(8)).$suffix;
    }

    private function buildTemporaryDirectoryPath(string $directory, string $prefix): string
    {
        return $this->buildTemporaryFilePath($directory, $prefix, '');
    }

    /**
     * @return array<int, string>
     */
    private function detectWordPressSignals(string $rootPath): array
    {
        $signals = [];
        if (is_file($rootPath.'/wp-config.php')) {
            $signals[] = 'wp-config.php';
        }
        if (is_dir($rootPath.'/wp-admin')) {
            $signals[] = 'wp-admin/';
        }
        if (is_dir($rootPath.'/wp-includes')) {
            $signals[] = 'wp-includes/';
        }
        if (is_dir($rootPath.'/wp-content')) {
            $signals[] = 'wp-content/';
        }

        return $signals;
    }

    /**
     * @return array<int, string>
     */
    private function detectLaravelSignals(string $rootPath): array
    {
        $signals = [];
        if (is_file($rootPath.'/artisan')) {
            $signals[] = 'artisan';
        }
        if (is_file($rootPath.'/bootstrap/app.php')) {
            $signals[] = 'bootstrap/app.php';
        }
        if (is_file($rootPath.'/composer.json')) {
            $composer = @file_get_contents($rootPath.'/composer.json');
            if (is_string($composer) && str_contains(strtolower($composer), 'laravel/framework')) {
                $signals[] = 'composer.json:laravel/framework';
            }
        }
        if (is_dir($rootPath.'/vendor/laravel')) {
            $signals[] = 'vendor/laravel/';
        }

        return $signals;
    }

    /**
     * @return array<int, string>
     */
    private function detectCodeIgniterSignals(string $rootPath): array
    {
        $signals = [];
        if (is_file($rootPath.'/spark')) {
            $signals[] = 'spark';
        }
        if (is_file($rootPath.'/app/Config/App.php')) {
            $signals[] = 'app/Config/App.php';
        }
        if (is_file($rootPath.'/public/index.php')) {
            $signals[] = 'public/index.php';
        }
        if (is_file($rootPath.'/application/config/config.php')) {
            $signals[] = 'application/config/config.php';
        }
        if (is_file($rootPath.'/system/core/CodeIgniter.php')) {
            $signals[] = 'system/core/CodeIgniter.php';
        }

        return $signals;
    }

    /**
     * @param array<int, string> $candidates
     */
    private function firstExistingDirectory(string $rootPath, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_dir($rootPath.'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
