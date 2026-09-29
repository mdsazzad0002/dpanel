<?php

namespace App\Services\Website;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteGitDeployment;
use App\Services\PostgresqlServiceManager;
use App\Services\Backup\PreOverwriteBackupService;
use App\Services\EdgeGatewayReloader;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Installs a fresh Laravel project (plain, API-only, or an official starter
 * kit) into a website's root_path. The heavy lifting — composer, artisan and
 * the Vite build — runs in Rust as the site owner with the website's PHP
 * version; this service orchestrates the steps and the panel-side records.
 */
class LaravelInstallService
{
    /** Supported majors with the PHP range each one runs on. */
    /** Laravel runs on both; the other app installers stay MariaDB-only. */
    public const DATABASE_ENGINES = [DatabaseRequest::ENGINE_MARIADB, DatabaseRequest::ENGINE_POSTGRESQL];

    public const VERSIONS = [
        '13' => ['label' => 'Laravel 13', 'min_php' => '8.3', 'max_php' => '8.5'],
        '12' => ['label' => 'Laravel 12', 'min_php' => '8.2', 'max_php' => '8.5'],
        '11' => ['label' => 'Laravel 11', 'min_php' => '8.2', 'max_php' => '8.4'],
        '10' => ['label' => 'Laravel 10', 'min_php' => '8.1', 'max_php' => '8.3'],
    ];

    /** Must stay in sync with the whitelist in drust/src/installer/laravel.rs. */
    public const STACKS = [
        'blank' => ['label' => 'Laravel (Blade)', 'description' => 'Plain Laravel skeleton with Blade views and Vite.', 'versions' => ['13', '12', '11', '10']],
        'vue' => ['label' => 'Inertia + Vue', 'description' => 'Official Vue starter kit with authentication (Breeze on Laravel 10).', 'versions' => ['13', '12', '10']],
        'react' => ['label' => 'Inertia + React', 'description' => 'Official React starter kit with authentication (Breeze on Laravel 10).', 'versions' => ['13', '12', '10']],
        'svelte' => ['label' => 'Inertia + Svelte', 'description' => 'Official Svelte starter kit with authentication.', 'versions' => ['13']],
        'livewire' => ['label' => 'Livewire', 'description' => 'Official Livewire starter kit with authentication (Breeze on Laravel 10).', 'versions' => ['13', '12', '10']],
        'api' => ['label' => 'API only', 'description' => 'Laravel skeleton with Sanctum API routes.', 'versions' => ['13', '12', '11', '10']],
    ];

    public function __construct(
        private readonly WebsiteResolverService $resolver,
        private readonly FilemanagerService $filemanager,
        private readonly PreOverwriteBackupService $preOverwriteBackup,
        private readonly WebsiteTemplateCatalogService $templateCatalog,
        private readonly EdgeGatewayReloader $gatewayReloader,
        private readonly WebsiteGitService $git,
        private readonly WebsiteDatabaseProvisioner $databases,
        private readonly PostgresqlServiceManager $postgresql,
    ) {
    }

    /**
     * Versions/stacks for the installer page, with the PHP version each Laravel
     * version would run on for this website (null when none is available).
     *
     * @return array{versions: array<int, array<string, mixed>>, stacks: array<int, array<string, mixed>>}
     */
    public function catalog(Website $website): array
    {
        $current = (string) ($website->php_version ?? '');

        return [
            'versions' => collect(self::VERSIONS)->map(fn (array $meta, string $version): array => [
                'value' => $version,
                'label' => $meta['label'],
                'min_php' => $meta['min_php'],
                'max_php' => $meta['max_php'],
                'php_version' => $this->resolvePhpVersion($current, $version),
            ])->values()->all(),
            'stacks' => collect(self::STACKS)->map(fn (array $meta, string $stack): array => [
                'value' => $stack,
                ...$meta,
            ])->values()->all(),
        ];
    }

