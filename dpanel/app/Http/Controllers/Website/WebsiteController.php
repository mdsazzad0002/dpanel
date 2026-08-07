<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Models\User;
use App\Models\Website;
use App\Services\Filemanager\FilemanagerService;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Website\WebsiteCreateEditService;
use App\Services\Website\WebsiteResolverService;
use App\Services\Website\WebsiteTemplateCatalogService;
use App\Services\Website\WordpressInstallService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
// Service Quick Use
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WebsiteController extends Controller
{
    public function __construct(
        protected WebsiteResolverService $websiteResolver,
        protected WebsiteTemplateCatalogService $templateCatalog,
        protected WebsiteCreateEditService $websiteCreateEdit,
        protected SslLifecycleService $sslLifecycleService,
        protected FilemanagerService $filemanagerService,
        protected WordpressInstallService $wordpressInstallService,
    ) {}

    private const HOME_BASE = '/home';

    private const WEBSITE_METRICS_TABLE = 'website_metrics';

    private const DEFAULT_SITE_DIR = 'public_html';

    private const PHP_SETTINGS_TABLE = 'php_management_settings';

    private const PHP_STATE_KEY = 'state';

    private const WEBSITE_USAGE_HISTORY_DIR = 'app/website-usage-history';

    private const WEBSITE_USAGE_RETENTION_HOURS = 12;

    private const WEBSITE_USAGE_MAX_POINTS = 720;

    private const WEBSITE_USAGE_STALE_FILE_DAYS = 3;

    private const WEBSITE_USAGE_CLEANUP_CACHE_KEY = 'websites:usage-history:last-cleanup';

    private const WEBSITE_USAGE_CLEANUP_INTERVAL_MINUTES = 30;

    /**
     * @var array<int, string>
     */
    private const FALLBACK_PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function __call(string $method, array $parameters): mixed
    {
        if (method_exists($this->websiteResolver, $method)) {
            return $this->websiteResolver->{$method}(...$parameters);
        }

        throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
    }

    public function searchParentDomains(Request $request): JsonResponse
    {
        $query = strtolower(trim((string) $request->query('q', '')));
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min($limit, 10));

        $domains = $this->visibleRequestsForActor($request->user())
            ->map(function (array $item): array {
                $domain = $this->normalizeDomain((string) ($item['domain'] ?? ''));
                $rootPath = (string) ($item['root_path'] ?? '');
                $startDirectory = (string) ($item['start_directory'] ?? 'public');

                return [
                    'id' => (string) ($item['id'] ?? ''),
                    'domain' => $domain,
                    'root_path' => $rootPath,
                    'start_directory' => $startDirectory,
                ];
            })
            ->filter(function (array $item) use ($query): bool {
                $domain = (string) ($item['domain'] ?? '');
                if ($domain === '') {
                    return false;
                }

                if ($query === '') {
                    return true;
                }

                return str_contains($domain, $query);
            })
            ->unique('domain')
            ->take($limit)
            ->values()
            ->all();

        return response()->json([
            'data' => $domains,
        ]);
    }

    /**
     * List created website requests/commands.
     */
    public function index(Request $request): Response
    {
        $requests = $this->decorateWebsiteRecords(
            $this->visibleRequestsForActor($request->user())
        );

        return Inertia::render('Websites/List', [
            'websiteRequests' => $requests,
        ]);
    }

    /**
     * Edit website request.
     */
    public function edit(string $token, string $id): Response
    {

        $requestItem = $this->findAuthorizedWebsiteOrFail($id);

        $phpVersions = $this->getPhpVersionsForWebsites();
        $currentVersion = (string) ($requestItem['php_version'] ?? '');
        if ($currentVersion !== '' && ! in_array($currentVersion, $phpVersions, true)) {
            $phpVersions[] = $currentVersion;
        }

        return Inertia::render('Websites/Edit', [
            'websiteRequest' => $requestItem,
            'serverBaseDir' => $this->websiteBaseDirectory(),
            'defaultPhpVersion' => $this->getDefaultWebsitePhpVersion('none'),
            'phpVersions' => array_values(
                collect($phpVersions)
                    ->map(fn (string $version): string => trim($version))
                    ->filter()
                    ->unique()
                    ->sort(fn ($a, $b) => version_compare($b, $a))
                    ->values()
                    ->all(),
            ),
            'wordpressVersions' => $this->getWordPressVersionOptions(),
        ]);
    }

    /**
     * Show website management overview.
     */
    public function manage(string $token, string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $metrics = $this->safeBuildDynamicMetrics($website);
        $runtimeStatus = strtolower(trim((string) ($website['status'] ?? 'pending'))) === 'live'
            ? 'live'
            : $this->detectRuntimeStatus($website);

        $activities = [
            [
                'label' => 'Request Created',
                'value' => $website['created_at'] ?? null,
            ],
            [
                'label' => 'Request Updated',
                'value' => $website['updated_at'] ?? null,
            ],
            [
                'label' => 'Status',
                'value' => $runtimeStatus,
            ],
            [
                'label' => 'Root Path',
                'value' => (string) ($website['root_path'] ?? '-'),
            ],
        ];

        $autoRenewNotice = $this->autoRenewWebsiteSslIfNeeded($website);
        $sslStatus = $this->inspectWebsiteSslStatus($website);
        $rootInspection = $this->inspectWebsiteApplication($website);
        $inspectedProjectRoot = rtrim((string) ($rootInspection['root_path'] ?? ''), '/');
        $rootInspection['has_composer_json'] = $inspectedProjectRoot !== '' && is_file($inspectedProjectRoot.'/composer.json');
        $rootInspection['has_package_json'] = $inspectedProjectRoot !== '' && is_file($inspectedProjectRoot.'/package.json');
        $databaseRequest = DatabaseRequest::query()
            ->visibleTo(request()->user())
            ->whereRaw('LOWER(domain) = ?', [strtolower((string) ($website['domain'] ?? ''))])
            ->where('status', 'active')
            ->latest()
            ->first();

        return Inertia::render('Websites/Manage', [
            'website' => $website,
            'metrics' => $metrics,
            'activities' => $activities,
            'sslStatus' => $sslStatus,
            'autoRenewNotice' => $autoRenewNotice,
            'rootInspection' => $rootInspection,
            'databaseConnection' => [
                'available' => $databaseRequest !== null,
                'database_name' => $databaseRequest?->database_name,
                'status' => $databaseRequest?->status,
            ],
        ]);
    }

    public function sslManager(string $token, string $id): RedirectResponse
    {
        $this->findAuthorizedWebsiteOrFail($id);

        return redirect()->route('websites.manage', ['token' => $token, 'id' => $id]);
    }

    public function issueSsl(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $this->findAuthorizedWebsiteOrFail($id);
        $website = Website::query()->findOrFail($id);
        $website->forceFill(['enable_ssl' => true])->save();

        try {
            $result = $this->sslLifecycleService->ensureForWebsite($website->fresh());
        } catch (\Throwable $e) {
            $message = 'SSL issue failed. '.$e->getMessage();

            return $request->expectsJson()
                ? response()->json(['type' => 'error', 'message' => $message], 422)
                : back()->with('error', $message);
        }

        $action = ! empty($result['renewed']) ? 'renewed' : (! empty($result['issued']) ? 'issued' : 'verified');
        $message = "SSL certificate {$action} successfully.";

        return $request->expectsJson()
            ? response()->json(['type' => 'success', 'message' => $message])
            : back()->with('success', $message);
    }

    public function updateSslStatus(Request $request, string $token, string $id): JsonResponse
    {
        $this->findAuthorizedWebsiteOrFail($id);
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $website = Website::query()->findOrFail($id);
        $originalStatus = (bool) $website->enable_ssl;
        $enabled = (bool) $validated['enabled'];

        try {
            $website->forceFill(['enable_ssl' => $enabled])->save();
            if (! $enabled) {
                $this->sslLifecycleService->ensureForWebsite($website->fresh());
            }
        } catch (\Throwable $e) {
            $website->forceFill(['enable_ssl' => $originalStatus])->save();

            return response()->json([
                'type' => 'error',
                'message' => 'SSL status update failed. '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'type' => 'success',
            'enabled' => $enabled,
            'message' => $enabled ? 'SSL enabled successfully.' : 'SSL disabled successfully.',
        ]);
    }

    public function Usage(string $token, string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $metrics = $this->safeBuildDynamicMetrics($website);
        $histories = $this->buildDynamicHistories((string) ($website['id'] ?? $id), $metrics);

        return Inertia::render('Websites/Usage', [
            'website' => $website,
            'metrics' => $metrics,
            'histories' => $histories,
        ]);
    }

    public function clearProjectCache(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $respond = function (bool $success, string $message, int $status = 200) use ($request, $id): RedirectResponse|JsonResponse {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ], $status);
            }

            return redirect()
                ->route('websites.manage', $id)
                ->with($success ? 'success' : 'error', $message);
        };

        $website = $this->findAuthorizedWebsiteOrFail($id);
        $rootInspection = $this->inspectWebsiteApplication($website);
        $detectedApp = strtolower((string) ($rootInspection['detected_app'] ?? ''));
        if (! in_array($detectedApp, ['wordpress', 'laravel'], true)) {
            return $respond(false, 'Cache clear is only available for detected WordPress or Laravel websites.', 422);
        }

        if ($detectedApp === 'wordpress') {
            $cachePath = rtrim((string) ($rootInspection['root_path'] ?? ''), '/').'/wp-content/cache';
            $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath((string) ($website['root_path'] ?? '')));
            try {
                if (is_dir($cachePath)) {
                    $this->filemanagerService->deletePath($siteOwner, $cachePath);
                }
                $this->filemanagerService->createDirectory($siteOwner, $cachePath);

                return $respond(true, 'WordPress cache cleared successfully.');
            } catch (\Throwable $e) {
                return $respond(false, 'WordPress cache clear failed: '.$e->getMessage(), 422);
            }
        }

        $rootPath = (string) ($rootInspection['root_path'] ?? $website['root_path'] ?? '');
        if ($rootPath === '' || ! is_dir($rootPath)) {
            return $respond(false, 'Website root path is missing or inaccessible.', 422);
        }

        $artisanPath = $this->resolveProjectArtisanPath($rootPath);
        if ($artisanPath === null) {
            return $respond(false, 'Laravel artisan file not found for this website.', 422);
        }

        $projectPath = dirname($artisanPath);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($rootPath));
        $primary = $this->runProjectArtisanCommand($projectPath, 'optimize:clear', $siteOwner);

        if ($primary['success']) {
            return $respond(true, 'Project cache cleared successfully (optimize:clear).');
        }

        $fallbackCommands = ['cache:clear', 'config:clear', 'route:clear', 'view:clear'];
        $fallbackResults = collect($fallbackCommands)
            ->map(fn (string $command): array => $this->runProjectArtisanCommand($projectPath, $command, $siteOwner))
            ->all();
        $successCount = collect($fallbackResults)->filter(fn (array $result): bool => (bool) ($result['success'] ?? false))->count();

        if ($successCount === count($fallbackCommands)) {
            return $respond(true, 'Project cache cleared successfully (fallback commands).');
        }

        if ($successCount > 0) {
            return $respond(false, 'Project cache clear partially completed. Check file permissions and Laravel CLI access.', 422);
        }

        $errorDetails = trim((string) ($primary['output'] ?? ''));
        if ($errorDetails !== '') {
            $errorDetails = substr(preg_replace('/\s+/', ' ', $errorDetails) ?? '', 0, 180);
        }
        $suffix = $errorDetails !== '' ? " Error: {$errorDetails}" : '';

        return $respond(false, 'Project cache clear failed.'.$suffix, 422);
    }

    public function fixProjectPermissions(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $inspection = $this->inspectWebsiteApplication($website);
        $rootPath = (string) ($inspection['root_path'] ?? $website['project_root'] ?? $website['root_path'] ?? '');
        $artisanPath = $this->resolveProjectArtisanPath($rootPath);
        $projectPath = $artisanPath !== null ? dirname($artisanPath) : rtrim($rootPath, '/');
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($projectPath));

        if ($siteOwner === '' || $projectPath === '') {
            return response()->json(['success' => false, 'message' => 'Website owner or project path is unavailable.'], 422);
        }

        try {
            $result = $this->filemanagerService->fixWebsitePermissions($siteOwner, $projectPath);
            if (! $result['success']) {
                return response()->json(['success' => false, 'message' => $result['output'] ?: 'Permission repair failed.'], 422);
            }

            return response()->json(['success' => true, 'message' => 'Website permissions fixed successfully. Laravel runtime files were prepared when applicable.']);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'message' => 'Permission repair failed: '.$error->getMessage()], 422);
        }
    }

    public function connectProjectDatabase(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $inspection = $this->inspectWebsiteApplication($website);
        $framework = strtolower((string) ($inspection['detected_app'] ?? ''));
        if (! in_array($framework, ['laravel', 'wordpress'], true)) {
            return response()->json(['success' => false, 'message' => 'Laravel or WordPress project was not detected.'], 422);
        }

        $root = rtrim((string) ($inspection['root_path'] ?? ''), '/');
        $configPath = $framework === 'laravel' ? $root.'/.env' : $root.'/wp-config.php';
        $database = DatabaseRequest::query()
            ->visibleTo($request->user())
            ->whereRaw('LOWER(domain) = ?', [strtolower((string) ($website['domain'] ?? ''))])
            ->where('status', 'active')
            ->latest()
            ->first();
        if ($database === null) {
            return response()->json(['success' => false, 'message' => 'No active database is assigned to this website domain.'], 422);
        }

        $client = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($apiToken !== '') $client = $client->withToken($apiToken);
        $response = $client->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/database-config', [
            'site_owner' => (string) ($website['site_owner'] ?? ''),
            'framework' => $framework,
            'config_path' => $configPath,
            'database_name' => (string) $database->database_name,
            'database_user' => (string) $database->database_user,
            'database_password' => (string) $database->database_password,
            'database_host' => (string) ($database->database_host ?: '127.0.0.1'),
            'database_port' => 3306,
        ]);
        $json = $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => (string) ($json['message'] ?? 'Database configuration update failed.')], 422);
        }

        if ($framework === 'laravel') {
            $this->runProjectArtisanCommand($root, 'config:clear', (string) ($website['site_owner'] ?? ''));
        }
        return response()->json(['success' => true, 'message' => ucfirst($framework).' database connected successfully.']);
    }

    public function installProjectDependencies(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $validated = $request->validate(['action' => ['required', 'string', 'in:composer_install,npm_install,npm_build']]);
        $inspection = $this->inspectWebsiteApplication($website);
        $root = rtrim((string) ($inspection['root_path'] ?? ''), '/');
        $manifest = $validated['action'] === 'composer_install' ? 'composer.json' : 'package.json';
        if ($root === '' || ! is_file($root.'/'.$manifest)) {
            return response()->json(['success' => false, 'message' => $manifest.' was not found in the detected project root.'], 422);
        }

        $client = Http::acceptJson()->asJson()->timeout(900);
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($apiToken !== '') $client = $client->withToken($apiToken);
        $response = $client->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/project-dependencies', [
            'site_owner' => (string) ($website['site_owner'] ?? ''),
            'project_root' => $root,
            'action' => $validated['action'],
        ]);
        $json = $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => (string) ($json['message'] ?? 'Dependency installation failed.')], 422);
        }
        $message = match ($validated['action']) {
            'composer_install' => 'Composer dependencies installed successfully.',
            'npm_build' => 'Frontend assets built successfully.',
            default => 'NPM dependencies installed and frontend assets built successfully.',
        };
        return response()->json(['success' => true, 'message' => $message]);
    }

    public function updateProjectStorageLink(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $respond = function (bool $success, string $message, bool $linked, int $status = 200) use ($request, $id): RedirectResponse|JsonResponse {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                    'linked' => $linked,
                ], $status);
            }

            return redirect()
                ->route('websites.manage', $id)
                ->with($success ? 'success' : 'error', $message);
        };

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:link,refresh,unlink'],
        ]);
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $inspection = $this->inspectWebsiteApplication($website);
        if (strtolower((string) ($inspection['detected_app'] ?? '')) !== 'laravel') {
            return $respond(false, 'Storage link is only available for detected Laravel websites.', false, 422);
        }

        $artisanPath = $this->resolveProjectArtisanPath((string) ($inspection['root_path'] ?? $website['root_path'] ?? ''));
        if ($artisanPath === null) {
            return $respond(false, 'Laravel artisan file not found for this website.', false, 422);
        }

        $projectPath = dirname($artisanPath);
        $publicStoragePath = $projectPath.'/public/storage';
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath((string) ($website['root_path'] ?? '')));
        $action = (string) $validated['action'];

        if (in_array($action, ['refresh', 'unlink'], true)) {
            $unlink = $this->runProjectArtisanCommand($projectPath, 'storage:unlink', $siteOwner);
            if (! $unlink['success'] && is_link($publicStoragePath)) {
                return $respond(false, 'Storage unlink failed: '.trim($unlink['output']), true, 422);
            }
        }

        if ($action === 'unlink') {
            $linked = is_link($publicStoragePath);

            return $respond(! $linked, $linked ? 'Storage link could not be removed.' : 'Storage link removed successfully.', $linked, $linked ? 422 : 200);
        }

        $link = $this->runProjectArtisanCommand($projectPath, 'storage:link', $siteOwner);
        $linked = is_link($publicStoragePath);
        if (! $link['success'] || ! $linked) {
            $details = trim((string) ($link['output'] ?? ''));
            $message = $action === 'refresh' ? 'Storage link refresh failed.' : 'Storage link creation failed.';

            return $respond(false, $details !== '' ? $message.' '.$details : $message, $linked, 422);
        }

        return $respond(true, $action === 'refresh' ? 'Storage link refreshed successfully.' : 'Storage link created successfully.', true);
    }

    /** @param array<string, mixed> $website */
    private function inspectWebsiteApplication(array $website): array
    {
        $installationRoot = $this->wordpressInstallService->resolveInstallationRoot($website);
        $candidates = array_values(array_unique(array_filter([
            $installationRoot,
            (string) ($website['project_root'] ?? ''),
            (string) ($website['root_path'] ?? ''),
        ])));
        $fallback = null;

        foreach ($candidates as $candidate) {
            $inspection = $this->wordpressInstallService->inspectRootDirectory($candidate);
            $inspection['root_path'] = $candidate;
            if (strtolower((string) ($inspection['detected_app'] ?? '')) === 'laravel') {
                $artisanPath = $this->resolveProjectArtisanPath($candidate);
                $projectPath = $artisanPath !== null ? dirname($artisanPath) : rtrim($candidate, '/');
                $storagePath = $projectPath.'/public/storage';
                $inspection['storage_linked'] = is_link($storagePath);
                $inspection['storage_link_path'] = $storagePath;
            }
            $fallback ??= $inspection;
            if (in_array((string) ($inspection['detected_app'] ?? ''), ['wordpress', 'laravel'], true)) {
                return $inspection;
            }
        }

        return $fallback ?? [
            'detected_app' => 'missing',
            'root_path' => $installationRoot,
        ];
    }

    /**
     * Update website request.
     */
    public function update(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        return $this->websiteCreateEdit->update($request, $id, $this->websiteMutationDeps($request));
    }

    /**
     * @return array<string, callable>
     */
    protected function websiteMutationDeps(Request $request): array
    {
        return [
            'buildCommand' => fn (array $payload): string => $this->buildCommand($payload),
            'applyWebsiteFilesystemIsolation' => function (string $siteOwner, string $projectRoot, string $rootPath): void {
                $this->applyWebsiteFilesystemIsolation($siteOwner, $projectRoot, $rootPath);
            },
            'ensureWebsiteFoldersExist' => function (Request $request, string $rootPath, string $projectRoot, string $context): RedirectResponse|JsonResponse|null {
                return $this->filemanagerService->ensureWebsiteFoldersExist($request, $rootPath, $projectRoot, $context);
            },
            'installSelectedApplication' => fn (string $installer, string $rootPath, string $domain, string $phpVersion, string $wordpressVersion = 'latest'): array => $this->installSelectedApplication($installer, $rootPath, $domain, $phpVersion, $wordpressVersion),
            'initializeWebsiteStarterFiles' => function (string $rootPath, string $domain, ?string $phpVersion = null): void {
                $this->initializeWebsiteStarterFiles($rootPath, $domain, $phpVersion);
            },
            'runIssueSslScript' => fn (string $domain, string $rootPath, bool $includeWwwAlias): array => $this->runIssueSslScript($domain, $rootPath, $includeWwwAlias),
            'shouldAddWwwAlias' => fn (string $domain): bool => $this->shouldAddWwwAlias($domain),
            'readRequests' => fn (): array => $this->readRequests(),
            'writeRequests' => function (array $requests): void {
                $this->writeRequests($requests);
            },
            'detectRuntimeStatus' => fn (array $website): string => $this->detectRuntimeStatus($website),
            'defaultResellerId' => function () use ($request): ?int {
                $actor = $request->user();

                return $actor && $actor->hasRole('reseller') ? (int) $actor->id : null;
            },
            'websiteModelToArray' => fn (Website $website): array => $this->websiteModelToArray($website),
        ];
    }

    /**
     * File manager for website root.
     */
    public function fileManager(Request $request, string $token, string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $websiteRoot = $this->normalizeRootPath((string) ($website['root_path'] ?? ''), (string) ($website['domain'] ?? 'site'));
        $defaultPath = $scopeRoot !== ''
            ? $scopeRoot
            : $this->relativePathFromBase($basePath, $websiteRoot);
        $currentPath = $this->sanitizeRelativePath((string) $request->query('path', $defaultPath));
        $showHidden = $request->has('show_hidden')
            ? $request->boolean('show_hidden')
            : (bool) ($website['filemanager_show_hidden'] ?? false);
        $directory = $this->resolvePathInsideBase($basePath, $currentPath);

        if (! is_dir($directory)) {
            $fallbackPath = $this->resolvePathInsideBase($basePath, $defaultPath);
            if (is_dir($fallbackPath)) {
                $directory = $fallbackPath;
                $currentPath = $defaultPath;
            } else {
                $directory = $basePath;
                $currentPath = '';
            }
        }

        $items = $this->listDirectoryItems($basePath, $directory, $showHidden);

        $selectedFilePath = $this->sanitizeRelativePath((string) ($request->query('file', $request->query('file_path', ''))));
        $selectedFile = $this->readSelectedFile($basePath, $selectedFilePath);
        $directoryTree = $this->buildDirectoryTree($basePath, '', 24, $showHidden, $currentPath);

        return Inertia::render('FileManager/FileManager', [
            'website' => [
                'id' => $website['id'],
                'domain' => $website['domain'] ?? '',
                'root_path' => $website['root_path'] ?? '',
                'project_root' => $website['project_root'] ?? '',
            ],
            'basePath' => $basePath,
            'rootFolder' => $scopeRoot,
            'currentPath' => $currentPath,
            'showHidden' => $showHidden,
            'openUploadTab' => $request->boolean('open_upload', false),
            'openEditorModal' => $request->boolean('open_editor', false),
            'openEditorPage' => $request->boolean('editor_page', false),
            'hasProjectArtisan' => $this->resolveProjectArtisanPath($directory) !== null,
            'directoryTree' => $directoryTree,
            'items' => $items,
            'selectedFile' => $selectedFile,
        ]);
    }

    public function updateFileManagerSettings(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'show_hidden' => ['required', 'boolean'],
            'path' => ['nullable', 'string', 'max:1000'],
            'root' => ['nullable', 'string', 'max:1000'],
            'file_path' => ['nullable', 'string', 'max:1000'],
        ]);

        Website::query()
            ->where('id', $website['id'])
            ->update([
                'filemanager_show_hidden' => (bool) $validated['show_hidden'],
                'updated_at' => now(),
            ]);

        return redirect()->route(
            'websites.filemanager',
            $this->fileManagerRouteParams(
                $id,
                (string) ($validated['path'] ?? ''),
                (string) ($validated['root'] ?? ''),
                array_filter([
                    'file_path' => (string) ($validated['file_path'] ?? ''),
                ], fn ($value): bool => $value !== null && $value !== ''),
            ),
        );
    }

    public function createFolder(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1000'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\\/:*?\"<>|]+$/'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));
        $folderName = trim((string) $validated['name']);
        $targetRelative = $this->sanitizeRelativePath(trim($currentPath.'/'.$folderName, '/'));
        $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);

        if (is_dir($targetPath)) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Folder already exists.');
        }

        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        try {
            $this->filemanagerService->createDirectory($siteOwner, $targetPath);
        } catch (\Throwable $e) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to create folder. '.$e->getMessage());
        }

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', 'Folder created.');
    }

    public function createFile(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1000'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\\/:*?\"<>|]+$/'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));
        $fileName = trim((string) $validated['name']);
        $targetRelative = $this->sanitizeRelativePath(trim($currentPath.'/'.$fileName, '/'));
        $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);

        if (is_file($targetPath)) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'File already exists.');
        }

        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        try {
            $this->filemanagerService->writeTextFile($siteOwner, $targetPath, '');
        } catch (\Throwable $e) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to create file. '.$e->getMessage());
        }

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot, ['file' => $targetRelative]))->with('success', 'File created.');
    }

    public function saveFile(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'file_path' => ['required', 'string', 'max:1500'],
            'content' => ['nullable', 'string'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $fileRelative = $this->sanitizeRelativePath((string) $validated['file_path']);
        $filePath = $this->resolvePathInsideBase($basePath, $fileRelative);

        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        try {
            $this->filemanagerService->writeTextFile(
                $siteOwner,
                $filePath,
                (string) ($validated['content'] ?? ''),
                true,
            );
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Failed to save file.', 'error' => $e->getMessage()], 422);
            }

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, '', $scopeRoot, ['file_path' => $fileRelative]))->with('error', 'Failed to save file. '.$e->getMessage());
        }

        $parent = dirname($fileRelative);
        $path = $parent === '.' ? '' : $parent;

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'File saved.',
                'file_path' => $fileRelative,
                'path' => $path,
                'scope_root' => $scopeRoot,
            ]);
        }

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $path, $scopeRoot, ['file_path' => $fileRelative]))->with('success', 'File saved.');
    }

    public function uploadFile(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        // This request is received by the main panel PHP runtime. The target
        // Website limits do not apply to the panel file manager.
        $uploadMaxKilobytes = min(
            $this->iniSizeToKilobytes((string) ini_get('upload_max_filesize'), 2 * 1024 * 1024),
            $this->iniSizeToKilobytes((string) ini_get('post_max_size'), 2 * 1024 * 1024),
        );

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1500'],
            'upload' => ['nullable', 'file', 'max:'.$uploadMaxKilobytes],
            'uploads' => ['nullable', 'array', 'max:100'],
            'uploads.*' => ['file', 'max:'.$uploadMaxKilobytes],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));

        $uploadedFiles = $request->file('uploads', []);
        if (! is_array($uploadedFiles)) {
            $uploadedFiles = [];
        }
        if ($request->hasFile('upload')) {
            $uploadedFiles[] = $request->file('upload');
        }
        $uploadedFiles = array_values(array_filter($uploadedFiles));

        if ($uploadedFiles === []) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot, ['open_upload' => 1]))->with('error', 'Upload file not found.');
        }

        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        try {
            foreach ($uploadedFiles as $index => $uploaded) {
                $filename = $this->sanitizeFilename((string) $uploaded->getClientOriginalName());
                if ($filename === '') {
                    $filename = 'uploaded-file-'.($index + 1);
                }

                $targetPath = $this->resolvePathInsideBase($basePath, $this->sanitizeRelativePath(trim($currentPath.'/'.$filename, '/')));
                $this->filemanagerService->uploadFile($siteOwner, $targetPath, $uploaded->getPathname());
            }
        } catch (\Throwable $e) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot, ['open_upload' => 1]))->with('error', 'Failed to upload file. '.$e->getMessage());
        }

        $message = count($uploadedFiles) === 1
            ? 'File uploaded successfully.'
            : count($uploadedFiles).' files uploaded successfully.';

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot, ['open_upload' => 1]))->with('success', $message);
    }

    public function changePermissions(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
            'permissions' => ['required', 'string', 'regex:/^[0-7]{3,4}$/'],
            'recursive' => ['nullable', 'boolean'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $itemRelative = $this->sanitizeRelativePath((string) $validated['item_path']);
        $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));

        if (! file_exists($itemPath)) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Item not found.');
        }

        $recursive = (bool) ($validated['recursive'] ?? false);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        try {
            $this->filemanagerService->changePermissions(
                $siteOwner,
                $itemPath,
                (string) $validated['permissions'],
                $recursive && is_dir($itemPath)
            );
        } catch (\Throwable $e) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to change permissions. '.$e->getMessage());
        }

        $message = $recursive && is_dir($itemPath)
            ? 'Permissions updated recursively.'
            : 'Permissions updated.';

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', $message);
    }

    public function renameItem(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
            'new_name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\\/:*?\"<>|]+$/'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $itemRelative = $this->sanitizeRelativePath((string) $validated['item_path']);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);

        $newName = $this->sanitizeFilename((string) $validated['new_name']);
        if ($newName === '') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid new name.'], 422);
            }
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Invalid new name.');
        }

        $parent = dirname($itemRelative);
        $parent = $parent === '.' ? '' : $parent;
        $targetRelative = $this->sanitizeRelativePath(trim($parent.'/'.$newName, '/'));
        $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);

        try {
            $this->filemanagerService->movePath($siteOwner, $itemPath, $targetPath);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Failed to rename item. '.$e->getMessage()], 422);
            }
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to rename item. '.$e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Item renamed.', 'path' => $targetRelative]);
        }

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', 'Item renamed.');
    }

    public function moveItems(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['nullable', 'string', 'max:1500'],
            'item_paths' => ['nullable', 'array', 'min:1'],
            'item_paths.*' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
            'destination_path' => ['nullable', 'string', 'max:1500'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $destinationPathRelative = $this->sanitizeRelativePath((string) ($validated['destination_path'] ?? ''));
        $this->resolvePathInsideBase($basePath, $destinationPathRelative);

        $allItems = [];
        if (! empty($validated['item_path'])) {
            $allItems[] = $this->sanitizeRelativePath((string) $validated['item_path']);
        }
        foreach ((array) ($validated['item_paths'] ?? []) as $multiItem) {
            $allItems[] = $this->sanitizeRelativePath((string) $multiItem);
        }

        $allItems = array_values(array_unique(array_filter($allItems)));
        if (count($allItems) === 0) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'No item selected to move.');
        }

        $movedCount = 0;
        $sameDestinationCount = 0;
        $errors = [];

        foreach ($allItems as $itemRelative) {
            $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);
            $targetRelative = $this->sanitizeRelativePath(trim($destinationPathRelative.'/'.basename($itemRelative), '/'));
            if ($targetRelative === $itemRelative) {
                $sameDestinationCount++;

                continue;
            }

            if (str_starts_with($targetRelative.'/', $itemRelative.'/')) {
                $errors[] = "Cannot move folder into itself: {$itemRelative}";

                continue;
            }

            $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);
            try {
                $this->filemanagerService->movePath($siteOwner, $itemPath, $targetPath);
            } catch (\Throwable $e) {
                $errors[] = basename($itemRelative).': '.$e->getMessage();

                continue;
            }

            $movedCount++;
        }

        if ($movedCount === 0 && $sameDestinationCount > 0 && count($errors) === 0) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Selected item(s) are already in that folder.');
        }

        if ($movedCount === 0) {
            $details = implode(' | ', array_slice($errors, 0, 3));

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', $details !== '' ? "Move failed. {$details}" : 'Move failed.');
        }

        if (count($errors) > 0 || $sameDestinationCount > 0) {
            $parts = ["Moved {$movedCount} item(s)."];
            if ($sameDestinationCount > 0) {
                $parts[] = "{$sameDestinationCount} already in destination.";
            }
            if (count($errors) > 0) {
                $details = implode(' | ', array_slice($errors, 0, 2));
                $parts[] = 'Skipped: '.$details;
            }

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', implode(' ', $parts));
        }

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', "Moved {$movedCount} item(s).");
    }

    public function downloadFile(Request $request, string $token, string $id): BinaryFileResponse|RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'file_path' => ['required', 'string', 'max:1500'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $fileRelative = $this->sanitizeRelativePath((string) $validated['file_path']);
        $filePath = $this->resolvePathInsideBase($basePath, $fileRelative);

        if (! is_file($filePath)) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, '', $scopeRoot))->with('error', 'File not found for download.');
        }

        if ($request->boolean('inline', false)) {
            return response()->file($filePath);
        }

        return response()->download($filePath, basename($filePath));
    }

    public function zipSelected(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'current_path' => ['nullable', 'string', 'max:1500'],
            'item_paths' => ['required', 'array', 'min:1'],
            'item_paths.*' => ['required', 'string', 'max:1500'],
            'zip_name' => ['nullable', 'string', 'max:255'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $itemPaths = collect((array) $validated['item_paths'])
            ->map(fn ($path) => $this->sanitizeRelativePath((string) $path))
            ->filter(fn (string $path) => $path !== '')
            ->values()
            ->all();

        if (count($itemPaths) === 0) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'No valid items selected for zip.');
        }

        $zipNameInput = trim((string) ($validated['zip_name'] ?? ''));
        $zipName = $this->sanitizeFilename($zipNameInput !== '' ? $zipNameInput : 'archive-'.now()->format('Ymd-His'));
        if (! str_ends_with(strtolower($zipName), '.zip')) {
            $zipName .= '.zip';
        }

        $zipRelative = $this->sanitizeRelativePath(trim($currentPath.'/'.$zipName, '/'));
        $zipPath = $this->resolvePathInsideBase($basePath, $zipRelative);

        if (file_exists($zipPath)) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Zip file already exists.');
        }

        if (! class_exists(ZipArchive::class)) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', $this->zipExtensionMissingMessage());
        }

        $zipTempDir = $this->resolveTemporaryDirectory().'/filemanager-zips';
        if (! is_dir($zipTempDir) && ! @mkdir($zipTempDir, 0775, true) && ! is_dir($zipTempDir)) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to prepare temporary zip folder.');
        }

        if (! is_writable($zipTempDir)) {
            @chmod($zipTempDir, 0775);
        }

        $zipTempPath = $this->buildTemporaryFilePath($zipTempDir, 'zip-', '.zip');
        if (@touch($zipTempPath) === false) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to create temporary zip file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipTempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipTempPath);

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to create zip file.');
        }

        foreach ($itemPaths as $itemRelative) {
            $sourcePath = $this->resolvePathInsideBase($basePath, $itemRelative);
            if (! file_exists($sourcePath)) {
                continue;
            }

            $baseName = basename($sourcePath);
            if (is_dir($sourcePath)) {
                $this->addDirectoryToZip($zip, $sourcePath, $baseName);
            } else {
                $zip->addFile($sourcePath, $baseName);
            }
        }

        if ($zip->close() !== true) {
            @unlink($zipTempPath);

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to finalize zip file.');
        }

        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        try {
            $this->filemanagerService->uploadFile($siteOwner, $zipPath, $zipTempPath);
        } catch (\Throwable $e) {
            @unlink($zipTempPath);

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to save zip file. '.$e->getMessage());
        }

        @unlink($zipTempPath);

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', 'Zip created successfully.');
    }

    public function unzipItem(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'zip_path' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $zipRelative = $this->sanitizeRelativePath((string) $validated['zip_path']);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $zipPath = $this->resolvePathInsideBase($basePath, $zipRelative);
        $destinationPath = $this->resolvePathInsideBase($basePath, $currentPath);

        if (! is_file($zipPath) || ! str_ends_with(strtolower($zipPath), '.zip')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Valid zip file not found.'], 422);
            }

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Valid zip file not found.');
        }

        try {
            $this->filemanagerService->unzipFile($siteOwner, $zipPath, $destinationPath);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Failed to extract zip. '.$e->getMessage()], 422);
            }

            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to extract zip. '.$e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Zip extracted successfully.']);
        }

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', 'Zip extracted successfully.');
    }

    public function deleteItem(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['nullable', 'string', 'max:1500'],
            'item_paths' => ['nullable', 'array', 'min:1'],
            'item_paths.*' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $allItems = [];

        if (! empty($validated['item_path'])) {
            $allItems[] = $this->sanitizeRelativePath((string) $validated['item_path']);
        }

        foreach ((array) ($validated['item_paths'] ?? []) as $multiItem) {
            $allItems[] = $this->sanitizeRelativePath((string) $multiItem);
        }

        $allItems = array_values(array_unique(array_filter($allItems)));
        if (count($allItems) === 0) {
            return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'No item selected to delete.');
        }

        foreach ($allItems as $itemRelative) {
            $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);
            try {
                $this->filemanagerService->deletePath($siteOwner, $itemPath);
            } catch (\Throwable $e) {
                return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('error', 'Failed to delete '.basename($itemRelative).'. '.$e->getMessage());
            }
        }

        return redirect()->route('websites.filemanager', $this->fileManagerRouteParams($id, $currentPath, $scopeRoot))->with('success', 'Selected item(s) deleted.');
    }

    /**
     * Delete website request.
     */
    public function destroy(string $token, string $id): RedirectResponse
    {
        $requests = collect($this->readRequests());
        $existingRequest = $this->findAuthorizedWebsiteOrFail($id);
        $before = $requests->count();
        $filtered = $requests->reject(fn (array $item) => ($item['id'] ?? null) === $id)->values();

        if ($filtered->count() === $before) {
            return redirect()->route('websites.list')->with('error', 'Website request not found.');
        }

        $this->writeRequests($filtered->all());

        return redirect()->route('websites.list')->with('success', 'Website request deleted successfully.');
    }

    public function updateStatus(Request $request, string $token, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:enabled,disabled'],
        ]);

        $requests = collect($this->readRequests());
        $existingRequest = $this->findAuthorizedWebsiteOrFail($id);

        $targetStatus = $validated['status'] === 'disabled' ? 'disabled' : 'pending';

        $updated = $requests->map(function (array $item) use ($id, $targetStatus): array {
            if ((string) ($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['status'] = $targetStatus;
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($updated);

        return redirect()->route('websites.list')->with('success', $validated['status'] === 'disabled'
            ? 'Website disabled successfully.'
            : 'Website enabled successfully.');
    }

    public function refreshRuntimeStatus(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $status = $this->detectRuntimeStatus($website);

        Website::query()->whereKey($id)->update([
            'status' => $status,
            'updated_at' => now(),
        ]);

        return response()->json([
            'type' => $status === 'live' ? 'success' : 'warning',
            'status' => $status,
            'message' => "Website check complete: status is {$status}.",
        ]);
    }

    /**
     * Build execution command from payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function buildCommand(array $payload): string
    {
        return sprintf(
            '/usr/local/bin/serverinstaller-site create --domain=%s --root=%s --php=%s%s',
            escapeshellarg((string) $payload['domain']),
            escapeshellarg((string) $payload['root_path']),
            escapeshellarg((string) $payload['php_version']),
            ! empty($payload['enable_ssl']) ? ' --ssl' : '',
        );
    }

    protected function normalizeStartDirectoryAlias(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        if ($value === '') {
            return '';
        }

        return substr($value, 0, 255);
    }

    /**
     * @return RedirectResponse|JsonResponse|null
     */

    /**
     * Best-effort ownership and chmod isolation.
     * Only applies when running as root on Linux.
     */
    protected function applyWebsiteFilesystemIsolation(string $siteOwner, string $projectRoot, string $rootPath): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $homePath = rtrim($this->websiteBaseDirectory(), '/')."/{$siteOwner}";
        $projectRoot = trim(str_replace('\\', '/', $projectRoot));
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        $publicRoot = $homePath.'/'.self::DEFAULT_SITE_DIR;
        if ($projectRoot === '' || ! str_starts_with($projectRoot, $homePath)) {
            $projectRoot = $homePath;
        }
        if ($rootPath === '' || ! str_starts_with($rootPath, $homePath.'/')) {
            $rootPath = $publicRoot;
        }

        $this->runSystemCommand('getent group '.escapeshellarg($siteOwner).' >/dev/null 2>&1 || groupadd '.escapeshellarg($siteOwner));
        $this->runSystemCommand('id -u '.escapeshellarg($siteOwner).' >/dev/null 2>&1 || useradd -m -d '.escapeshellarg($homePath).' -s /usr/sbin/nologin -g '.escapeshellarg($siteOwner).' '.escapeshellarg($siteOwner));
        $this->runSystemCommand('mkdir -p '.escapeshellarg($homePath));
        $this->runSystemCommand('chown root:root '.escapeshellarg($homePath));
        $this->runSystemCommand('chmod 711 '.escapeshellarg($homePath));
        $this->runSystemCommand('mkdir -p '.escapeshellarg($projectRoot));
        $this->runSystemCommand('chown -R '.escapeshellarg($siteOwner).':www-data '.escapeshellarg($projectRoot));
        $this->runSystemCommand('find '.escapeshellarg($projectRoot).' -type d -exec chmod 750 {} \\;');
        $this->runSystemCommand('find '.escapeshellarg($projectRoot).' -type f -exec chmod 640 {} \\;');
        $this->runSystemCommand('mkdir -p '.escapeshellarg($publicRoot));
        $this->runSystemCommand('mkdir -p '.escapeshellarg($rootPath));
    }

    protected function runSystemCommand(string $command): void
    {
        try {
            @shell_exec($command.' 2>&1');
        } catch (\Throwable $e) {
            // Ignore to keep website flow non-blocking.
        }
    }

    protected function resolveProjectArtisanPath(string $rootPath): ?string
    {
        $normalized = rtrim($this->normalizeAbsolutePath($rootPath), '/');
        if ($normalized === '') {
            return null;
        }

        $parent = rtrim($this->normalizeAbsolutePath(dirname($normalized)), '/');
        $candidates = [
            $normalized.'/artisan',
            $parent !== '' && $parent !== '.' ? $parent.'/artisan' : '',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{success: bool, output: string, exit_code: int}
     */
    protected function runProjectArtisanCommand(string $projectPath, string $artisanCommand, string $siteOwner = ''): array
    {
        $projectPath = rtrim($this->normalizeAbsolutePath($projectPath), '/');
        if ($projectPath === '' || ! is_dir($projectPath)) {
            return [
                'success' => false,
                'output' => 'Invalid project path.',
                'exit_code' => 1,
            ];
        }

        $phpBinary = $this->resolvePhpCliBinary();

        $snippet = sprintf(
            'cd %s && %s artisan %s',
            escapeshellarg($projectPath),
            escapeshellarg($phpBinary),
            escapeshellarg($artisanCommand),
        );

        $output = [];
        $exitCode = 1;
        $owner = trim($siteOwner);
        $canRunAsOwner = $owner !== ''
            && preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $owner) === 1
            && function_exists('posix_geteuid')
            && posix_geteuid() === 0;

        if ($canRunAsOwner) {
            $ownerOutput = [];
            $ownerExitCode = 1;
            @exec(
                'runuser -u '.escapeshellarg($owner).' -- sh -lc '.escapeshellarg($snippet).' 2>&1',
                $ownerOutput,
                $ownerExitCode,
            );
            if ($ownerExitCode === 0) {
                return [
                    'success' => true,
                    'output' => trim(implode("\n", $ownerOutput)),
                    'exit_code' => 0,
                ];
            }
            $output = $ownerOutput;
            $exitCode = $ownerExitCode;
        }

        $directOutput = [];
        $directExitCode = 1;
        @exec('sh -lc '.escapeshellarg($snippet).' 2>&1', $directOutput, $directExitCode);

        if ($canRunAsOwner && count($output) > 0) {
            $directOutput = array_merge($output, ['---- fallback as current user ----'], $directOutput);
        }

        return [
            'success' => $directExitCode === 0,
            'output' => trim(implode("\n", $directOutput)),
            'exit_code' => $directExitCode,
        ];
    }

    protected function resolvePhpCliBinary(): string
    {
        $candidates = [
            defined('PHP_CLI_BINARY') ? (string) PHP_CLI_BINARY : '',
            trim((string) PHP_BINARY),
            PHP_BINDIR ? rtrim((string) PHP_BINDIR, '/').'/php' : '',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
            '/usr/bin/php8.0',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $basename = basename($candidate);
            if (str_starts_with($basename, 'php-fpm')) {
                continue;
            }

            if (str_contains($candidate, '/')) {
                if (is_executable($candidate)) {
                    return $candidate;
                }

                continue;
            }

            return $candidate;
        }

        return 'php';
    }

    /**
     * @return array{ran: bool, success: bool, output: string, exit_code: int}
     */
    protected function runIssueSslScript(string $domain, string $rootPath, bool $includeWwwAlias): array
    {
        $scriptCandidates = [
            base_path('scripts/issue-ssl.sh'),
            '/usr/local/bin/serverpanel-issue-ssl.sh',
        ];

        $scriptPath = $this->resolveScriptPath($scriptCandidates);
        if ($scriptPath === '') {
            return ['ran' => false, 'success' => false, 'output' => 'issue-ssl script not found', 'exit_code' => 1];
        }

        $result = app(ScriptExecutionGateway::class)->execute($scriptPath, [
            $this->normalizeDomain($domain),
            $rootPath,
            $includeWwwAlias ? '1' : '0',
        ], [], true);
        $message = trim((string) ($result['output'] ?? ''));
        if (! ($result['success'] ?? false)) {
            Log::warning('SSL issue script failed', [
                'domain' => $domain,
                'root_path' => $rootPath,
                'include_www_alias' => $includeWwwAlias,
                'script' => $scriptPath,
                'exit_code' => (int) ($result['exit_code'] ?? 1),
                'output' => $message,
            ]);
        }

        return [
            'ran' => (bool) ($result['ran'] ?? true),
            'success' => (bool) ($result['success'] ?? false),
            'output' => $message,
            'exit_code' => (int) ($result['exit_code'] ?? 1),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function visibleRequestsForActor(?User $actor = null): Collection
    {
        $actor ??= request()->user();

        return collect($this->readRequests())
            ->filter(fn (array $website): bool => $this->actorCanAccessWebsite($website, $actor))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $requests
     * @return array<int, array<string, mixed>>
     */
    protected function decorateWebsiteRecords(Collection $requests): array
    {
        $domains = $requests
            ->map(fn (array $item): string => strtolower(trim((string) ($item['domain'] ?? ''))))
            ->filter()
            ->unique()
            ->values();

        $certificatesByDomain = $domains->isNotEmpty()
            ? SslCertificate::query()
                ->whereIn('domain', $domains->all())
                ->orderBy('expires_at')
                ->get(['domain', 'status', 'expires_at'])
                ->keyBy(fn (SslCertificate $certificate): string => strtolower(trim($certificate->domain)))
            : collect();
        $managedDomainsByName = $domains->isNotEmpty()
            ? Domain::query()
                ->whereIn('name', $domains->all())
                ->get(['name', 'ssl_status', 'ssl_expires_at'])
                ->keyBy(fn (Domain $domain): string => strtolower(trim($domain->name)))
            : collect();

        $assignmentUserIds = $requests
            ->flatMap(fn (array $item): array => [
                (int) ($item['assigned_user_id'] ?? 0),
                (int) ($item['assigned_reseller_id'] ?? 0),
            ])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $usersById = count($assignmentUserIds) > 0
            ? User::query()->whereIn('id', $assignmentUserIds)->get(['id', 'name', 'email'])->keyBy('id')
            : collect();

        return $requests
            ->map(function (array $item) use ($usersById, $certificatesByDomain, $managedDomainsByName): array {
                $item = $this->normalizeWebsiteRecord($item);
                $runtimeStatus = $this->detectRuntimeStatus($item);
                if ($runtimeStatus !== '') {
                    $item['status'] = $runtimeStatus;
                    if (! empty($item['id'])) {
                        Website::query()->whereKey((string) $item['id'])
                            ->where('status', '!=', $runtimeStatus)
                            ->update(['status' => $runtimeStatus, 'updated_at' => now()]);
                    }
                }
                $domain = (string) ($item['domain'] ?? '');
                $domainKey = strtolower(trim($domain));
                $certificate = $certificatesByDomain->get($domainKey);
                $managedDomain = $managedDomainsByName->get($domainKey);
                $expiresAt = $certificate?->expires_at ?? $managedDomain?->ssl_expires_at;

                $item['ssl_status'] = $certificate?->status
                    ?? $managedDomain?->ssl_status
                    ?? ($item['ssl_status'] ?? 'unknown');
                $item['ssl_expires_at'] = $expiresAt?->toIso8601String();
                $item['ssl_days_remaining'] = $expiresAt !== null
                    ? (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false)
                    : null;

                $assignedUserId = (int) ($item['assigned_user_id'] ?? 0);
                $assignedResellerId = (int) ($item['assigned_reseller_id'] ?? 0);
                $assignedUser = $assignedUserId > 0 ? $usersById->get($assignedUserId) : null;
                $assignedReseller = $assignedResellerId > 0 ? $usersById->get($assignedResellerId) : null;

                $item['assigned_user_id'] = $assignedUserId > 0 ? $assignedUserId : null;
                $item['assigned_reseller_id'] = $assignedResellerId > 0 ? $assignedResellerId : null;
                $item['assigned_user_name'] = $assignedUser?->name ?? null;
                $item['assigned_reseller_name'] = $assignedReseller?->name ?? null;
                $item['created_by_label'] = $assignedReseller?->name ?? $assignedUser?->name ?? 'Admin';

                return $item;
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    protected function isValidWebsiteRootPath(string $rootPath): bool
    {
        $homeBase = $this->websiteBaseDirectory();
        $normalized = $this->normalizeAbsolutePath($rootPath);
        if (! $this->pathStartsWith($normalized, $homeBase.'/')) {
            return false;
        }

        $suffix = trim(substr($normalized, strlen($homeBase.'/')), '/');
        if ($suffix === '') {
            return false;
        }

        $parts = array_values(array_filter(explode('/', $suffix), fn (string $part) => $part !== '' && $part !== '.' && $part !== '..'));
        $owner = (string) ($parts[0] ?? '');
        $siteDir = (string) ($parts[1] ?? '');

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', strtolower($owner)) !== 1) {
            return false;
        }

        if ($siteDir === '' || preg_match('/^[A-Za-z0-9._-]+$/', $siteDir) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Create first-time starter files for newly created empty site root.
     */
    protected function initializeWebsiteStarterFiles(string $rootPath, string $domain, ?string $phpVersion = null): void
    {
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        if ($rootPath === '') {
            return;
        }

        if (! is_dir($rootPath) && ! @mkdir($rootPath, 0755, true) && ! is_dir($rootPath)) {
            return;
        }

        $indexPhpPath = rtrim($rootPath, '/').'/index.php';
        $indexHtmlPath = rtrim($rootPath, '/').'/index.html';
        $existingPhp = is_file($indexPhpPath) ? (string) @file_get_contents($indexPhpPath) : '';
        $existingHtml = is_file($indexHtmlPath) ? (string) @file_get_contents($indexHtmlPath) : '';
        $managedStarter = str_contains($existingPhp, '@serverpanel-starter')
            || str_contains($existingPhp, 'Starter page generated by ServerPanel')
            || str_contains($existingPhp, 'Managed by <strong>ServerPanel</strong>')
            || str_contains($existingHtml, 'Starter page generated by ServerPanel');

        $entries = array_values(array_filter(
            (array) @scandir($rootPath),
            fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
        if ($entries !== [] && ! $managedStarter) {
            return;
        }

        $template = @file_get_contents(resource_path('stubs/website/index.php'));
        if (! is_string($template) || ! str_contains($template, '@serverpanel-starter')) {
            return;
        }

        $siteOwner = $this->extractSiteOwnerFromRootPath($rootPath);
        try {
            $this->filemanagerService->writeTextFile($siteOwner, $indexPhpPath, $template);
        } catch (\Throwable $e) {
            @file_put_contents($indexPhpPath, $template, LOCK_EX);
        }

        if ($existingHtml === '' || str_contains($existingHtml, 'Starter page generated by ServerPanel')) {
            try {
                if (is_file($indexHtmlPath)) {
                    $this->filemanagerService->deletePath($siteOwner, $indexHtmlPath);
                }
            } catch (\Throwable $e) {
                @unlink($indexHtmlPath);
            }
        }

        $legacyNote = rtrim($rootPath, '/').'/extra/first-site-note.txt';
        if (is_file($legacyNote) && str_contains((string) @file_get_contents($legacyNote), 'first website creation')) {
            try {
                $this->filemanagerService->deletePath($siteOwner, $legacyNote);
            } catch (\Throwable $e) {
                @unlink($legacyNote);
            }
            @rmdir(dirname($legacyNote));
        }
    }

    /**
     * @return array{attempted: bool, installed: bool, message: string}
     */
    protected function installSelectedApplication(string $installer, string $rootPath, string $domain, string $phpVersion, string $wordpressVersion = 'latest'): array
    {
        $normalized = strtolower(trim($installer));
        if ($normalized === '' || $normalized === 'none') {
            return [
                'attempted' => false,
                'installed' => false,
                'message' => '',
            ];
        }

        if ($normalized === 'wordpress') {
            return $this->installWordPressApplication($rootPath, $wordpressVersion);
        }

        return [
            'attempted' => true,
            'installed' => false,
            'message' => "Unsupported installer selected: {$normalized}.",
        ];
    }

    protected function hasWordPressFiles(string $rootPath): bool
    {
        $normalizedRootPath = rtrim(str_replace('\\', '/', trim($rootPath)), '/');
        if ($normalizedRootPath === '') {
            return false;
        }

        return is_file($normalizedRootPath.'/wp-includes/version.php')
            || is_file($normalizedRootPath.'/wp-config.php')
            || is_dir($normalizedRootPath.'/wp-admin');
    }

    /**
     * @return array{attempted: bool, installed: bool, message: string}
     */
    protected function installWordPressApplication(string $rootPath, string $wordpressVersion = 'latest'): array
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
                $zip = new ZipArchive;
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

    protected function normalizeWordPressDatabasePrefix(string $prefix, string $domain = ''): string
    {
        $normalized = strtolower(trim($prefix));
        if ($normalized === '') {
            $normalized = (string) Str::of(explode('.', $this->normalizeDomain($domain))[0] ?? '')
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->limit(20, '');
        }

        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? substr($normalized, 0, 32) : 'wp';
    }

    /**
     * @return array<string, string>
     */
    protected function resolveWordPressDatabaseConfig(string $databasePrefix, string $domain, ?DatabaseRequest $existingDatabaseRequest = null): array
    {
        $base = $this->normalizeWordPressDatabasePrefix($databasePrefix, $domain);

        if ($existingDatabaseRequest !== null) {
            $storedName = trim((string) $existingDatabaseRequest->database_name);
            $storedUser = trim((string) $existingDatabaseRequest->database_user);
            $storedPassword = trim((string) $existingDatabaseRequest->database_password);
            $storedHost = trim((string) $existingDatabaseRequest->database_host);

            if ($storedName === '' || $storedUser === '' || $storedPassword === '') {
                $existingDatabaseRequest = null;
            } else {
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

    protected function makeWordPressDatabaseIdentifier(string $prefix, string $suffix): string
    {
        $identifier = trim($prefix.'_'.$suffix, '_');
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '_', $identifier) ?? $identifier;

        return substr($identifier, 0, 64);
    }

    protected function generateWordPressDatabasePassword(): string
    {
        try {
            return bin2hex(random_bytes(16)).'!A1';
        } catch (\Throwable $e) {
            return Str::random(24).'!A1';
        }
    }

    protected function runtimeDatabaseScriptPath(): string
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
     * @param  array<int, string>  $scriptCandidates
     */
    protected function resolveScriptPath(array $scriptCandidates): string
    {
        foreach ($scriptCandidates as $candidate) {
            if (trim((string) $candidate) !== '') {
                return (string) $candidate;
            }
        }

        return '';
    }

    /**
     * @param  array<string, string>  $databaseConfig
     * @return array{ran: bool, success: bool, output: string}
     */
    protected function provisionWordPressDatabase(array $databaseConfig): array
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
     * @param  array<string, string>  $databaseConfig
     */
    protected function buildWordPressDatabaseCommand(array $databaseConfig): string
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
     * @param  array<string, string>  $databaseConfig
     * @return array{success: bool, message: string}
     */
    protected function writeWordPressConfig(string $rootPath, array $databaseConfig, string $tablePrefix): array
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

    protected function normalizeWordPressVersion(string $version): string
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

    /**
     * @return array<int, string>
     */
    protected function getWordPressVersionOptions(): array
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
     * @return array{success: bool, message: string}
     */
    protected function copyDirectoryContentsRecursive(string $sourceDirectory, string $targetDirectory): array
    {
        if (! is_dir($sourceDirectory)) {
            return [
                'success' => false,
                'message' => 'Source directory does not exist.',
            ];
        }

        if (! is_dir($targetDirectory) && ! @mkdir($targetDirectory, 0755, true) && ! is_dir($targetDirectory)) {
            return [
                'success' => false,
                'message' => 'Cannot create target directory.',
            ];
        }

        $items = @scandir($sourceDirectory);
        if (! is_array($items)) {
            return [
                'success' => false,
                'message' => 'Cannot read source directory entries.',
            ];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourcePath = $sourceDirectory.'/'.$item;
            $targetPath = $targetDirectory.'/'.$item;

            if (is_dir($sourcePath)) {
                $result = $this->copyDirectoryContentsRecursive($sourcePath, $targetPath);
                if (! $result['success']) {
                    return $result;
                }

                continue;
            }

            if (! @copy($sourcePath, $targetPath)) {
                return [
                    'success' => false,
                    'message' => "Cannot copy file: {$item}",
                ];
            }

            @chmod($targetPath, 0644);
        }

        return [
            'success' => true,
            'message' => 'Copied.',
        ];
    }

    protected function shouldAddWwwAlias(string $domain): bool
    {
        $labels = array_values(array_filter(explode('.', $this->normalizeDomain($domain))));
        if (count($labels) < 2) {
            return false;
        }

        [, $subLabels] = $this->splitDomainParts($labels);
        if (count($subLabels) === 0) {
            return true;
        }

        return count($subLabels) === 1 && $subLabels[0] === 'www';
    }

    /**
     * @param  array<string, mixed>  $website
     * @return array<string, int|float|string|null>
     */
    protected function safeBuildDynamicMetrics(array $website): array
    {
        try {
            return $this->buildDynamicMetrics($website);
        } catch (\Throwable $e) {
            return [
                'connections_current' => 0,
                'jobs_pending' => 0,
                'databases_count' => 0,
                'disk_used_mb' => 0,
                'disk_limit_mb' => 102400,
                'cpu_usage_percent' => 0,
                'ram_usage_mb' => 0,
                'file_count' => 0,
                'last_modified_at' => null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $website
     * @return array<string, mixed>
     */
    protected function inspectWebsiteSslStatus(array $website): array
    {
        $checkedAt = now()->toIso8601String();
        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));
        if ($domain === '') {
            return [
                'status' => 'unknown',
                'message' => 'Domain is missing.',
                'checked_at' => $checkedAt,
                'domain' => '',
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'subject_cn' => null,
                'issuer_cn' => null,
            ];
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $domain,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            "ssl://{$domain}:443",
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($client)) {
            return [
                'status' => 'unreachable',
                'message' => trim($errstr) !== '' ? trim($errstr) : "Unable to connect to {$domain}:443.",
                'checked_at' => $checkedAt,
                'domain' => $domain,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'subject_cn' => null,
                'issuer_cn' => null,
            ];
        }

        $params = stream_context_get_params($client);
        @fclose($client);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($certificate === null) {
            return [
                'status' => 'invalid',
                'message' => 'No certificate was presented by the server.',
                'checked_at' => $checkedAt,
                'domain' => $domain,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'subject_cn' => null,
                'issuer_cn' => null,
            ];
        }

        if (! function_exists('openssl_x509_parse')) {
            return [
                'status' => 'unknown',
                'message' => 'Certificate found, but OpenSSL parsing is unavailable in PHP.',
                'checked_at' => $checkedAt,
                'domain' => $domain,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'subject_cn' => null,
                'issuer_cn' => null,
            ];
        }

        $parsed = @openssl_x509_parse($certificate);
        if (! is_array($parsed)) {
            return [
                'status' => 'invalid',
                'message' => 'Certificate was presented but parsing failed.',
                'checked_at' => $checkedAt,
                'domain' => $domain,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'subject_cn' => null,
                'issuer_cn' => null,
            ];
        }

        $validFromTs = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $validToTs = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $subjectCn = isset($parsed['subject']['CN']) ? (string) $parsed['subject']['CN'] : null;
        $issuerCn = isset($parsed['issuer']['CN']) ? (string) $parsed['issuer']['CN'] : null;
        $nowTs = now()->timestamp;
        $isValidNow = $validFromTs !== null && $validToTs !== null && $nowTs >= $validFromTs && $nowTs <= $validToTs;
        $daysRemaining = $validToTs !== null ? (int) floor(($validToTs - $nowTs) / 86400) : null;

        $status = 'valid';
        $message = 'SSL certificate is active and valid.';
        if (! $isValidNow) {
            if ($validToTs !== null && $validToTs < $nowTs) {
                $status = 'expired';
                $message = 'SSL certificate has expired.';
            } else {
                $status = 'invalid';
                $message = 'SSL certificate is present but not currently valid.';
            }
        }

        return [
            'status' => $status,
            'message' => $message,
            'checked_at' => $checkedAt,
            'domain' => $domain,
            'valid_from' => $validFromTs !== null ? Carbon::createFromTimestamp($validFromTs)->toIso8601String() : null,
            'valid_to' => $validToTs !== null ? Carbon::createFromTimestamp($validToTs)->toIso8601String() : null,
            'days_remaining' => $daysRemaining,
            'subject_cn' => $subjectCn,
            'issuer_cn' => $issuerCn,
        ];
    }

    protected function autoRenewWebsiteSslIfNeeded(array $website): ?string
    {
        if (! (bool) config('serverpanel.ssl_auto_renew_enabled', true)) {
            return null;
        }

        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));
        $rootPath = (string) ($website['root_path'] ?? '');
        if ($domain === '' || $rootPath === '') {
            return null;
        }

        $status = $this->inspectWebsiteSslStatus($website);
        $daysRemaining = isset($status['days_remaining']) ? (int) $status['days_remaining'] : null;
        $isExpired = (string) ($status['status'] ?? '') === 'expired';
        $renewThreshold = max(0, (int) config('serverpanel.ssl_auto_renew_days', 30));

        if (! $isExpired && ($daysRemaining === null || $daysRemaining > $renewThreshold)) {
            return null;
        }

        $cooldownHours = max(1, (int) config('serverpanel.ssl_auto_renew_cooldown_hours', 12));
        $cacheKey = 'website-ssl-auto-renew:'.sha1($domain.'|'.$rootPath);
        if (Cache::has($cacheKey)) {
            return null;
        }

        Cache::put($cacheKey, true, now()->addHours($cooldownHours));

        if (! is_dir($rootPath)) {
            return 'SSL auto-renew skipped because the website root path is missing.';
        }

        $result = $this->runIssueSslScript($domain, $rootPath, $this->shouldAddWwwAlias($domain));
        if (! $result['ran']) {
            return 'SSL auto-renew script is not available on this server.';
        }

        if (! $result['success']) {
            $details = trim((string) ($result['output'] ?? ''));

            return $details !== ''
                ? 'SSL auto-renew failed: '.$details
                : 'SSL auto-renew failed.';
        }

        return 'SSL auto-renew completed successfully.';
    }

    /**
     * @param  array<string, mixed>  $website
     * @return array<string, int|float|string|null>
     */
    protected function buildDynamicMetrics(array $website): array
    {
        $basePath = $this->normalizeAbsolutePath((string) ($website['root_path'] ?? ''));
        $filesystem = $this->scanWebsiteFilesystemStats($basePath);
        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));

        $cronJobs = $this->countWebsiteCronJobs((string) ($website['id'] ?? ''));
        $databases = $this->countWebsiteDatabases($domain);
        $diskUsedMb = (int) floor(($filesystem['size_bytes'] / 1024 / 1024) * 100) / 100;

        return [
            'connections_current' => $this->countActiveConnections($domain),
            'jobs_pending' => $cronJobs,
            'databases_count' => $databases,
            'disk_used_mb' => $diskUsedMb,
            'disk_limit_mb' => 102400,
            'cpu_usage_percent' => $this->currentCpuUsagePercent(),
            'ram_usage_mb' => $this->currentRamUsageMb(),
            'file_count' => $filesystem['file_count'],
            'last_modified_at' => $filesystem['last_modified_at'],
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $currentMetrics
     * @return array<string, array<int, array<string, int|float|string>>>
     */
    protected function buildDynamicHistories(string $websiteId, array $currentMetrics): array
    {
        $now = now();
        $history = $this->readWebsiteMetricsHistory($websiteId);

        $history[] = [
            'captured_at' => $now->toIso8601String(),
            'connections' => (int) ($currentMetrics['connections_current'] ?? 0),
            'jobs' => (int) ($currentMetrics['jobs_pending'] ?? 0),
            'databases' => (int) ($currentMetrics['databases_count'] ?? 0),
            'disk' => (float) ($currentMetrics['disk_used_mb'] ?? 0),
            'cpu' => (float) ($currentMetrics['cpu_usage_percent'] ?? 0),
            'ram' => (int) ($currentMetrics['ram_usage_mb'] ?? 0),
        ];

        $cutoff = $now->copy()->subHours(self::WEBSITE_USAGE_RETENTION_HOURS);
        $history = collect($history)
            ->filter(function (array $point) use ($cutoff): bool {
                $capturedAt = trim((string) ($point['captured_at'] ?? ''));
                if ($capturedAt === '') {
                    return false;
                }

                try {
                    return Carbon::parse($capturedAt)->greaterThanOrEqualTo($cutoff);
                } catch (\Throwable $e) {
                    return false;
                }
            })
            ->sortBy(function (array $point): int {
                try {
                    return Carbon::parse((string) ($point['captured_at'] ?? ''))->timestamp;
                } catch (\Throwable $e) {
                    return 0;
                }
            })
            ->values()
            ->all();

        // Safety cap for file size while still retaining enough samples in 12h.
        if (count($history) > self::WEBSITE_USAGE_MAX_POINTS) {
            $history = array_slice($history, -self::WEBSITE_USAGE_MAX_POINTS);
        }

        $this->writeWebsiteMetricsHistory($websiteId, $history);

        $points = collect($history)
            ->map(function (array $point): array {
                $capturedAt = (string) ($point['captured_at'] ?? '');
                $time = now()->format('H:i');
                try {
                    if ($capturedAt !== '') {
                        $time = Carbon::parse($capturedAt)->format('H:i');
                    }
                } catch (\Throwable $e) {
                    // Keep fallback time if parsing fails.
                }

                return [
                    'time' => $time,
                    'connections' => (int) ($point['connections'] ?? 0),
                    'jobs' => (int) ($point['jobs'] ?? 0),
                    'databases' => (int) ($point['databases'] ?? 0),
                    'disk' => (float) ($point['disk'] ?? 0),
                    'cpu' => (float) ($point['cpu'] ?? 0),
                    'ram' => (int) ($point['ram'] ?? 0),
                ];
            })
            ->all();

        return [
            'points' => $points,
        ];
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    protected function readWebsiteMetricsHistory(string $websiteId): array
    {
        $path = $this->websiteMetricsHistoryPath($websiteId);
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $pointsRaw = [];
        if (isset($decoded['points']) && is_array($decoded['points'])) {
            $pointsRaw = $decoded['points'];
        } elseif (array_is_list($decoded)) {
            $pointsRaw = $decoded;
        }

        return collect($pointsRaw)
            ->filter(fn ($point): bool => is_array($point))
            ->map(function (array $point): array {
                return [
                    'captured_at' => (string) ($point['captured_at'] ?? ''),
                    'connections' => (int) ($point['connections'] ?? 0),
                    'jobs' => (int) ($point['jobs'] ?? 0),
                    'databases' => (int) ($point['databases'] ?? 0),
                    'disk' => (float) ($point['disk'] ?? 0),
                    'cpu' => (float) ($point['cpu'] ?? 0),
                    'ram' => (int) ($point['ram'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $history
     */
    protected function writeWebsiteMetricsHistory(string $websiteId, array $history): void
    {
        $path = $this->websiteMetricsHistoryPath($websiteId);
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }

        $payload = [
            'version' => 1,
            'website_id' => $websiteId,
            'updated_at' => now()->toIso8601String(),
            'points' => array_values($history),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return;
        }

        $written = @file_put_contents($path, $json, LOCK_EX);
        if ($written === false) {
            return;
        }

        $this->cleanupWebsiteMetricsHistoryFiles();
    }

    protected function websiteMetricsHistoryPath(string $websiteId): string
    {
        $token = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($websiteId)) ?? '';
        if ($token === '') {
            $token = substr(sha1($websiteId), 0, 20);
        }

        return storage_path(self::WEBSITE_USAGE_HISTORY_DIR.'/'.$token.'.json');
    }

    protected function cleanupWebsiteMetricsHistoryFiles(): void
    {
        $lockAcquired = Cache::add(
            self::WEBSITE_USAGE_CLEANUP_CACHE_KEY,
            now()->toIso8601String(),
            now()->addMinutes(self::WEBSITE_USAGE_CLEANUP_INTERVAL_MINUTES),
        );
        if (! $lockAcquired) {
            return;
        }

        $historyDir = storage_path(self::WEBSITE_USAGE_HISTORY_DIR);
        if (! is_dir($historyDir)) {
            return;
        }

        $entries = @scandir($historyDir);
        if (! is_array($entries)) {
            return;
        }

        $validWebsiteIds = Website::query()
            ->pluck('id')
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->values()
            ->all();
        $validWebsiteIdMap = array_fill_keys($validWebsiteIds, true);
        $staleCutoff = now()->subDays(self::WEBSITE_USAGE_STALE_FILE_DAYS);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! str_ends_with(strtolower($entry), '.json')) {
                continue;
            }

            $fullPath = str_replace('\\', '/', rtrim($historyDir, '/').'/'.$entry);
            if (! is_file($fullPath)) {
                continue;
            }

            if ($this->shouldDeleteWebsiteMetricsHistoryFile($fullPath, $validWebsiteIdMap, $staleCutoff)) {
                @unlink($fullPath);
            }
        }
    }

    protected function resolveTemporaryDirectory(): string
    {
        $candidates = [
            sys_get_temp_dir(),
            storage_path('app/tmp'),
            storage_path('framework/tmp'),
            storage_path('app'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = rtrim(str_replace('\\', '/', trim((string) $candidate)), '/');
            if ($candidate === '') {
                continue;
            }

            if (! is_dir($candidate) && ! @mkdir($candidate, 0775, true) && ! is_dir($candidate)) {
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

    protected function buildTemporaryFilePath(string $directory, string $prefix, string $suffix): string
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $prefix) ?: 'tmp_';

        return $directory.'/'.$prefix.bin2hex(random_bytes(8)).$suffix;
    }

    protected function buildTemporaryDirectoryPath(string $directory, string $prefix): string
    {
        return $this->buildTemporaryFilePath($directory, $prefix, '');
    }

    /**
     * @param  array<string, bool>  $validWebsiteIdMap
     */
    protected function shouldDeleteWebsiteMetricsHistoryFile(string $fullPath, array $validWebsiteIdMap, Carbon $staleCutoff): bool
    {
        $raw = @file_get_contents($fullPath);
        if (! is_string($raw) || trim($raw) === '') {
            return true;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return true;
        }

        $websiteId = trim((string) ($decoded['website_id'] ?? ''));
        if ($websiteId !== '' && ! isset($validWebsiteIdMap[$websiteId])) {
            return true;
        }

        $updatedAt = trim((string) ($decoded['updated_at'] ?? ''));
        if ($updatedAt !== '') {
            try {
                return Carbon::parse($updatedAt)->lt($staleCutoff);
            } catch (\Throwable $e) {
                // Fall back to file mtime if timestamp cannot be parsed.
            }
        }

        $mtime = @filemtime($fullPath);
        if ($mtime === false) {
            return false;
        }

        return Carbon::createFromTimestamp((int) $mtime)->lt($staleCutoff);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function readRequests(): array
    {
        return Website::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Website $website): array => $this->websiteModelToArray($website))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     */
    protected function writeRequests(array $requests): void
    {
        $this->persistRequestsToDatabase($requests);
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     */
    protected function persistRequestsToDatabase(array $requests): void
    {
        $websiteColumns = array_flip(DB::getSchemaBuilder()->getColumnListing('websites'));

        $rows = collect($requests)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row) use ($websiteColumns): array {
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    $id = (string) str()->uuid();
                }

                $domain = $this->normalizeDomain((string) ($row['domain'] ?? ''));
                $startDirectory = $this->normalizeStartDirectoryAlias((string) ($row['start_directory']));
                $rootPath = $domain !== ''
                    ? $this->normalizeRootPath((string) ($row['root_path'] ?? ''), $domain)
                    : '';
                $projectRoot = $domain !== ''
                    ? $this->deriveProjectRootPath($rootPath, $domain)
                    : $this->normalizeAbsolutePath((string) ($row['project_root'] ?? ''));
                $siteOwner = $rootPath !== ''
                    ? $this->extractSiteOwnerFromRootPath($projectRoot !== '' ? $projectRoot : $rootPath)
                    : (isset($row['site_owner']) ? (string) $row['site_owner'] : null);
                $createdAt = $this->normalizeDatabaseDatetime((string) ($row['created_at'] ?? ''));
                $updatedAt = $this->normalizeDatabaseDatetime((string) ($row['updated_at'] ?? ''), $createdAt);

                return array_intersect_key([
                    'id' => $id,
                    'domain' => $domain,
                    'start_directory' => $startDirectory,
                    'root_path' => $rootPath,
                    'project_root' => $projectRoot,
                    'site_owner' => $siteOwner,
                    'php_version' => (string) ($row['php_version'] ?? ''),
                    'wordpress_db_prefix' => $this->normalizeWordPressDatabasePrefix((string) ($row['wordpress_db_prefix'] ?? ''), $domain),
                    'enable_ssl' => (bool) ($row['enable_ssl'] ?? false),
                    'filemanager_show_hidden' => (bool) ($row['filemanager_show_hidden'] ?? false),
                    'assigned_user_id' => isset($row['assigned_user_id']) && $row['assigned_user_id'] !== '' ? (int) $row['assigned_user_id'] : null,
                    'assigned_reseller_id' => isset($row['assigned_reseller_id']) && $row['assigned_reseller_id'] !== '' ? (int) $row['assigned_reseller_id'] : null,
                    'status' => (string) ($row['status'] ?? 'pending'),
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ], $websiteColumns);
            })
            ->filter(fn (array $row): bool => trim($row['domain']) !== '')
            ->reverse()
            ->unique(fn (array $row): string => strtolower(trim((string) $row['domain'])))
            ->reverse()
            ->values();

        DB::transaction(function () use ($rows, $websiteColumns): void {
            if ($rows->isEmpty()) {
                Website::query()->delete();

                return;
            }

            Website::query()->upsert(
                $rows->all(),
                ['id'],
                array_values(array_filter([
                    'domain',
                    'start_directory',
                    'root_path',
                    'site_owner',
                    'php_version',
                    'wordpress_db_prefix',
                    'enable_ssl',
                    'filemanager_show_hidden',
                    'assigned_user_id',
                    'assigned_reseller_id',
                    'status',
                    'created_at',
                    'updated_at',
                ], fn (string $column): bool => isset($websiteColumns[$column]))),
            );

            $ids = $rows->pluck('id')->all();
            Website::query()->whereNotIn('id', $ids)->delete();
        });
    }

    /**
     * @return array<string,mixed>
     */
    protected function websiteModelToArray(Website $website): array
    {
        $domain = $this->normalizeDomain((string) ($website->domain ?? ''));
        $rootPath = $domain !== ''
            ? $this->normalizeRootPath((string) ($website->root_path ?? ''), $domain)
            : '';
        $projectRoot = $domain !== ''
            ? $this->deriveProjectRootPath($rootPath, $domain)
            : $this->normalizeAbsolutePath((string) ($website->project_root ?? ''));
        $siteOwner = $rootPath !== ''
            ? $this->extractSiteOwnerFromRootPath($projectRoot !== '' ? $projectRoot : $rootPath)
            : ($website->site_owner !== null ? (string) $website->site_owner : null);
        $storedStatus = (string) ($website->status ?? 'pending');
        $isAlias = in_array(strtolower((string) ($website->type ?? '')), ['alis', 'alias'], true);
        $status = $isAlias
            ? 'live'
            : $this->detectRuntimeStatus([
                'domain' => $domain,
                'root_path' => $rootPath,
                'status' => $storedStatus,
            ]);
        if ($website->exists && $status !== $storedStatus) {
            try {
                $website->forceFill(['status' => $status])->saveQuietly();
            } catch (\Throwable $e) {
                // Keep response non-blocking if status sync fails.
            }
        }

        return [
            'id' => (string) $website->id,
            'domain' => $domain,
            'type' => (string) ($website->type ?? 'primary'),
            'parent_id' => $website->parent_id !== null ? (string) $website->parent_id : null,
            'start_directory' => $this->normalizeStartDirectoryAlias((string) ($website->start_directory ?? 'public')),
            'root_path' => $rootPath,
            'project_root' => $projectRoot,
            'site_owner' => $siteOwner,
            'php_version' => (string) ($website->php_version ?? ''),
            'wordpress_db_prefix' => $this->normalizeWordPressDatabasePrefix((string) ($website->wordpress_db_prefix ?? ''), $domain),
            'enable_ssl' => (bool) ($website->enable_ssl ?? false),
            'ssl_status' => $this->resolveWebsiteSslStatus($domain),
            'filemanager_show_hidden' => (bool) ($website->filemanager_show_hidden ?? false),
            'assigned_user_id' => $website->assigned_user_id,
            'assigned_reseller_id' => $website->assigned_reseller_id,
            'status' => $status,
            'created_at' => $website->created_at?->toIso8601String(),
            'updated_at' => $website->updated_at?->toIso8601String(),
        ];
    }

    protected function resolveWebsiteSslStatus(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return 'unknown';
        }

        $certificateStatus = (string) (SslCertificate::query()->where('domain', $domain)->value('status') ?? '');
        if ($certificateStatus !== '') {
            return $certificateStatus;
        }

        $domainStatus = (string) (Domain::query()->where('name', $domain)->value('ssl_status') ?? '');
        if ($domainStatus !== '') {
            return $domainStatus;
        }

        return 'unknown';
    }

    protected function normalizeDatabaseDatetime(string $value, ?string $fallback = null): string
    {
        $value = trim($value);
        if ($value !== '') {
            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                // fall through to fallback
            }
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            return $fallback;
        }

        return now()->format('Y-m-d H:i:s');
    }

    /**
     * @return array{size_bytes:int,file_count:int,last_modified_at:string|null}
     */
    protected function scanWebsiteFilesystemStats(string $basePath): array
    {
        $sizeBytes = $this->privilegedDirectorySize($basePath);
        $scanSizeBytes = 0;
        $fileCount = 0;
        $latestMtime = null;

        if (! is_dir($basePath)) {
            return [
                'size_bytes' => 0,
                'file_count' => 0,
                'last_modified_at' => null,
            ];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                $mtime = $item->getMTime();
                $latestMtime = $latestMtime === null ? $mtime : max($latestMtime, $mtime);

                if ($item->isFile()) {
                    $fileCount++;
                    $scanSizeBytes += $item->getSize();
                }
            }
        } catch (\Throwable $e) {
            // Keep metrics available even if scanning fails.
        }

        return [
            'size_bytes' => $sizeBytes ?? $scanSizeBytes,
            'file_count' => $fileCount,
            'last_modified_at' => $latestMtime ? date('c', (int) $latestMtime) : null,
        ];
    }

    protected function privilegedDirectorySize(string $path): ?int
    {
        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $command = ['du', '-sb', '--', $path];
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $command = array_merge(['sudo', '-n'], $command);
        }

        try {
            $result = Process::timeout(30)->run($command);
            if ($result->successful() && preg_match('/^(\d+)/', trim($result->output()), $matches) === 1) {
                return (int) $matches[1];
            }
        } catch (\Throwable $e) {
            // Fall back to the unprivileged recursive scan below.
        }

        return null;
    }

    protected function detectRuntimeStatus(array $website): string
    {
        $storedStatus = strtolower(trim((string) ($website['status'] ?? 'pending')));
        if ($storedStatus === 'disabled') {
            return 'disabled';
        }

        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));

        return $domain !== '' ? 'live' : 'pending';
    }

    protected function rustGatewayHostMatches(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        try {
            $gatewayUrl = rtrim((string) config('serverpanel.edge_gateway_url', 'http://127.0.0.1'), '/');
            $response = Http::withHeaders(['Host' => $domain])
                ->withoutRedirecting()
                ->timeout(2)
                ->get($gatewayUrl.'/');

            return $response->successful()
                && strcasecmp(trim((string) $response->header('x-site-match')), $domain) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function countWebsiteCronJobs(string $websiteId): int
    {
        return (int) CronJob::query()
            ->where('website_id', $websiteId)
            ->where('status', 'active')
            ->count();
    }

    protected function countWebsiteDatabases(string $domain): int
    {
        $normalized = strtolower(trim($domain));

        return (int) DatabaseRequest::query()
            ->whereRaw('LOWER(domain) = ?', [$normalized])
            ->count();
    }

    protected function countActiveConnections(string $domain): int
    {
        if ($domain === '') {
            return 0;
        }

        $escapedDomain = escapeshellarg($domain);
        $output = @shell_exec("ss -Htanp 2>/dev/null | grep -c {$escapedDomain}");
        if ($output === null) {
            return 0;
        }

        $count = (int) trim($output);

        return max(0, $count);
    }

    protected function currentCpuUsagePercent(): float
    {
        if (! function_exists('sys_getloadavg')) {
            return 0.0;
        }

        $load = sys_getloadavg();
        if (! is_array($load) || ! isset($load[0])) {
            return 0.0;
        }

        $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        $cores = $cores > 0 ? $cores : 1;

        return round(min(100, max(0, ((float) $load[0] / $cores) * 100)), 2);
    }

    protected function currentRamUsageMb(): int
    {
        $memInfo = @file_get_contents('/proc/meminfo');
        if (! is_string($memInfo) || $memInfo === '') {
            return 0;
        }

        preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', $memInfo, $total);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $memInfo, $available);
        if (! isset($total[1], $available[1])) {
            return 0;
        }

        $usedKb = max(0, (int) $total[1] - (int) $available[1]);

        return (int) floor($usedKb / 1024);
    }

    /**
     * @return array<int, string>
     */
    protected function getPhpVersionsForWebsites(): array
    {
        return $this->templateCatalog->availablePhpVersions();

        try {
            $templateVersions = $this->templateCatalog->availablePhpVersions();
            $installedVersions = [];
            if (DB::getSchemaBuilder()->hasTable(self::PHP_SETTINGS_TABLE)) {
                $row = DB::table(self::PHP_SETTINGS_TABLE)
                    ->where('setting_key', self::PHP_STATE_KEY)
                    ->first();

                if ($row === null || ! isset($row->setting_value) || ! is_string($row->setting_value) || trim($row->setting_value) === '') {
                    DB::table(self::PHP_SETTINGS_TABLE)->updateOrInsert(
                        ['setting_key' => self::PHP_STATE_KEY],
                        [
                            'setting_value' => json_encode(['versions' => $this->templateCatalog->availablePhpVersions() ?: self::FALLBACK_PHP_VERSIONS], JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );

                    $row = DB::table(self::PHP_SETTINGS_TABLE)
                        ->where('setting_key', self::PHP_STATE_KEY)
                        ->first();
                }

                $decoded = json_decode((string) ($row->setting_value ?? ''), true);
                $installedVersions = collect((array) ($decoded['versions'] ?? []))
                    ->map(fn ($version): string => trim((string) $version))
                    ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
                    ->unique()
                    ->sort(fn ($a, $b) => version_compare($b, $a))
                    ->values()
                    ->all();
            }

            $usedVersions = Website::query()
                ->select('php_version')
                ->whereNotNull('php_version')
                ->get()
                ->map(fn ($item): string => trim((string) ($item->php_version ?? '')))
                ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
                ->unique()
                ->values()
                ->all();

            $merged = collect([...$templateVersions, ...$installedVersions, ...$usedVersions])
                ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
                ->unique()
                ->sort(fn ($a, $b) => version_compare($b, $a))
                ->values()
                ->all();

            $versions = count($merged) > 0 ? $merged : ($this->templateCatalog->availablePhpVersions() ?: self::FALLBACK_PHP_VERSIONS);

            return $versions;
        } catch (\Throwable $e) {
            return $this->templateCatalog->availablePhpVersions() ?: self::FALLBACK_PHP_VERSIONS;
        }
    }

    protected function normalizeWebsitePhpVersion(string $phpVersion): string
    {
        $versions = collect($this->getPhpVersionsForWebsites())
            ->map(fn (string $version): string => trim($version))
            ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
            ->values()
            ->all();

        $fallback = (string) ($versions[0] ?? $this->templateCatalog->defaultPhpVersion('starter') ?: self::FALLBACK_PHP_VERSIONS[0]);
        $normalized = strtolower(trim($phpVersion));

        if ($normalized === '' || $normalized === 'latest') {
            return $fallback;
        }

        if (preg_match('/^\d+\.\d+$/', $normalized) === 1) {
            return $normalized;
        }

        return $fallback;
    }

    protected function getDefaultWebsitePhpVersion(string $installer = 'none'): string
    {
        $installer = strtolower(trim($installer));

        return match ($installer) {
            'wordpress' => $this->templateCatalog->defaultPhpVersion('wordpress'),
            default => $this->templateCatalog->defaultPhpVersion('starter'),
        };
    }

    protected function resolveFileManagerBasePath(array $website, string $scopeRoot = ''): string
    {
        $configured = (string) ($website['project_root'] ?? '');
        $domain = (string) ($website['domain'] ?? 'site');
        $resolvedRoot = $configured !== ''
            ? $this->normalizeAbsolutePath($configured)
            : $this->deriveProjectRootPath((string) ($website['root_path'] ?? ''), $domain);
        $resolvedRoot = str_replace('\\', '/', trim($resolvedRoot));

        if ($resolvedRoot === '') {
            abort(422, 'Website root path is missing. Please set a valid root path first.');
        }

        if (! is_dir($resolvedRoot)) {
            @mkdir($resolvedRoot, 0755, true);
        }

        return str_replace('\\', '/', rtrim($resolvedRoot, '/'));
    }

    protected function relativePathFromBase(string $basePath, string $absolutePath): string
    {
        $base = rtrim(str_replace('\\', '/', trim($basePath)), '/');
        $target = rtrim(str_replace('\\', '/', trim($absolutePath)), '/');

        if ($base === '' || $target === '') {
            return '';
        }

        if ($target === $base) {
            return '';
        }

        if (! str_starts_with($target, $base.'/')) {
            return '';
        }

        return $this->sanitizeRelativePath(substr($target, strlen($base) + 1));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fileManagerRouteParams(string $id, string $currentPath = '', string $scopeRoot = '', array $extra = []): array
    {
        $params = ['id' => $id];
        $currentPath = $this->sanitizeRelativePath($currentPath);
        $scopeRoot = $this->sanitizeRelativePath($scopeRoot);

        if ($currentPath !== '') {
            $params['path'] = $currentPath;
        }

        if ($scopeRoot !== '') {
            $params['root'] = $scopeRoot;
        }

        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $params[$key] = $value;
        }

        return $params;
    }

    protected function sanitizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        $parts = [];
        foreach (explode('/', $path) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    protected function iniSizeToKilobytes(string $value, int $fallback): int
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^(\d+)([KMGT])$/', $value, $matches) !== 1) {
            return $fallback;
        }

        $number = (int) $matches[1];
        $multiplier = match ($matches[2]) {
            'K' => 1,
            'M' => 1024,
            'G' => 1024 * 1024,
            'T' => 1024 * 1024 * 1024,
            default => 1,
        };

        return max(1, $number * $multiplier);
    }

    protected function resolvePathInsideBase(string $basePath, string $relative): string
    {
        $relative = $this->sanitizeRelativePath($relative);
        $full = rtrim($basePath, '/').($relative !== '' ? '/'.$relative : '');

        return str_replace('\\', '/', $full);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function listDirectoryItems(string $basePath, string $directory, bool $showHidden = false): array
    {
        $entries = @scandir($directory);
        if (! is_array($entries)) {
            return [];
        }

        $items = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! $showHidden && str_starts_with($entry, '.')) {
                continue;
            }

            $fullPath = rtrim($directory, '/').'/'.$entry;
            $isDir = is_dir($fullPath);
            $relative = $this->sanitizeRelativePath(str_replace(rtrim($basePath, '/').'/', '', str_replace('\\', '/', $fullPath)));

            $items[] = [
                'name' => $entry,
                'path' => $relative,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? null : (@filesize($fullPath) ?: 0),
                'modified_at' => @filemtime($fullPath) ? date('c', (int) @filemtime($fullPath)) : null,
                'permissions' => $this->formatPermissions($fullPath),
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildDirectoryTree(string $basePath, string $relative, int $depth, bool $showHidden, string $activePath = ''): array
    {
        if ($depth < 0) {
            return [];
        }

        $activePath = $this->sanitizeRelativePath($activePath);
        $path = $this->resolvePathInsideBase($basePath, $relative);
        $entries = @scandir($path);
        if (! is_array($entries)) {
            return [];
        }

        $tree = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! $showHidden && str_starts_with($entry, '.')) {
                continue;
            }

            $childRelative = $this->sanitizeRelativePath(trim($relative.'/'.$entry, '/'));
            $childPath = $this->resolvePathInsideBase($basePath, $childRelative);
            if (! is_dir($childPath)) {
                continue;
            }

            $isActiveBranch = $activePath !== '' && (
                $childRelative === $activePath
                || str_starts_with($activePath.'/', $childRelative.'/')
            );

            $children = [];
            if ($depth > 0 && $isActiveBranch) {
                $children = $this->buildDirectoryTree($basePath, $childRelative, $depth - 1, $showHidden, $activePath);
            }

            $tree[] = [
                'name' => $entry,
                'path' => $childRelative,
                'has_children' => count($children) > 0,
                'children' => $children,
            ];
        }

        usort($tree, fn (array $a, array $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return $tree;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readSelectedFile(string $basePath, string $fileRelative): ?array
    {
        if ($fileRelative === '') {
            return null;
        }

        $filePath = $this->resolvePathInsideBase($basePath, $fileRelative);
        if (! is_file($filePath)) {
            return null;
        }

        $size = @filesize($filePath) ?: 0;
        if ($size > 1024 * 1024) {
            return [
                'path' => $fileRelative,
                'name' => basename($filePath),
                'content' => '',
                'readonly' => true,
                'message' => 'File is larger than 1MB and not loaded in editor.',
            ];
        }

        $content = @file_get_contents($filePath);
        if (! is_string($content)) {
            $content = '';
        }

        return [
            'path' => $fileRelative,
            'name' => basename($filePath),
            'content' => $content,
            'readonly' => false,
            'message' => null,
        ];
    }

    protected function deleteDirectoryRecursive(string $directory): void
    {
        $entries = @scandir($directory);
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = rtrim($directory, '/').'/'.$entry;
            if (is_dir($full)) {
                $this->deleteDirectoryRecursive($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($directory);
    }

    protected function applyPermissionsRecursively(string $path, int $mode): bool
    {
        if (! @chmod($path, $mode)) {
            return false;
        }

        if (! is_dir($path)) {
            return true;
        }

        $entries = @scandir($path);
        if (! is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = rtrim($path, '/').'/'.$entry;

            // Do not recurse into links to avoid crossing outside website root.
            if (is_link($fullPath)) {
                continue;
            }

            if (is_dir($fullPath)) {
                if (! $this->applyPermissionsRecursively($fullPath, $mode)) {
                    return false;
                }

                continue;
            }

            if (! @chmod($fullPath, $mode)) {
                return false;
            }
        }

        return true;
    }

    protected function sanitizeFilename(string $filename): string
    {
        $filename = trim(str_replace(['\\', '/'], '-', $filename));
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?? '';
        $filename = trim($filename, '-');
        if ($filename === '.' || $filename === '..' || trim($filename, '.') === '') {
            return '';
        }

        return $filename;
    }

    protected function formatPermissions(string $path): string
    {
        $perms = @fileperms($path);
        if ($perms === false) {
            return '-';
        }

        return substr(sprintf('%o', $perms), -4);
    }

    protected function zipExtensionMissingMessage(): string
    {
        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        return 'PHP zip extension is not installed on server. Run: sudo apt update && sudo apt install -y php'.$version.'-zip php-zip && sudo systemctl restart php'.$version.'-fpm serverpanel';
    }

    protected function addDirectoryToZip(ZipArchive $zip, string $directory, string $prefix): void
    {
        $entries = @scandir($directory);
        if (! is_array($entries)) {
            return;
        }

        $zip->addEmptyDir($prefix);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = rtrim($directory, '/').'/'.$entry;
            $zipPath = trim($prefix.'/'.$entry, '/');
            if (is_dir($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $zipPath);
            } else {
                $zip->addFile($fullPath, $zipPath);
            }
        }
    }
}
