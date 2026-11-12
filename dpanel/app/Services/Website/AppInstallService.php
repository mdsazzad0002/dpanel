<?php

namespace App\Services\Website;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\PreOverwriteBackupService;
use App\Services\EdgeGatewayReloader;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Website\Apps\AppInstallContext;
use App\Services\Website\Apps\AppInstaller;
use App\Services\Website\Apps\AppInstallerRegistry;
use App\Services\Website\Apps\AppInstallFailed;
use App\Services\Website\Apps\AppInstallSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shared flow of the one-click app installers (one class per app in
 * Apps/, listed in AppInstallerRegistry). Like the Laravel installer, existing
 * files go to the File Manager trash first, every shell step runs in drust as
 * the site owner on the website's PHP version, and the panel records the
 * database and switches the document root.
 */
class AppInstallService
{
    public function __construct(
        private readonly WebsiteResolverService $resolver,
        private readonly FilemanagerService $filemanager,
        private readonly PreOverwriteBackupService $preOverwriteBackup,
        private readonly WebsiteTemplateCatalogService $templateCatalog,
        private readonly EdgeGatewayReloader $gatewayReloader,
        private readonly WebsiteDatabaseProvisioner $databases,
        private readonly AppInstallerRegistry $registry,
        private readonly AppInstallSupport $support,
    ) {
    }

    /**
     * Installable versions for the page, each with the PHP version it would run on.
     *
     * @return array{versions: array<int, array<string, mixed>>, error: string|null}
     */
    public function catalog(string $app, Website $website): array
    {
        try {
            $versions = $this->registry->get($app)->versions();
        } catch (AppInstallFailed $e) {
            return ['versions' => [], 'error' => $e->getMessage()];
        }
        $current = (string) ($website->php_version ?? '');

        return ['versions' => array_map(fn (array $version): array => [
            ...$version,
            'php_version' => $this->resolvePhpVersion($current, $version),
        ], $versions), 'error' => null];
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
     *                                       database_engine and the app's own fields
     * @param  (\Closure(string): void)|null  $onStage
     * @return array{success: bool, message: string}
     */
    public function install(string $app, Website $website, array $input, ?User $actor = null, ?\Closure $onStage = null): array
    {
        try {
            return $this->run($this->registry->get($app), $website, $input, $actor, $onStage ?? static function (): void {
            });
        } catch (AppInstallFailed $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{success: bool, message: string}
     */
    private function run(AppInstaller $installer, Website $website, array $input, ?User $actor, \Closure $report): array
    {
        $app = $installer->key();
        $meta = $installer->definition();

        $domain = $this->resolver->normalizeDomain((string) $website->domain);
        $rootPath = rtrim(str_replace('\\', '/', trim((string) $website->root_path)), '/');
        $siteOwner = trim((string) $website->site_owner);
        if ($domain === '' || $rootPath === '' || $siteOwner === '') {
            throw new AppInstallFailed('Domain, root path or site owner is missing for this website.');
        }

        // Resolve version, PHP and database before anything destructive happens.
        $plan = $installer->plan($input, $website, $actor);
        $range = $plan['range'];
        $phpVersion = $this->resolvePhpVersion((string) $website->php_version, $range);
        if ($phpVersion === null) {
            throw new AppInstallFailed("{$meta['label']} needs PHP {$range['min_php']}–{$range['max_php']}, but none is installed on this server.");
        }

        $databaseId = trim((string) ($input['database_id'] ?? 'new'));
        $useDatabase = $databaseId !== 'none';
        if (! $useDatabase && $meta['database'] !== 'optional') {
            throw new AppInstallFailed("{$meta['label']} needs a database.");
        }
        if ($meta['database'] === 'new' && $databaseId !== 'new') {
            throw new AppInstallFailed("{$meta['label']} must be installed into a new, empty database.");
        }
        $engines = $this->registry->engines($app);
        $existing = null;
        if ($useDatabase && $databaseId !== 'new') {
            $existing = $this->databases->find($website, $actor, $databaseId, $engines);
            if ($existing === null) {
                throw new AppInstallFailed('The selected database is not available or has no stored credentials.');
            }
        }
        $engine = $existing !== null
            ? ($existing->isPostgresql() ? DatabaseRequest::ENGINE_POSTGRESQL : DatabaseRequest::ENGINE_MARIADB)
            : (string) ($input['database_engine'] ?? DatabaseRequest::ENGINE_MARIADB);
        if ($useDatabase && ! in_array($engine, $engines, true)) {
            throw new AppInstallFailed("{$meta['label']} cannot be installed on this database engine.");
        }
        $context = new AppInstallContext($website, $input, $actor, $domain, $rootPath, $siteOwner, $phpVersion, $plan,
            $useDatabase && $engine === DatabaseRequest::ENGINE_POSTGRESQL);

        // Without the driver the installer only fails after the site files are replaced.
        if ($context->isPostgresql) {
            $module = $installer->postgresModule();
            $modules = $this->support->step($context, 'php_modules', [], 60);
            if (! $modules['success'] || preg_match('/^'.$module.'$/mi', $modules['output']) !== 1) {
                throw new AppInstallFailed("PostgreSQL needs the {$module} extension for PHP {$phpVersion}, and it is not loaded. Enable it in PHP Manager or choose MariaDB.");
            }
        }
        $installer->preflight($context);

        $report('backing_up');
        if ($this->filemanager->directoryExists($rootPath)) {
            $this->preOverwriteBackup->snapshot($website, $app.'_install');
            if ($this->filemanager->directoryExists($rootPath)) {
                throw new AppInstallFailed('Could not move the existing website files to the trash; nothing was changed.');
            }
        }

        $report('downloading');
        $download = $installer->download($context);
        if (! $download['success']) {
            throw new AppInstallFailed("{$meta['label']} download failed: ".$download['output']);
        }

        if ($useDatabase) {
            $report('creating_database');
            $database = $this->databases->resolveConfig($domain, $existing, (string) ($input['database_suffix'] ?? ''), $app, $engine);
            $provision = $this->databases->provision($database);
            if (! $provision['success']) {
                throw new AppInstallFailed($provision['output'] ?: 'Database provisioning failed.');
            }
            if ($existing === null) {
                $this->databases->record($domain, $database, $website, $actor);
            }
            $context->database = $database;
            $context->existingDatabase = $existing !== null;
            $installer->afterDatabase($context);
        }

        $report('configuring');
        $installer->configure($context);

        $report('finalizing');
        $step = $this->support->step($context, 'finalize', ['stack' => $app], 120);
        if (! $step['success']) {
            throw new AppInstallFailed('Preparing file permissions failed: '.$step['output']);
        }
        $this->applyRuntime($website, $phpVersion, (string) $meta['document_root']);

        $databaseNote = $context->database === null
            ? 'without a database'
            : sprintf('with %s database %s', $context->existingDatabase ? 'the existing' : 'a new', $context->database['database_name']);
        $login = $installer->complete($context);

        return [
            'success' => true,
            'message' => "{$plan['label']} installed on PHP {$phpVersion} {$databaseNote}.{$login}",
        ];
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