    /**
     * Keep the website's PHP version when it fits the Laravel version,
     * otherwise pick the newest installed version inside the supported range.
     */
    public function resolvePhpVersion(string $current, string $laravelVersion): ?string
    {
        $meta = self::VERSIONS[$laravelVersion] ?? null;
        if ($meta === null) {
            return null;
        }

        $inRange = static fn (string $php): bool => version_compare($php, $meta['min_php'], '>=')
            && version_compare($php, $meta['max_php'], '<=');
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
     * This website's own active databases — never another site's — that a
     * Laravel install may wipe and reuse.
     *
     * @return array<int, array{id: string, database_name: string, database_user: string, domain: string}>
     */
    public function selectableDatabases(Website $website, ?User $actor): array
    {
        return $this->databases->selectable($website, $actor, self::DATABASE_ENGINES);
    }

    /**
     * Whether "create new database" can offer PostgreSQL on this server.
     *
     * @return array{installed: bool, active: bool, port: int}
     */
    public function postgresqlAvailability(): array
    {
        $service = $this->postgresql->statuses()['postgresql'] ?? [];

        return [
            'installed' => (bool) ($service['installed'] ?? false),
            'active' => (bool) ($service['active'] ?? false),
            'port' => (int) config('postgresql.port', 5432),
        ];
    }

    /**
     * What "create new database" will make, shown on the installer page. The
     * suffix is sent back with the install so the preview is what gets made.
     *
     * @return array{database_name: string, database_user: string, database_host: string, charset: string, collation: string, suffix: string}
     */
    public function previewNewDatabase(Website $website): array
    {
        return $this->databases->preview($website, 'laravel');
    }

    /**
     * @param array{stack: string, laravel_version: string, database_id?: string|null, database_suffix?: string|null} $input
     *        database_id: one of this website's databases to wipe and reuse; null/"new" creates one
     *        named with database_suffix (from previewNewDatabase) when it is still free
     *        push_to_git: publish the new project to the website's connected (empty) repository
     * @param (\Closure(string): void)|null $onStage
     * @return array{success: bool, message: string, php_version?: string, database_reused?: bool}
     */
    public function install(Website $website, array $input, ?User $actor = null, ?\Closure $onStage = null): array
    {
        $report = static function (string $stage) use ($onStage): void {
            if ($onStage !== null) {
                $onStage($stage);
            }
        };

        $stack = (string) ($input['stack'] ?? '');
        $version = (string) ($input['laravel_version'] ?? '');
        if (! in_array($version, self::STACKS[$stack]['versions'] ?? [], true)) {
            return $this->fail('This stack is not available for the selected Laravel version.');
        }

        $domain = $this->resolver->normalizeDomain((string) $website->domain);
        $rootPath = rtrim(str_replace('\\', '/', trim((string) $website->root_path)), '/');
        $siteOwner = trim((string) $website->site_owner);
        if ($domain === '' || $rootPath === '' || $siteOwner === '') {
            return $this->fail('Domain, root path or site owner is missing for this website.');
        }

        $phpVersion = $this->resolvePhpVersion((string) $website->php_version, $version);
        if ($phpVersion === null) {
            $meta = self::VERSIONS[$version];

            return $this->fail("Laravel {$version} needs PHP {$meta['min_php']}–{$meta['max_php']}, but none is installed on this server.");
        }

        // Resolve the database before anything destructive happens, so a bad
        // selection fails with the website untouched.
        $existing = null;
        $databaseId = trim((string) ($input['database_id'] ?? ''));
        if ($databaseId !== '' && $databaseId !== 'new') {
            $existing = $this->databases->find($website, $actor, $databaseId, self::DATABASE_ENGINES);
            if ($existing === null) {
                return $this->fail('The selected database is not available or has no stored credentials.');
            }
        }
        $engine = $existing !== null
            ? (string) ($existing->engine ?: DatabaseRequest::ENGINE_MARIADB)
            : (string) ($input['database_engine'] ?? DatabaseRequest::ENGINE_MARIADB);
        if (! in_array($engine, self::DATABASE_ENGINES, true)) {
            return $this->fail('Unsupported database engine.');
        }
        // Check PostgreSQL prerequisites before the website is touched: without
        // them the install would wipe the site and then fail halfway.
        if ($engine === DatabaseRequest::ENGINE_POSTGRESQL) {
            if (! ($this->postgresql->statuses()['postgresql']['active'] ?? false)) {
                return $this->fail('PostgreSQL is turned off. Turn it on under Database Management → PostgreSQL first.');
            }
            if (! $this->phpHasModule($siteOwner, $rootPath, $phpVersion, 'pdo_pgsql')) {
                return $this->fail("PHP {$phpVersion} has no PostgreSQL driver (pdo_pgsql). Install it with: sudo dpanel php update all");
            }
        }

        // Whatever is in root_path goes to the File Manager trash first (the
        // same safety net WordPress/Clone/Import use), so it stays restorable.
        $report('backing_up');
        if ($this->filemanager->directoryExists($rootPath)) {
            $this->preOverwriteBackup->snapshot($website, 'laravel_install');
            if ($this->filemanager->directoryExists($rootPath)) {
                return $this->fail('Could not move the existing website files to the trash; nothing was changed.');
            }
        }

        $report('downloading');
        $step = $this->step($siteOwner, $rootPath, $phpVersion, 'create_project', ['stack' => $stack, 'version' => $version], 960);
        if (! $step['success']) {
            return $this->fail('Laravel download failed: '.$step['output']);
        }

        $report('creating_database');
        $database = $this->databases->resolveConfig($domain, $existing, (string) ($input['database_suffix'] ?? ''), 'laravel', $engine);
        $provision = $this->databases->provision($database);
        if (! $provision['success']) {
            return $this->fail($provision['output'] ?: 'Database provisioning failed.');
        }
        if ($existing === null) {
            $this->databases->record($domain, $database, $website, $actor);
        }

        $report('configuring');
        try {
            $this->writeEnvironment($siteOwner, $rootPath, $website, $database);
        } catch (\Throwable $e) {
            return $this->fail('Writing .env failed: '.$e->getMessage());
        }
        foreach (['package:discover', 'key:generate --force'] as $command) {
            $step = $this->artisan($siteOwner, $rootPath, $phpVersion, $command);
            if (! $step['success']) {
                return $this->fail("artisan {$command} failed: ".$step['output']);
            }
        }

        // migrate:fresh drops every table first, so a reused database starts
        // clean exactly like a newly created one.
        $report('migrating');
        // Laravel 10 already ships Sanctum and routes/api.php; install:api is 11+.
        $commands = $stack === 'api' && $version !== '10'
            ? ['install:api --without-migration-prompt', 'migrate:fresh --force', 'storage:link']
            : ['migrate:fresh --force', 'storage:link'];
        foreach ($commands as $command) {
            $step = $this->artisan($siteOwner, $rootPath, $phpVersion, $command);
            if (! $step['success']) {
                return $this->fail("artisan {$command} failed: ".$step['output']);
            }
        }

        if ($stack !== 'api') {
            $report('building_assets');
            // Breeze (Laravel 10 frontend stacks) runs npm install + build itself.
            $step = $version === '10' && $stack !== 'blank'
                ? $this->step($siteOwner, $rootPath, $phpVersion, 'breeze', ['stack' => $stack], 1860)
                : $this->step($siteOwner, $rootPath, $phpVersion, 'npm_build', [], 1860);
            if (! $step['success']) {
                return $this->fail('Frontend build failed: '.$step['output']);
            }
        }

        $report('finalizing');
        try {
            $this->finalizeEnvironment($siteOwner, $rootPath);
        } catch (\Throwable $e) {
            return $this->fail('Switching .env to production failed: '.$e->getMessage());
        }
        $step = $this->step($siteOwner, $rootPath, $phpVersion, 'finalize', [], 120);
        if (! $step['success']) {
            return $this->fail('Preparing storage permissions failed: '.$step['output']);
        }
        $this->applyRuntime($website, $phpVersion);

        $gitNote = '';
        if ((bool) ($input['push_to_git'] ?? false)) {
            $report('pushing_git');
            $gitNote = ' '.$this->pushToRepository($website, $actor);
        }

        return [
            'success' => true,
            'message' => sprintf(
                '%s (%s) installed on PHP %s with %s %s database %s.',
                self::VERSIONS[$version]['label'],
                self::STACKS[$stack]['label'],
                $phpVersion,
                $existing ? 'the existing (freshly migrated)' : 'a new',
                $engine === DatabaseRequest::ENGINE_POSTGRESQL ? 'PostgreSQL' : 'MariaDB',
                $database['database_name'],
            ).$gitNote,
            'php_version' => $phpVersion,
            'database_reused' => $existing !== null,
        ];
    }

    /**
     * The install already succeeded, so a failed push is reported in the
     * message rather than failing the whole install.
     */
    private function pushToRepository(Website $website, ?User $actor): string
    {
        $deployment = WebsiteGitDeployment::query()->with('githubAccount')->where('website_id', $website->id)->first();
        if ($deployment === null) {
            return 'Git push skipped: no repository is connected to this website.';
        }

        try {
            $result = $this->git->run($deployment, 'init', $actor?->id, 'Initial Laravel install');
        } catch (\Throwable $e) {
            return 'Git push failed: '.$e->getMessage();
        }

        return $result['success']
            ? "Pushed to {$deployment->branch} of ".($deployment->repository_full_name ?: $deployment->repository_url).'.'
            : 'Git push failed: '.$result['output'];
    }

    /** @return array{success: bool, message: string} */
    private function fail(string $message): array
    {
        return ['success' => false, 'message' => Str::limit(trim($message), 4000)];
    }

    /**
     * @param array<string, string> $extra
     * @return array{success: bool, output: string}
     */
    private function step(string $siteOwner, string $rootPath, string $phpVersion, string $action, array $extra, int $timeout): array
    {
        return $this->filemanager->runLaravelInstallerStep($siteOwner, $rootPath, $phpVersion, $action, $extra, $timeout);
    }

    private function phpHasModule(string $siteOwner, string $rootPath, string $phpVersion, string $module): bool
    {
        // Runs `php -m` as the owner (drust creates the root if it's missing,
        // which is harmless before an install).
        $step = $this->step($siteOwner, $rootPath, $phpVersion, 'php_modules', [], 60);

        return $step['success'] && in_array(strtolower($module), array_map(
            fn (string $line): string => strtolower(trim($line)),
            preg_split('/\R/', $step['output']) ?: [],
        ), true);
    }

    /** @return array{success: bool, output: string} */
    private function artisan(string $siteOwner, string $rootPath, string $phpVersion, string $command): array
    {
        return $this->step($siteOwner, $rootPath, $phpVersion, 'artisan', ['command' => $command], 660);
    }

    /** @param array<string, string> $database */
    private function writeEnvironment(string $siteOwner, string $rootPath, Website $website, array $database): void
    {
        $this->filemanager->ensureLaravelEnvFile($siteOwner, $rootPath);
        $content = $this->filemanager->readTextFile($siteOwner, $rootPath.'/.env')['content'];

        $scheme = (bool) $website->enable_ssl ? 'https' : 'http';
        // Starter kits call DB::prohibitDestructiveCommands() in production,
        // which blocks migrate:fresh; APP_ENV flips to production once the
        // install steps are done (see finalizeEnvironment()).
        $values = [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_URL' => $scheme.'://'.$this->resolver->normalizeDomain((string) $website->domain),
            'DB_CONNECTION' => ($database['engine'] ?? '') === DatabaseRequest::ENGINE_POSTGRESQL ? 'pgsql' : 'mysql',
            'DB_HOST' => $database['database_host'],
            'DB_PORT' => $database['database_port'],
            'DB_DATABASE' => $database['database_name'],
            'DB_USERNAME' => $database['database_user'],
            'DB_PASSWORD' => $database['database_password'],
        ];
        $this->filemanager->writeTextFile($siteOwner, $rootPath.'/.env', $this->setEnvValues($content, $values));
    }

    private function finalizeEnvironment(string $siteOwner, string $rootPath): void
    {
        $content = $this->filemanager->readTextFile($siteOwner, $rootPath.'/.env')['content'];
        $this->filemanager->writeTextFile($siteOwner, $rootPath.'/.env', $this->setEnvValues($content, ['APP_ENV' => 'production']));
    }

    /** @param array<string, string> $values */
    private function setEnvValues(string $content, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$this->envValue($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';
            $content = preg_match($pattern, $content) === 1
                ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content, 1)
                : rtrim($content)."\n".$line."\n";
        }

        return $content;
    }

    private function envValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:\/@-]*$/', $value) === 1) {
            return $value;
        }

        // phpdotenv single quotes can't hold a quote; escaping \ " $ in double
        // quotes keeps any value literal (no ${VAR} interpolation).
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /** Point the site (and its aliases) at public/ on the chosen PHP version. */
    private function applyRuntime(Website $website, string $phpVersion): void
    {
        $settings = ['php_version' => $phpVersion, 'start_directory' => 'public', 'updated_at' => now()];
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
}
