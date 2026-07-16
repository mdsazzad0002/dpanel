<?php

namespace App\Services\Website;

use App\Models\DatabaseRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ZipArchive;

class WordpressInstallService
{
    public function __construct(
        private readonly WebsiteResolverService $resolver,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getWordPressVersionOptions(): array
    {
        try {
            return Cache::remember('wordpress.version.options', now()->addHours(6), function (): array {
                $url = 'https://api.wordpress.org/core/version-check/1.7/';
                $body = @file_get_contents(
                    $url,
                    false,
                    stream_context_create([
                        'http' => [
                            'timeout' => 6,
                        ],
                    ]),
                );

                if (! is_string($body) || trim($body) === '') {
                    $body = '';
                    if (function_exists('curl_init')) {
                        $ch = @curl_init($url);
                        if ($ch !== false) {
                            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            @curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
                            $responseBody = @curl_exec($ch);
                            $statusCode = (int) @curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            @curl_close($ch);
                            if (is_string($responseBody) && $responseBody !== '' && $statusCode >= 200 && $statusCode < 400) {
                                $body = $responseBody;
                            }
                        }
                    }
                }

                $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
                if (! is_array($decoded)) {
                    return ['latest'];
                }

                $offers = $decoded['offers'] ?? null;
                if (! is_array($offers)) {
                    return ['latest'];
                }

                $versions = collect($offers)
                    ->map(function ($offer): string {
                        if (! is_array($offer)) {
                            return '';
                        }

                        return $this->normalizeWordPressVersion((string) ($offer['current'] ?? $offer['version'] ?? ''));
                    })
                    ->filter(fn (string $version): bool => $version !== '' && $version !== 'latest')
                    ->unique()
                    ->sort(fn (string $a, string $b): int => version_compare($b, $a))
                    ->take(15)
                    ->values()
                    ->all();

                return array_values(array_merge(['latest'], $versions));
            });
        } catch (\Throwable $e) {
            return ['latest'];
        }
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

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $input
     * @return array{success: bool, message: string, website: array<string, mixed>|null, database_request: array<string, mixed>|null}
     */
    public function install(array $website, array $input, ?User $actor = null): array
    {
        $domain = $this->resolver->normalizeDomain((string) ($website['domain'] ?? ''));
        $rootPath = (string) ($website['root_path'] ?? '');
        $projectRoot = (string) ($website['project_root'] ?? $this->resolver->deriveProjectRootPath($rootPath, $domain));
        $phpVersion = (string) ($website['php_version'] ?? '8.0');
        $wordpressVersion = $this->normalizeWordPressVersion((string) ($input['wordpress_version'] ?? ($website['wordpress_version'] ?? 'latest')));
        $siteOwner = (string) ($website['site_owner'] ?? $this->resolver->extractSiteOwnerFromRootPath($projectRoot));
        $databasePrefix = $this->normalizeWordPressDatabasePrefix(
            (string) ($input['database_prefix'] ?? ($website['wordpress_db_prefix'] ?? '')),
            $domain
        );

        if ($domain === '' || $rootPath === '') {
            return $this->fail('Domain or root path is missing for WordPress installation.');
        }

        try {
            if ($siteOwner !== '') {
                $this->applyWebsiteFilesystemIsolation($siteOwner, $projectRoot, $rootPath);
            }

            if (! $this->hasWordPressFiles($rootPath)) {
                $installerResult = $this->installWordPressApplication($rootPath, $wordpressVersion);
                if (! $installerResult['installed']) {
                    $message = trim((string) ($installerResult['message'] ?? ''));

                    return $this->fail($message !== '' ? $message : 'WordPress installation failed.');
                }
            }

            $existingDatabaseRequest = DatabaseRequest::query()->where('domain', $domain)->first();
            $databaseConfig = $this->resolveWordPressDatabaseConfig($databasePrefix, $domain, $existingDatabaseRequest);
            $databaseProvisionResult = $this->provisionWordPressDatabase($databaseConfig);
            if (! $databaseProvisionResult['success']) {
                return $this->fail(trim((string) ($databaseProvisionResult['output'] ?? '')) ?: 'WordPress database provisioning failed.');
            }

            $configResult = $this->writeWordPressConfig($rootPath, $databaseConfig, $databaseConfig['table_prefix']);
            if (! $configResult['success']) {
                return $this->fail(trim((string) ($configResult['message'] ?? '')) ?: 'WordPress wp-config.php update failed.');
            }
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $runtimeStatus = strtolower((string) ($website['status'] ?? '')) === 'disabled'
            ? 'disabled'
            : 'pending';

        $databaseRequest = $this->syncDatabaseRequest(
            $domain,
            $databaseConfig,
            $website['assigned_user_id'] ?? null,
            $actor,
            $existingDatabaseRequest ?? null
        );
        $website = array_merge($website, [
            'app_installer' => 'wordpress',
            'wordpress_version' => $wordpressVersion,
            'wordpress_db_prefix' => $databasePrefix,
            'status' => $runtimeStatus,
        ]);

        return [
            'success' => true,
            'message' => $existingDatabaseRequest ? 'WordPress configuration updated and database synced successfully.' : 'WordPress installed and configured successfully.',
            'website' => $website,
            'database_request' => $databaseRequest?->toArray(),
        ];
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
        return rtrim(dirname(base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'discript'.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'database-request.sh';
    }

    /**
     * @param array<string, string> $databaseConfig
     * @return array{ran: bool, success: bool, output: string}
     */
    private function provisionWordPressDatabase(array $databaseConfig): array
    {
        $scriptPath = $this->runtimeDatabaseScriptPath();
        if (! is_file($scriptPath)) {
            return [
                'ran' => false,
                'success' => false,
                'output' => 'Database provisioning script not found: '.$scriptPath,
            ];
        }

        $parts = [
            'bash',
            escapeshellarg($scriptPath),
            'create',
            escapeshellarg((string) ($databaseConfig['database_name'] ?? '')),
            escapeshellarg((string) ($databaseConfig['database_user'] ?? '')),
            escapeshellarg((string) ($databaseConfig['database_password'] ?? '')),
            escapeshellarg((string) ($databaseConfig['database_host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($databaseConfig['database_port'] ?? '3306')),
            escapeshellarg((string) ($databaseConfig['charset'] ?? 'utf8mb4')),
            escapeshellarg((string) ($databaseConfig['collation'] ?? 'utf8mb4_unicode_ci')),
        ];

        $output = [];
        $exitCode = 1;
        @exec(implode(' ', $parts).' 2>&1', $output, $exitCode);
        $message = trim(implode("\n", $output));

        return [
            'ran' => true,
            'success' => $exitCode === 0,
            'output' => $message !== '' ? $message : ($exitCode === 0 ? 'Database provisioning completed.' : 'Database provisioning failed.'),
        ];
    }

    /**
     * @param array<string, string> $databaseConfig
     */
    private function buildWordPressDatabaseCommand(array $databaseConfig): string
    {
        $scriptPath = $this->runtimeDatabaseScriptPath();

        return sprintf(
            'bash %s create %s %s %s %s %s %s %s',
            escapeshellarg($scriptPath),
            escapeshellarg((string) ($databaseConfig['database_name'] ?? '')),
            escapeshellarg((string) ($databaseConfig['database_user'] ?? '')),
            escapeshellarg((string) ($databaseConfig['database_password'] ?? '')),
            escapeshellarg((string) ($databaseConfig['database_host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($databaseConfig['database_port'] ?? '3306')),
            escapeshellarg((string) ($databaseConfig['charset'] ?? 'utf8mb4')),
            escapeshellarg((string) ($databaseConfig['collation'] ?? 'utf8mb4_unicode_ci')),
        );
    }

    /**
     * @param array<string, string> $databaseConfig
     * @return array{success: bool, message: string}
     */
    private function writeWordPressConfig(string $rootPath, array $databaseConfig, string $tablePrefix): array
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

        $updated = preg_replace(
            "/define\\(\\s*'DB_HOST'\\s*,\\s*'[^']*'\\s*\\);/",
            "define('DB_HOST', '".addslashes((string) ($databaseConfig['database_host'] ?? '127.0.0.1'))."');",
            $updated,
            1
        ) ?? $updated;

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

        $written = @file_put_contents($configPath, $updated);
        if ($written === false) {
            return [
                'success' => false,
                'message' => 'WordPress config failed: unable to write wp-config.php.',
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
    private function installWordPressApplication(string $rootPath, string $wordpressVersion = 'latest'): array
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

        if (! is_dir($rootPath) && ! @mkdir($rootPath, 0755, true) && ! is_dir($rootPath)) {
            return [
                'attempted' => true,
                'installed' => false,
                'message' => 'WordPress install failed: cannot create website root directory.',
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

        if ($hasZipArchive) {
            $tmpArchive = (string) @tempnam(sys_get_temp_dir(), 'wpzip_');
            if ($tmpArchive === '') {
                return [
                    'attempted' => true,
                    'installed' => false,
                    'message' => 'WordPress install failed: cannot create temporary zip file.',
                ];
            }
            $packageUrl = $wordpressVersion === 'latest'
                ? 'https://wordpress.org/latest.zip'
                : 'https://wordpress.org/wordpress-'.$wordpressVersion.'.zip';
            $extractMethod = 'zip';
        } else {
            $tmpArchive = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/wp_targz_'.bin2hex(random_bytes(6)).'.tar.gz';
            $tmpTar = substr($tmpArchive, 0, -3);
            $packageUrl = $wordpressVersion === 'latest'
                ? 'https://wordpress.org/latest.tar.gz'
                : 'https://wordpress.org/wordpress-'.$wordpressVersion.'.tar.gz';
            $extractMethod = 'targz';
        }

        $tmpExtract = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/wp_extract_'.bin2hex(random_bytes(6));
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

            $copyResult = $this->copyDirectoryContentsRecursive($sourceDir, $rootPath);
            if (! $copyResult['success']) {
                return [
                    'attempted' => true,
                    'installed' => false,
                    'message' => 'WordPress install failed: '.$copyResult['message'],
                ];
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
            'command' => $this->buildWordPressDatabaseCommand($databaseConfig),
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
    private function copyDirectoryContentsRecursive(string $sourceDirectory, string $targetDirectory): array
    {
        if (! is_dir($sourceDirectory)) {
            return [
                'success' => false,
                'message' => 'Source directory does not exist.',
                'files' => 0,
            ];
        }

        if (! is_dir($targetDirectory) && ! @mkdir($targetDirectory, 0755, true) && ! is_dir($targetDirectory)) {
            return [
                'success' => false,
                'message' => 'Cannot create target directory.',
                'files' => 0,
            ];
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $targetDirectory.'/'.substr($item->getPathname(), strlen($sourceDirectory) + 1);
            if ($item->isDir()) {
                if (! is_dir($targetPath) && ! @mkdir($targetPath, 0755, true) && ! is_dir($targetPath)) {
                    return [
                        'success' => false,
                        'message' => 'Cannot create directory during copy.',
                        'files' => $count,
                    ];
                }
                continue;
            }

            if (! @copy($item->getPathname(), $targetPath)) {
                return [
                    'success' => false,
                    'message' => 'Cannot copy file during WordPress extraction.',
                    'files' => $count,
                ];
            }
            $count++;
        }

        return [
            'success' => true,
            'message' => 'Files copied.',
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
