<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WebsiteController extends Controller
{
    private const HOME_BASE = '/home';
    private const WEBSITE_METRICS_TABLE = 'website_metrics';
    private const DEFAULT_SITE_DIR = 'public_html';
    private const PHP_SETTINGS_TABLE = 'php_management_settings';
    private const PHP_STATE_KEY = 'state';
    private const DEFAULT_APACHE_BACKEND_PORT = 8080;
    private const DEFAULT_NGINX_PRIMARY_PORT = 80;
    private const WEBSITE_USAGE_HISTORY_DIR = 'app/website-usage-history';
    private const WEBSITE_USAGE_RETENTION_HOURS = 12;
    private const WEBSITE_USAGE_MAX_POINTS = 720;
    private const WEBSITE_USAGE_STALE_FILE_DAYS = 3;
    private const WEBSITE_USAGE_CLEANUP_CACHE_KEY = 'websites:usage-history:last-cleanup';
    private const WEBSITE_USAGE_CLEANUP_INTERVAL_MINUTES = 30;
    /**
     * @var array<int, string>
     */
    private const FALLBACK_PHP_VERSIONS = ['8.0', '7.4'];
    /**
     * Common compound public suffixes for registrable-domain detection.
     *
     * @var array<int, string>
     */
    private const COMPOUND_PUBLIC_SUFFIXES = [
        'com.bd',
        'net.bd',
        'org.bd',
        'edu.bd',
        'gov.bd',
        'ac.bd',
        'com.au',
        'net.au',
        'org.au',
        'co.uk',
        'org.uk',
        'gov.uk',
        'ac.uk',
        'co.jp',
        'com.sg',
        'com.my',
        'co.nz',
    ];

    /**
     * Show website creation page.
     */
    public function create(): Response
    {
        return Inertia::render('Websites/Create', [
            'serverBaseDir' => $this->websiteBaseDirectory(),
            'phpVersions' => $this->getPhpVersionsForWebsites(),
            'wordpressVersions' => $this->getWordPressVersionOptions(),
        ]);
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

                return [
                    'domain' => $domain,
                    'root_path' => $rootPath,
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
     * Create a website command request.
     * Command execution is intentionally commented out.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $validated['app_installer'] = strtolower(trim((string) ($validated['app_installer'] ?? 'none')));
        $validated['wordpress_version'] = $this->normalizeWordPressVersion((string) ($validated['wordpress_version'] ?? 'latest'));
        $validated['php_version'] = $this->normalizeWebsitePhpVersion((string) ($validated['php_version'] ?? ''));
        $validated['domain'] = $this->normalizeDomain($validated['domain']);
        $domainExists = collect($this->readRequests())
            ->contains(fn (array $item): bool => $this->normalizeDomain((string) ($item['domain'] ?? '')) === $validated['domain']);
        if ($domainExists) {
            return back()->withErrors(['domain' => 'This domain already exists.']);
        }
        $validated['root_path'] = $this->normalizeRootPath((string) ($validated['root_path'] ?? ''), $validated['domain']);
        $validated['site_owner'] = $this->extractSiteOwnerFromRootPath($validated['root_path']);

        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);
        $sslNotice = null;

        $command = $this->buildCommand($validated);

        // Intentionally disabled: command execution must be manually enabled later.
        try {
            $output = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);
            $this->applyWebsiteFilesystemIsolation($validated['site_owner'], $validated['root_path']);
            $appInstallerResult = $this->installSelectedApplication(
                $validated['app_installer'],
                $validated['root_path'],
                $validated['domain'],
                (string) $validated['php_version'],
                (string) $validated['wordpress_version'],
            );
            if ($appInstallerResult['attempted'] && ! $appInstallerResult['installed']) {
                return back()->withErrors(['app_installer' => $appInstallerResult['message']]);
            }
            if (! $appInstallerResult['installed']) {
                $this->initializeWebsiteStarterFiles(
                    $validated['root_path'],
                    $validated['domain'],
                    (string) $validated['php_version'],
                );
            }
            $this->relocateApacheDefaultPage();
            $this->syncLiveWebVhost(
                $validated['domain'],
                $validated['root_path'],
                (string) $validated['php_version'],
            );

            if ($validated['enable_ssl']) {
                $sslResult = $this->runIssueSslScript(
                    $validated['domain'],
                    $validated['root_path'],
                    $this->shouldAddWwwAlias($validated['domain']),
                );

                if (! $sslResult['ran']) {
                    $sslNotice = 'SSL auto-generate is not available on this server.';
                } elseif (! $sslResult['success']) {
                    $sslNotice = trim($sslResult['output']) !== ''
                        ? 'SSL auto-generate failed: '.trim($sslResult['output'])
                        : 'SSL auto-generate failed.';
                } else {
                    $this->syncLiveWebVhost(
                        $validated['domain'],
                        $validated['root_path'],
                        (string) $validated['php_version'],
                    );
                }
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $requests = $this->readRequests();
        $actor = $request->user();
        $defaultResellerId = $actor && $actor->hasRole('reseller') ? (int) $actor->id : null;
        $runtimeStatus = $this->detectRuntimeStatus([
            'domain' => $validated['domain'],
            'root_path' => $validated['root_path'],
            'status' => 'pending',
        ]);
        $requests[] = [
            'id' => (string) str()->uuid(),
            'domain' => $validated['domain'],
            'root_path' => $validated['root_path'],
            'site_owner' => $validated['site_owner'],
            'php_version' => $validated['php_version'],
            'app_installer' => $validated['app_installer'],
            'wordpress_version' => $validated['wordpress_version'],
            'enable_ssl' => $validated['enable_ssl'],
            'assigned_user_id' => null,
            'assigned_reseller_id' => $defaultResellerId,
            'command' => $command,
            'status' => $runtimeStatus,
            'created_at' => now()->toIso8601String(),
        ];
        $this->writeRequests($requests);

        $installerLabel = $validated['app_installer'] === 'wordpress' ? 'WordPress' : 'Starter';
        $message = "Website request created successfully. Installer: {$installerLabel}.";
        if ($sslNotice !== null) {
            $message .= ' '.$sslNotice;
        } elseif ($validated['enable_ssl']) {
            $message .= ' SSL was auto-generated.';
        }

        return redirect()->route('websites.list')->with('success', $message);
    }

    /**
     * Edit website request.
     */
    public function edit(string $id): Response
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
    public function manage(string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $metrics = $this->safeBuildDynamicMetrics($website);

        $runtimeStatus = $this->detectRuntimeStatus($website);
        $websiteDomain = (string) ($website['domain'] ?? '');
        $hasApacheVhost = $this->apacheVhostExists($websiteDomain);
        $hasNginxVhost = $this->nginxVhostExists($websiteDomain);

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
                'label' => 'Installer',
                'value' => strtolower((string) ($website['app_installer'] ?? 'none')) === 'wordpress' ? 'wordpress' : 'starter',
            ],
            [
                'label' => 'Root Path',
                'value' => (string) ($website['root_path'] ?? '-'),
            ],
            [
                'label' => 'Apache VHost',
                'value' => $hasApacheVhost ? 'configured' : ($hasNginxVhost ? 'not configured (nginx configured)' : 'not configured'),
            ],
            [
                'label' => 'Nginx VHost',
                'value' => $hasNginxVhost ? 'configured' : ($hasApacheVhost ? 'not configured (apache configured)' : 'not configured'),
            ],
        ];

        return Inertia::render('Websites/Manage', [
            'website' => $website,
            'metrics' => $metrics,
            'activities' => $activities,
        ]);
    }

    public function webServerManager(string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $domain = (string) ($website['domain'] ?? '');

        return Inertia::render('Websites/WebServerManager', [
            'website' => $website,
            'vhostPreview' => $this->buildVhostPreview($website),
            'apacheServiceStatus' => $this->detectServiceStatusForWebsitePage('apache2', 'wampapache64'),
            'nginxServiceStatus' => $this->detectServiceStatusForWebsitePage('nginx', 'wampnginx64'),
            'hasApacheVhost' => $this->apacheVhostExists($domain),
            'hasNginxVhost' => $this->nginxVhostExists($domain),
        ]);
    }

    public function sslManager(string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $autoRenewNotice = $this->autoRenewWebsiteSslIfNeeded($website);

        return Inertia::render('Websites/SslManager', [
            'website' => $website,
            'sslStatus' => $this->inspectWebsiteSslStatus($website),
            'autoRenewNotice' => $autoRenewNotice,
        ]);
    }

    public function issueSsl(string $id): RedirectResponse
    {
        $requests = collect($this->readRequests());
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));
        $rootPath = (string) ($website['root_path'] ?? '');
        $phpVersion = (string) ($website['php_version'] ?? '8.0');
        if ($domain === '' || $rootPath === '') {
            return redirect()->route('websites.ssl', $id)->with('error', 'Domain or root path is missing for SSL issue.');
        }
        if (! is_dir($rootPath)) {
            return redirect()->route('websites.ssl', $id)->with('error', "Root path does not exist: {$rootPath}");
        }
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return redirect()->route('websites.ssl', $id)->with('error', 'SSL issue is only supported on Linux servers.');
        }
        $issueResult = $this->runIssueSslScript($domain, $rootPath, $this->shouldAddWwwAlias($domain));
        if (! $issueResult['ran']) {
            return redirect()->route('websites.ssl', $id)->with('error', 'SSL issue script is not available on this server.');
        }
        if (! $issueResult['success']) {
            $summary = trim((string) preg_replace('/\s+/', ' ', (string) ($issueResult['output'] ?? '')));
            if ($summary !== '') {
                $summary = substr($summary, 0, 280);
            }

            $message = 'SSL issue failed.';
            if ((int) ($issueResult['exit_code'] ?? 1) === 77) {
                $message = 'SSL issue requires root privileges. Run ServerPanel as root or allow passwordless sudo for this action.';
            }
            if ($summary !== '') {
                $message .= ' '.$summary;
            }

            return redirect()->route('websites.ssl', $id)->with('error', $message);
        }

        $syncReport = $this->syncLiveWebVhostWithReport($domain, $rootPath, $phpVersion);
        $runtimeStatus = $this->detectRuntimeStatus([
            'domain' => $domain,
            'root_path' => $rootPath,
            'status' => (string) ($website['status'] ?? 'pending'),
        ]);

        $updated = $requests->map(function (array $item) use ($id, $runtimeStatus): array {
            if ((string) ($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['enable_ssl'] = true;
            $item['status'] = $runtimeStatus;
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($updated);

        $syncMessage = implode(' | ', $syncReport['messages']);
        if (! $syncReport['generated']) {
            $message = 'SSL certificate issued, but vhost sync failed.';
            if ($syncMessage !== '') {
                $message .= ' '.$syncMessage;
            }

            return redirect()->route('websites.ssl', $id)->with('error', $message);
        }

        $message = 'SSL certificate issued successfully.';
        if ($syncMessage !== '') {
            $message .= ' '.$syncMessage;
        }

        return redirect()->route('websites.ssl', $id)->with('success', $message);
    }

    public function Usage(string $id): Response
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

    public function syncVhost(Request $request, string $id): RedirectResponse
    {
        $redirectTarget = function () use ($request, $id): RedirectResponse {
            if ((string) $request->input('return_to', '') === 'apache') {
                return redirect()->route('apache.index', ['website_id' => $id]);
            }
            if ((string) $request->input('return_to', '') === 'website_service') {
                return redirect()->route('websites.web-server', $id);
            }

            return redirect()->route('websites.manage', $id);
        };

        $requests = collect($this->readRequests());
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $domain = (string) ($website['domain'] ?? '');
        $rootPath = (string) ($website['root_path'] ?? '');
        $phpVersion = (string) ($website['php_version'] ?? '8.0');
        if ($domain === '' || $rootPath === '') {
            return $redirectTarget()->with('error', 'Domain or root path is missing for vhost sync.');
        }
        if (! is_dir($rootPath)) {
            return $redirectTarget()->with('error', "Root path does not exist: {$rootPath}");
        }

        $syncReport = $this->syncLiveWebVhostWithReport($domain, $rootPath, $phpVersion);

        $runtimeStatus = $this->detectRuntimeStatus([
            'domain' => $domain,
            'root_path' => $rootPath,
            'status' => (string) ($website['status'] ?? 'pending'),
        ]);

        $updated = $requests->map(function (array $item) use ($id, $runtimeStatus): array {
            if ((string) ($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['status'] = $runtimeStatus;
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($updated);

        $message = implode(' | ', $syncReport['messages']);
        if (! $syncReport['generated']) {
            return $redirectTarget()->with('error', $message !== '' ? $message : 'Vhost sync failed.');
        }

        return $redirectTarget()->with('success', $message !== '' ? $message : 'Vhost sync completed.');
    }

    public function clearProjectCache(string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $rootPath = (string) ($website['root_path'] ?? '');
        if ($rootPath === '' || ! is_dir($rootPath)) {
            return redirect()->route('websites.manage', $id)->with('error', 'Website root path is missing or inaccessible.');
        }

        $artisanPath = $this->resolveProjectArtisanPath($rootPath);
        if ($artisanPath === null) {
            return redirect()->route('websites.manage', $id)->with('error', 'Laravel artisan file not found for this website.');
        }

        $projectPath = dirname($artisanPath);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($rootPath));
        $primary = $this->runProjectArtisanCommand($projectPath, 'optimize:clear', $siteOwner);

        if ($primary['success']) {
            return redirect()->route('websites.manage', $id)->with('success', 'Project cache cleared successfully (optimize:clear).');
        }

        $fallbackCommands = ['cache:clear', 'config:clear', 'route:clear', 'view:clear'];
        $fallbackResults = collect($fallbackCommands)
            ->map(fn (string $command): array => $this->runProjectArtisanCommand($projectPath, $command, $siteOwner))
            ->all();
        $successCount = collect($fallbackResults)->filter(fn (array $result): bool => (bool) ($result['success'] ?? false))->count();

        if ($successCount === count($fallbackCommands)) {
            return redirect()->route('websites.manage', $id)->with('success', 'Project cache cleared successfully (fallback commands).');
        }

        if ($successCount > 0) {
            return redirect()->route('websites.manage', $id)->with('error', 'Project cache clear partially completed. Check file permissions and Laravel CLI access.');
        }

        $errorDetails = trim((string) ($primary['output'] ?? ''));
        if ($errorDetails !== '') {
            $errorDetails = substr(preg_replace('/\s+/', ' ', $errorDetails) ?? '', 0, 180);
        }
        $suffix = $errorDetails !== '' ? " Error: {$errorDetails}" : '';

        return redirect()->route('websites.manage', $id)->with('error', 'Project cache clear failed.'.$suffix);
    }

    public function wordpressManager(string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        return Inertia::render('Websites/WordPressInstaller', [
            'website' => $website,
            'wordpressVersions' => $this->getWordPressVersionOptions(),
        ]);
    }

    public function installWordPress(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'wordpress_version' => ['nullable', 'string', 'max:20', 'regex:/^(latest|\\d+\\.\\d+(?:\\.\\d+)?)$/i'],
            'return_to' => ['nullable', 'string', 'in:manage,wordpress'],
        ]);
        $returnToWordPress = (string) ($validated['return_to'] ?? '') === 'wordpress';
        $redirectTarget = function () use ($id, $returnToWordPress): RedirectResponse {
            if ($returnToWordPress) {
                return redirect()->route('websites.wordpress.manager', $id);
            }

            return redirect()->route('websites.manage', $id);
        };

        $requests = collect($this->readRequests());
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $domain = (string) ($website['domain'] ?? '');
        $rootPath = (string) ($website['root_path'] ?? '');
        $phpVersion = (string) ($website['php_version'] ?? '8.0');
        $wordpressVersion = $this->normalizeWordPressVersion((string) ($validated['wordpress_version'] ?? ($website['wordpress_version'] ?? 'latest')));
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($rootPath));

        if ($domain === '' || $rootPath === '') {
            return $redirectTarget()->with('error', 'Domain or root path is missing for WordPress installation.');
        }

        if ($this->hasWordPressFiles($rootPath)) {
            $updatedRequests = $requests->map(function (array $item) use ($id, $wordpressVersion): array {
                if ((string) ($item['id'] ?? '') !== $id) {
                    return $item;
                }

                $item['app_installer'] = 'wordpress';
                $item['wordpress_version'] = $wordpressVersion;
                $item['updated_at'] = now()->toIso8601String();

                return $item;
            })->values()->all();

            $this->writeRequests($updatedRequests);
            return $redirectTarget()->with('success', 'WordPress is already installed for this website.');
        }

        try {
            if ($siteOwner !== '') {
                $this->applyWebsiteFilesystemIsolation($siteOwner, $rootPath);
            }

            $installerResult = $this->installSelectedApplication('wordpress', $rootPath, $domain, $phpVersion, $wordpressVersion);
            if (! $installerResult['installed']) {
                $message = trim((string) ($installerResult['message'] ?? ''));
                return $redirectTarget()->with('error', $message !== '' ? $message : 'WordPress installation failed.');
            }

            $this->relocateApacheDefaultPage();
            $this->syncLiveWebVhost($domain, $rootPath, $phpVersion);
        } catch (\Throwable $e) {
            return $redirectTarget()->with('error', $e->getMessage());
        }

        $runtimeStatus = strtolower((string) ($website['status'] ?? '')) === 'disabled'
            ? 'disabled'
            : $this->detectRuntimeStatus([
                'domain' => $domain,
                'root_path' => $rootPath,
                'status' => (string) ($website['status'] ?? 'pending'),
            ]);

        $updated = $requests->map(function (array $item) use ($id, $runtimeStatus, $wordpressVersion): array {
            if ((string) ($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['app_installer'] = 'wordpress';
            $item['wordpress_version'] = $wordpressVersion;
            $item['status'] = $runtimeStatus;
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($updated);

        return $redirectTarget()->with('success', 'WordPress installed successfully with one click.');
    }

    /**
     * Preview website files from dynamic base dir + normalized domain path.
     */
    public function preview(string $id, ?string $path = null): BinaryFileResponse|\Illuminate\Http\Response
    {
        $website = Website::query()
            ->visibleTo(request()->user())
            ->firstWhere('id', $id);
        if (! $website) {
            return response(
                "Preview not found\n".
                "Reason: website id does not exist\n".
                'Requested website id: '.$id."\n",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $domain = $this->normalizeDomain((string) ($website->domain ?? ''));
        if ($domain === '') {
            return response(
                "Preview not found\n".
                "Reason: website domain is empty\n".
                'Requested website id: '.$id."\n",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $siteFolderInput = $this->normalizeRootPath('', $domain);
        $siteFolder = realpath($siteFolderInput);
        if ($siteFolder === false || ! is_dir($siteFolder)) {
            return response(
                "Preview not found\n".
                "Reason: preview directory does not exist\n".
                'Requested website id: '.$id."\n".
                'Domain: '.$domain."\n".
                'Expected directory: '.str_replace('\\', '/', $siteFolderInput)."\n",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $requestedRelative = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string) ($path ?? '')), DIRECTORY_SEPARATOR);
        $requestedPath = $requestedRelative !== ''
            ? $siteFolder.DIRECTORY_SEPARATOR.$requestedRelative
            : $siteFolder.DIRECTORY_SEPARATOR.'index.php';

        $file = realpath($requestedPath);

        if ($file !== false && is_dir($file)) {
            $indexPhp = realpath($file.DIRECTORY_SEPARATOR.'index.php');
            $indexHtml = realpath($file.DIRECTORY_SEPARATOR.'index.html');
            $file = $indexPhp !== false ? $indexPhp : $indexHtml;
        }

        $folderPrefix = rtrim($siteFolder, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $isInsideFolder = is_string($file) && ($file === $siteFolder || str_starts_with($file, $folderPrefix));
        if (! $isInsideFolder || ! is_file((string) $file)) {
            $missing = $requestedRelative !== '' ? str_replace(DIRECTORY_SEPARATOR, '/', $requestedRelative) : '/';
            $expectedRawPath = $requestedRelative !== ''
                ? $siteFolder.DIRECTORY_SEPARATOR.$requestedRelative
                : $siteFolder.DIRECTORY_SEPARATOR.'index.php';
            $expectedPath = str_replace('\\', '/', $expectedRawPath);
            $isDirectoryRequest = $requestedRelative === '' || is_dir($expectedRawPath);

            $details = "Preview not found\n".
                "Reason: requested file is missing\n".
                'Requested URL path: '.$missing."\n".
                'Expected path: '.$expectedPath."\n";

            if ($isDirectoryRequest) {
                $details .= "Expected index files: index.php or index.html\n";
            }

            return response($details, 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if (pathinfo((string) $file, PATHINFO_EXTENSION) === 'php') {
            ob_start();
            include $file;
            $content = ob_get_clean();

            return response($content, 200)
                ->header('Content-Type', 'text/html');
        }

        return response()->file($file);
    }

    /**
     * Update website request.
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $existingRequest = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $this->validatePayload($request);
        $validated['app_installer'] = strtolower(trim((string) ($validated['app_installer'] ?? ($existingRequest['app_installer'] ?? 'none'))));
        $validated['wordpress_version'] = $this->normalizeWordPressVersion((string) ($validated['wordpress_version'] ?? ($existingRequest['wordpress_version'] ?? 'latest')));
        $validated['php_version'] = $this->normalizeWebsitePhpVersion((string) ($validated['php_version'] ?? ($existingRequest['php_version'] ?? '')));
        $validated['domain'] = $this->normalizeDomain($validated['domain']);
        $domainExists = collect($this->readRequests())
            ->contains(function (array $item) use ($id, $validated): bool {
                if ((string) ($item['id'] ?? '') === $id) {
                    return false;
                }

                return $this->normalizeDomain((string) ($item['domain'] ?? '')) === $validated['domain'];
            });
        if ($domainExists) {
            return back()->withErrors(['domain' => 'This domain already exists.']);
        }
        $validated['root_path'] = $this->normalizeRootPath((string) ($validated['root_path'] ?? ''), $validated['domain']);
        $validated['site_owner'] = $this->extractSiteOwnerFromRootPath($validated['root_path']);
        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);
        $sslNotice = null;

        $this->applyWebsiteFilesystemIsolation($validated['site_owner'], $validated['root_path']);
        $this->initializeWebsiteStarterFiles(
            $validated['root_path'],
            $validated['domain'],
            (string) $validated['php_version'],
        );
        $this->relocateApacheDefaultPage();
        $this->syncLiveWebVhost(
            $validated['domain'],
            $validated['root_path'],
            (string) $validated['php_version'],
            (string) ($existingRequest['domain'] ?? ''),
        );

        if ($validated['enable_ssl']) {
            $sslResult = $this->runIssueSslScript(
                $validated['domain'],
                $validated['root_path'],
                $this->shouldAddWwwAlias($validated['domain']),
            );

            if (! $sslResult['ran']) {
                $sslNotice = 'SSL auto-generate is not available on this server.';
            } elseif (! $sslResult['success']) {
                $sslNotice = trim($sslResult['output']) !== ''
                    ? 'SSL auto-generate failed: '.trim($sslResult['output'])
                    : 'SSL auto-generate failed.';
            } else {
                $this->syncLiveWebVhost(
                    $validated['domain'],
                    $validated['root_path'],
                    (string) $validated['php_version'],
                    (string) ($existingRequest['domain'] ?? ''),
                );
            }
        }

        $requests = collect($this->readRequests())->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            $item['domain'] = $validated['domain'];
            $item['root_path'] = $validated['root_path'];
            $item['site_owner'] = $validated['site_owner'];
            $item['php_version'] = $validated['php_version'];
            $item['app_installer'] = $validated['app_installer'];
            $item['wordpress_version'] = $validated['wordpress_version'];
            $item['enable_ssl'] = $validated['enable_ssl'];
            $item['command'] = $this->buildCommand($validated);
            $item['status'] = $this->detectRuntimeStatus([
                'domain' => $validated['domain'],
                'root_path' => $validated['root_path'],
                'status' => (string) ($item['status'] ?? 'pending'),
            ]);
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($requests);

        $message = $validated['enable_ssl']
            ? 'Website request updated successfully and SSL auto-generation was attempted.'
            : 'Website request updated successfully.';
        if ($sslNotice !== null) {
            $message .= ' '.$sslNotice;
        }

        return redirect()->route('websites.list')->with('success', $message);
    }

    /**
     * File manager for website root.
     */
    public function fileManager(Request $request, string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $basePath = $this->resolveFileManagerBasePath($website);
        $currentPath = $this->sanitizeRelativePath((string) $request->query('path', ''));
        $showHidden = $request->boolean('show_hidden', false);
        $directory = $this->resolvePathInsideBase($basePath, $currentPath);

        if (! is_dir($directory)) {
            $directory = $basePath;
            $currentPath = '';
        }

        $items = $this->listDirectoryItems($basePath, $directory, $showHidden);

        $selectedFilePath = $this->sanitizeRelativePath((string) $request->query('file', ''));
        $selectedFile = $this->readSelectedFile($basePath, $selectedFilePath);
        $directoryTree = $this->buildDirectoryTree($basePath, '', 24, $showHidden, $currentPath);

        return Inertia::render('Websites/FileManager', [
            'website' => [
                'id' => $website['id'],
                'domain' => $website['domain'] ?? '',
                'root_path' => $website['root_path'] ?? '',
            ],
            'basePath' => $basePath,
            'currentPath' => $currentPath,
            'showHidden' => $showHidden,
            'openUploadTab' => $request->boolean('open_upload', false),
            'openEditorModal' => $request->boolean('open_editor', false),
            'openEditorPage' => $request->boolean('editor_page', false),
            'directoryTree' => $directoryTree,
            'items' => $items,
            'selectedFile' => $selectedFile,
        ]);
    }

    public function createFolder(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1000'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\\/:*?\"<>|]+$/'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));
        $folderName = trim((string) $validated['name']);
        $targetRelative = $this->sanitizeRelativePath(trim($currentPath.'/'.$folderName, '/'));
        $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);

        if (is_dir($targetPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Folder already exists.');
        }

        if (! @mkdir($targetPath, 0755, true) && ! is_dir($targetPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to create folder.');
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', 'Folder created.');
    }

    public function createFile(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1000'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\\/:*?\"<>|]+$/'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));
        $fileName = trim((string) $validated['name']);
        $targetRelative = $this->sanitizeRelativePath(trim($currentPath.'/'.$fileName, '/'));
        $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);

        if (is_file($targetPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'File already exists.');
        }

        $dir = dirname($targetPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to create parent folder.');
        }

        if (@file_put_contents($targetPath, '') === false) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to create file.');
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath, 'file' => $targetRelative])->with('success', 'File created.');
    }

    public function saveFile(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'file_path' => ['required', 'string', 'max:1500'],
            'content' => ['nullable', 'string'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $fileRelative = $this->sanitizeRelativePath((string) $validated['file_path']);
        $filePath = $this->resolvePathInsideBase($basePath, $fileRelative);

        if (! is_file($filePath)) {
            return redirect()->route('websites.filemanager', ['id' => $id])->with('error', 'File not found.');
        }

        if (@file_put_contents($filePath, (string) ($validated['content'] ?? '')) === false) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'file' => $fileRelative])->with('error', 'Failed to save file.');
        }

        $parent = dirname($fileRelative);
        $path = $parent === '.' ? '' : $parent;

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $path, 'file' => $fileRelative])->with('success', 'File saved.');
    }

    public function uploadFile(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1500'],
            'upload' => ['required', 'file', 'max:2097152'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));
        $targetDir = $this->resolvePathInsideBase($basePath, $currentPath);

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath, 'open_upload' => 1])->with('error', 'Failed to create target directory for upload.');
        }

        $uploaded = $request->file('upload');
        if ($uploaded === null) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath, 'open_upload' => 1])->with('error', 'Upload file not found.');
        }

        $filename = $this->sanitizeFilename((string) $uploaded->getClientOriginalName());
        if ($filename === '') {
            $filename = 'uploaded-file';
        }

        $targetPath = $this->resolvePathInsideBase($basePath, $this->sanitizeRelativePath(trim($currentPath.'/'.$filename, '/')));
        if (@move_uploaded_file($uploaded->getPathname(), $targetPath) === false) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath, 'open_upload' => 1])->with('error', 'Failed to move uploaded file.');
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath, 'open_upload' => 1])->with('success', 'File uploaded successfully.');
    }

    public function changePermissions(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
            'permissions' => ['required', 'string', 'regex:/^[0-7]{3,4}$/'],
            'recursive' => ['nullable', 'boolean'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $itemRelative = $this->sanitizeRelativePath((string) $validated['item_path']);
        $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));

        if (! file_exists($itemPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Item not found.');
        }

        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Permission change not supported on Windows environment.');
        }

        $mode = octdec((string) $validated['permissions']);
        $recursive = (bool) ($validated['recursive'] ?? false);
        $changed = $recursive && is_dir($itemPath)
            ? $this->applyPermissionsRecursively($itemPath, $mode)
            : @chmod($itemPath, $mode);

        if (! $changed) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to change permissions.');
        }

        $message = $recursive && is_dir($itemPath)
            ? 'Permissions updated recursively.'
            : 'Permissions updated.';

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', $message);
    }

    public function renameItem(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
            'new_name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\\/:*?\"<>|]+$/'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $itemRelative = $this->sanitizeRelativePath((string) $validated['item_path']);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);

        if (! file_exists($itemPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Item not found.');
        }

        $newName = $this->sanitizeFilename((string) $validated['new_name']);
        if ($newName === '') {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Invalid new name.');
        }

        $parent = dirname($itemRelative);
        $parent = $parent === '.' ? '' : $parent;
        $targetRelative = $this->sanitizeRelativePath(trim($parent.'/'.$newName, '/'));
        $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);

        if (file_exists($targetPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Target name already exists.');
        }

        if (! @rename($itemPath, $targetPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to rename item.');
        }

        $query = ['id' => $id, 'path' => $currentPath];
        if (is_file($targetPath)) {
            $query['file'] = $targetRelative;
        }

        return redirect()->route('websites.filemanager', $query)->with('success', 'Item renamed.');
    }

    public function moveItems(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['nullable', 'string', 'max:1500'],
            'item_paths' => ['nullable', 'array', 'min:1'],
            'item_paths.*' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
            'destination_path' => ['nullable', 'string', 'max:1500'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $destinationPathRelative = $this->sanitizeRelativePath((string) ($validated['destination_path'] ?? ''));
        $destinationPath = $this->resolvePathInsideBase($basePath, $destinationPathRelative);

        if (! is_dir($destinationPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Destination folder not found.');
        }

        $allItems = [];
        if (! empty($validated['item_path'])) {
            $allItems[] = $this->sanitizeRelativePath((string) $validated['item_path']);
        }
        foreach ((array) ($validated['item_paths'] ?? []) as $multiItem) {
            $allItems[] = $this->sanitizeRelativePath((string) $multiItem);
        }

        $allItems = array_values(array_unique(array_filter($allItems)));
        if (count($allItems) === 0) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'No item selected to move.');
        }

        $movedCount = 0;
        $sameDestinationCount = 0;
        $errors = [];

        foreach ($allItems as $itemRelative) {
            $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);
            if (! file_exists($itemPath)) {
                $errors[] = "Item not found: {$itemRelative}";
                continue;
            }

            $targetRelative = $this->sanitizeRelativePath(trim($destinationPathRelative.'/'.basename($itemPath), '/'));
            if ($targetRelative === $itemRelative) {
                $sameDestinationCount++;
                continue;
            }

            if (is_dir($itemPath) && str_starts_with($targetRelative.'/', $itemRelative.'/')) {
                $errors[] = "Cannot move folder into itself: {$itemRelative}";
                continue;
            }

            $targetPath = $this->resolvePathInsideBase($basePath, $targetRelative);
            if (file_exists($targetPath)) {
                $errors[] = 'Target already exists: '.basename($targetPath);
                continue;
            }

            if (! @rename($itemPath, $targetPath)) {
                $errors[] = 'Failed to move: '.basename($itemPath);
                continue;
            }

            $movedCount++;
        }

        if ($movedCount === 0 && $sameDestinationCount > 0 && count($errors) === 0) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Selected item(s) are already in that folder.');
        }

        if ($movedCount === 0) {
            $details = implode(' | ', array_slice($errors, 0, 3));

            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', $details !== '' ? "Move failed. {$details}" : 'Move failed.');
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

            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', implode(' ', $parts));
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', "Moved {$movedCount} item(s).");
    }

    public function downloadFile(Request $request, string $id): BinaryFileResponse|RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'file_path' => ['required', 'string', 'max:1500'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $fileRelative = $this->sanitizeRelativePath((string) $validated['file_path']);
        $filePath = $this->resolvePathInsideBase($basePath, $fileRelative);

        if (! is_file($filePath)) {
            return redirect()->route('websites.filemanager', ['id' => $id])->with('error', 'File not found for download.');
        }

        if ($request->boolean('inline', false)) {
            return response()->file($filePath);
        }

        return response()->download($filePath, basename($filePath));
    }

    public function zipSelected(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'current_path' => ['nullable', 'string', 'max:1500'],
            'item_paths' => ['required', 'array', 'min:1'],
            'item_paths.*' => ['required', 'string', 'max:1500'],
            'zip_name' => ['nullable', 'string', 'max:255'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $itemPaths = collect((array) $validated['item_paths'])
            ->map(fn ($path) => $this->sanitizeRelativePath((string) $path))
            ->filter(fn (string $path) => $path !== '')
            ->values()
            ->all();

        if (count($itemPaths) === 0) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'No valid items selected for zip.');
        }

        $zipNameInput = trim((string) ($validated['zip_name'] ?? ''));
        $zipName = $this->sanitizeFilename($zipNameInput !== '' ? $zipNameInput : 'archive-'.now()->format('Ymd-His'));
        if (! str_ends_with(strtolower($zipName), '.zip')) {
            $zipName .= '.zip';
        }

        $zipRelative = $this->sanitizeRelativePath(trim($currentPath.'/'.$zipName, '/'));
        $zipPath = $this->resolvePathInsideBase($basePath, $zipRelative);

        if (file_exists($zipPath)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Zip file already exists.');
        }

        if (! class_exists(ZipArchive::class)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', $this->zipExtensionMissingMessage());
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to create zip file.');
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

        $zip->close();

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', 'Zip created successfully.');
    }

    public function unzipItem(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'zip_path' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
        $zipRelative = $this->sanitizeRelativePath((string) $validated['zip_path']);
        $currentPath = $this->sanitizeRelativePath((string) ($validated['current_path'] ?? ''));
        $zipPath = $this->resolvePathInsideBase($basePath, $zipRelative);

        if (! is_file($zipPath) || ! str_ends_with(strtolower($zipPath), '.zip')) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Valid zip file not found.');
        }

        if (! class_exists(ZipArchive::class)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', $this->zipExtensionMissingMessage());
        }

        $extractTo = dirname($zipPath);
        if (! is_dir($extractTo) || ! is_writable($extractTo)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Extract target directory is not writable.');
        }

        $zip = new ZipArchive();
        try {
            $openResult = $zip->open($zipPath);
            if ($openResult !== true) {
                return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', $this->zipOpenErrorMessage($openResult));
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (! is_string($entryName) || ! $this->isSafeZipEntryPath($entryName)) {
                    return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Zip contains unsafe file paths and cannot be extracted.');
                }
            }

            $ok = $zip->extractTo($extractTo);
            if (! $ok) {
                return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to extract zip file. Check file permissions and archive integrity.');
            }
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Zip extract failed due to server error.');
        } finally {
            $zip->close();
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', 'Zip extracted successfully.');
    }

    public function deleteItem(Request $request, string $id): RedirectResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'item_path' => ['nullable', 'string', 'max:1500'],
            'item_paths' => ['nullable', 'array', 'min:1'],
            'item_paths.*' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
        ]);

        $basePath = $this->resolveFileManagerBasePath($website);
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
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'No item selected to delete.');
        }

        foreach ($allItems as $itemRelative) {
            $itemPath = $this->resolvePathInsideBase($basePath, $itemRelative);
            if (is_dir($itemPath)) {
                $this->deleteDirectoryRecursive($itemPath);
            } elseif (is_file($itemPath)) {
                @unlink($itemPath);
            }
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', 'Selected item(s) deleted.');
    }

    /**
     * Delete website request.
     */
    public function destroy(string $id): RedirectResponse
    {
        $requests = collect($this->readRequests());
        $existingRequest = $this->findAuthorizedWebsiteOrFail($id);
        $before = $requests->count();
        $filtered = $requests->reject(fn (array $item) => ($item['id'] ?? null) === $id)->values();

        if ($filtered->count() === $before) {
            return redirect()->route('websites.list')->with('error', 'Website request not found.');
        }

        if (is_array($existingRequest)) {
            $this->removeLiveWebVhost((string) ($existingRequest['domain'] ?? ''));
        }

        $this->writeRequests($filtered->all());

        return redirect()->route('websites.list')->with('success', 'Website request deleted successfully.');
    }

    public function updateStatus(Request $request, string $id): RedirectResponse
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

    /**
     * Build execution command from payload.
     *
     * @param array<string, mixed> $payload
     */
    private function buildCommand(array $payload): string
    {
        return sprintf(
            '/usr/local/bin/serverinstaller-site create --domain=%s --root=%s --php=%s%s',
            escapeshellarg((string) $payload['domain']),
            escapeshellarg((string) $payload['root_path']),
            escapeshellarg((string) $payload['php_version']),
            ! empty($payload['enable_ssl']) ? ' --ssl' : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/',
            ],
            'root_path' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    if (! $this->isValidWebsiteRootPath($value)) {
                        $fail("The {$attribute} must be inside ".$this->websiteBaseDirectory().' and follow <base>/<owner>/<site_dir>.');
                    }
                },
            ],
            'php_version' => ['required', 'string', 'max:10'],
            'app_installer' => ['nullable', 'string', 'in:none,wordpress'],
            'wordpress_version' => ['nullable', 'string', 'max:20', 'regex:/^(latest|\\d+\\.\\d+(?:\\.\\d+)?)$/i'],
            'enable_ssl' => ['boolean'],
        ]);
    }

    /**
     * Normalize domain input.
     */
    private function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    /**
     * Normalize root path to isolated /home/<owner>/<site_dir> format.
     * Main domain uses public_html; subdomains use their own directory.
     */
    private function normalizeRootPath(string $rootPath, string $domain): string
    {
        $layout = $this->deriveWebsiteLayout($domain);
        $homeBase = $this->websiteBaseDirectory();
        $normalized = $this->normalizeAbsolutePath($rootPath);

        if ($normalized === '') {
            return $layout['root_path'];
        }

        if (! $this->pathStartsWith($normalized, $homeBase.'/')) {
            return $layout['root_path'];
        }

        $suffix = trim(substr($normalized, strlen($homeBase.'/')), '/');
        if ($suffix === '') {
            return $layout['root_path'];
        }

        $parts = array_values(array_filter(explode('/', $suffix), fn (string $part) => $part !== '' && $part !== '.' && $part !== '..'));
        $owner = $this->normalizeSiteOwner((string) ($parts[0] ?? $layout['site_owner']), $layout['site_owner']);
        $requestedSiteDir = (string) ($parts[1] ?? '');
        $siteDir = $this->normalizeSiteDirectory($requestedSiteDir, $layout['site_dir']);

        return $homeBase."/{$owner}/{$siteDir}";
    }

    /**
     * @return array{site_owner: string, site_dir: string, root_path: string}
     */
    private function deriveWebsiteLayout(string $domain): array
    {
        $homeBase = $this->websiteBaseDirectory();
        $normalizedDomain = $this->normalizeDomain($domain);
        $labels = array_values(array_filter(explode('.', $normalizedDomain), fn (string $label) => $label !== ''));
        $defaultOwner = $this->normalizeSiteOwnerFromDomain($normalizedDomain);

        $ownerSeed = $defaultOwner;
        $siteDirSeed = self::DEFAULT_SITE_DIR;

        if (count($labels) >= 2) {
            [$registrableLabels, $subLabels] = $this->splitDomainParts($labels);
            $ownerSeed = $this->buildOwnerSeedFromRegistrable($registrableLabels);
            if (count($subLabels) === 1 && $subLabels[0] === 'www') {
                $subLabels = [];
            }
            if (count($subLabels) > 0) {
                $siteDirSeed = implode('_', $subLabels);
            }
        }

        $siteOwner = $this->normalizeSiteOwner($ownerSeed, $defaultOwner);
        $siteDir = $this->normalizeSiteDirectory($siteDirSeed, self::DEFAULT_SITE_DIR);

        return [
            'site_owner' => $siteOwner,
            'site_dir' => $siteDir,
            'root_path' => $homeBase."/{$siteOwner}/{$siteDir}",
        ];
    }

    private function normalizeSiteOwnerFromDomain(string $domain): string
    {
        $labels = array_values(array_filter(explode('.', $this->normalizeDomain($domain)), fn (string $label) => $label !== ''));
        if (count($labels) >= 2) {
            [$registrableLabels] = $this->splitDomainParts($labels);
            $candidate = $this->buildOwnerSeedFromRegistrable($registrableLabels);
        } else {
            $candidate = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $domain));
        }

        $candidate = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $candidate));
        $candidate = trim($candidate, '_');
        if ($candidate === '' || ctype_digit($candidate[0])) {
            $candidate = 'site_'.$candidate;
        }

        return substr($candidate, 0, 32);
    }

    /**
     * @param array<int, string> $labels
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function splitDomainParts(array $labels): array
    {
        $count = count($labels);
        if ($count < 2) {
            return [$labels, []];
        }

        $suffixParts = 1;
        if ($count >= 3) {
            $lastTwo = strtolower($labels[$count - 2].'.'.$labels[$count - 1]);
            if (in_array($lastTwo, self::COMPOUND_PUBLIC_SUFFIXES, true)) {
                $suffixParts = 2;
            }
        }

        $registrableLength = $suffixParts + 1;
        if ($count < $registrableLength) {
            $registrableLength = 2;
        }

        $registrableLabels = array_slice($labels, -$registrableLength);
        $subLabels = array_slice($labels, 0, -$registrableLength);

        return [$registrableLabels, $subLabels];
    }

    /**
     * @param array<int, string> $registrableLabels
     */
    private function buildOwnerSeedFromRegistrable(array $registrableLabels): string
    {
        if (count($registrableLabels) <= 2) {
            return (string) ($registrableLabels[0] ?? 'site');
        }

        return implode('_', $registrableLabels);
    }

    private function normalizeSiteOwner(string $owner, string $fallback): string
    {
        $owner = strtolower(trim($owner));
        if ($owner === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $owner) !== 1) {
            return $fallback;
        }

        return $owner;
    }

    private function normalizeSiteDirectory(string $siteDir, string $fallback): string
    {
        $siteDir = strtolower(trim($siteDir));
        $siteDir = (string) preg_replace('/[^a-z0-9._-]+/', '_', $siteDir);
        $siteDir = trim($siteDir, '._-');
        if ($siteDir === '' || $siteDir === '.' || $siteDir === '..') {
            return $fallback;
        }

        return substr($siteDir, 0, 64);
    }

    private function extractSiteOwnerFromRootPath(string $rootPath): string
    {
        $homeBase = $this->websiteBaseDirectory();
        $path = $this->normalizeAbsolutePath($rootPath);
        if (! $this->pathStartsWith($path, $homeBase.'/')) {
            return 'site_default';
        }

        $suffix = trim(substr($path, strlen($homeBase.'/')), '/');
        $parts = explode('/', $suffix);

        return $this->normalizeSiteOwner((string) ($parts[0] ?? ''), 'site_default');
    }

    /**
     * Best-effort ownership and chmod isolation.
     * Only applies when running as root on Linux.
     */
    private function applyWebsiteFilesystemIsolation(string $siteOwner, string $rootPath): void
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return;
        }

        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $homePath = rtrim($this->websiteBaseDirectory(), '/')."/{$siteOwner}";
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        $publicRoot = $homePath.'/'.self::DEFAULT_SITE_DIR;
        if (! str_starts_with($rootPath, $homePath.'/')) {
            $rootPath = $publicRoot;
        }

        $this->runSystemCommand("getent group ".escapeshellarg($siteOwner)." >/dev/null 2>&1 || groupadd ".escapeshellarg($siteOwner));
        $this->runSystemCommand("id -u ".escapeshellarg($siteOwner)." >/dev/null 2>&1 || useradd -m -d ".escapeshellarg($homePath)." -s /usr/sbin/nologin -g ".escapeshellarg($siteOwner)." ".escapeshellarg($siteOwner));
        $this->runSystemCommand("mkdir -p ".escapeshellarg($homePath));
        $this->runSystemCommand("chown root:root ".escapeshellarg($homePath));
        $this->runSystemCommand("chmod 711 ".escapeshellarg($homePath));
        $this->runSystemCommand("mkdir -p ".escapeshellarg($publicRoot));
        $this->runSystemCommand("mkdir -p ".escapeshellarg($rootPath));
        $this->runSystemCommand("chown -R ".escapeshellarg($siteOwner).":www-data ".escapeshellarg($publicRoot));
        $this->runSystemCommand("find ".escapeshellarg($publicRoot)." -type d -exec chmod 750 {} \\;");
        $this->runSystemCommand("find ".escapeshellarg($publicRoot)." -type f -exec chmod 640 {} \\;");
        if ($rootPath !== $publicRoot) {
            $this->runSystemCommand("chown -R ".escapeshellarg($siteOwner).":www-data ".escapeshellarg($rootPath));
            $this->runSystemCommand("find ".escapeshellarg($rootPath)." -type d -exec chmod 750 {} \\;");
            $this->runSystemCommand("find ".escapeshellarg($rootPath)." -type f -exec chmod 640 {} \\;");
        }
    }

    private function runSystemCommand(string $command): void
    {
        try {
            @shell_exec($command.' 2>&1');
        } catch (\Throwable $e) {
            // Ignore to keep website flow non-blocking.
        }
    }

    private function resolveProjectArtisanPath(string $rootPath): ?string
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
    private function runProjectArtisanCommand(string $projectPath, string $artisanCommand, string $siteOwner = ''): array
    {
        $projectPath = rtrim($this->normalizeAbsolutePath($projectPath), '/');
        if ($projectPath === '' || ! is_dir($projectPath)) {
            return [
                'success' => false,
                'output' => 'Invalid project path.',
                'exit_code' => 1,
            ];
        }

        $phpBinary = trim((string) PHP_BINARY);
        if ($phpBinary === '') {
            $phpBinary = 'php';
        }

        $snippet = sprintf(
            'cd %s && %s artisan %s',
            escapeshellarg($projectPath),
            escapeshellarg($phpBinary),
            escapeshellarg($artisanCommand),
        );

        $output = [];
        $exitCode = 1;
        $owner = trim($siteOwner);
        $isLinux = ! str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS');
        $canRunAsOwner = $isLinux
            && $owner !== ''
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

    /**
     * @return array{ran: bool, success: bool, output: string, exit_code: int}
     */
    private function runIssueSslScript(string $domain, string $rootPath, bool $includeWwwAlias): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ran' => false, 'success' => false, 'output' => 'Windows environment', 'exit_code' => 1];
        }

        $scriptCandidates = [
            base_path('scripts/issue-ssl.sh'),
            '/usr/local/bin/serverpanel-issue-ssl.sh',
        ];

        $scriptPath = '';
        foreach ($scriptCandidates as $candidate) {
            if (is_file($candidate)) {
                $scriptPath = $candidate;
                break;
            }
        }
        if ($scriptPath === '') {
            return ['ran' => false, 'success' => false, 'output' => 'issue-ssl script not found', 'exit_code' => 1];
        }

        $parts = [
            'bash',
            escapeshellarg($scriptPath),
            escapeshellarg($this->normalizeDomain($domain)),
            escapeshellarg($rootPath),
            $includeWwwAlias ? '1' : '0',
        ];
        $command = implode(' ', $parts);
        $output = [];
        $exitCode = 1;

        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        if ($isRoot) {
            @exec($command.' 2>&1', $output, $exitCode);
        } else {
            $sudoPath = trim((string) @shell_exec('command -v sudo 2>/dev/null'));
            if ($sudoPath === '') {
                $output = ['sudo command not found and process is not root.'];
                $exitCode = 77;
            } else {
                @exec(escapeshellarg($sudoPath).' -n '.$command.' 2>&1', $output, $exitCode);
            }
        }

        $message = trim(implode("\n", $output));
        if ($exitCode !== 0) {
            Log::warning('SSL issue script failed', [
                'domain' => $domain,
                'root_path' => $rootPath,
                'include_www_alias' => $includeWwwAlias,
                'script' => $scriptPath,
                'exit_code' => $exitCode,
                'output' => $message,
            ]);
        }

        return [
            'ran' => true,
            'success' => $exitCode === 0,
            'output' => $message,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @return array{ran: bool, success: bool, output: string}
     */
    private function runSyncVhostScript(string $action, string $domain, ?string $rootPath = null, ?string $phpVersion = null, ?string $oldDomain = null): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ran' => false, 'success' => false, 'output' => 'Windows environment'];
        }

        $scriptCandidates = [
            base_path('scripts/sync-vhost.sh'),
            '/usr/local/bin/serverpanel-sync-vhost.sh',
        ];

        $scriptPath = '';
        foreach ($scriptCandidates as $candidate) {
            if (is_file($candidate)) {
                $scriptPath = $candidate;
                break;
            }
        }
        if ($scriptPath === '') {
            return ['ran' => false, 'success' => false, 'output' => 'sync-vhost script not found'];
        }

        $parts = [
            'bash',
            escapeshellarg($scriptPath),
            escapeshellarg($action),
            escapeshellarg($this->normalizeDomain($domain)),
        ];
        if ($rootPath !== null) {
            $parts[] = escapeshellarg($rootPath);
        }
        if ($phpVersion !== null) {
            $parts[] = escapeshellarg($phpVersion);
        }
        if ($oldDomain !== null) {
            $parts[] = escapeshellarg($this->normalizeDomain($oldDomain));
        }

        $envPrefix = sprintf(
            'PANEL_PORT=%d APACHE_BACKEND_PORT=%d NGINX_PRIMARY_PORT=%d PHPMYADMIN_PORT=%d REDIS_SERVICE=%s ',
            $this->panelPort(),
            $this->apacheBackendPort(),
            $this->nginxPrimaryPort(),
            $this->phpMyAdminPort(),
            escapeshellarg($this->redisServiceUnit()),
        );
        $output = [];
        $exitCode = 1;
        @exec($envPrefix.implode(' ', $parts).' 2>&1', $output, $exitCode);
        $message = trim(implode("\n", $output));
        $success = in_array($exitCode, [0, 3], true);

        if ($exitCode === 3) {
            Log::warning('Vhost sync script completed with recoverable errors', [
                'action' => $action,
                'domain' => $domain,
                'root_path' => $rootPath,
                'php_version' => $phpVersion,
                'old_domain' => $oldDomain,
                'script' => $scriptPath,
                'exit_code' => $exitCode,
                'output' => $message,
            ]);
        } elseif ($exitCode !== 0) {
            Log::warning('Vhost sync script failed', [
                'action' => $action,
                'domain' => $domain,
                'root_path' => $rootPath,
                'php_version' => $phpVersion,
                'old_domain' => $oldDomain,
                'script' => $scriptPath,
                'exit_code' => $exitCode,
                'output' => $message,
            ]);
        }

        return [
            'ran' => true,
            'success' => $success,
            'output' => $message,
        ];
    }

    private function websiteBaseDirectory(): string
    {
        $configured = trim((string) config('app.server_base_dir', ''));
        if ($configured !== '') {
            return rtrim($this->normalizeAbsolutePath($configured), '/');
        }

        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return rtrim($this->normalizeAbsolutePath(dirname(base_path())), '/');
        }

        return self::HOME_BASE;
    }

    /**
     * @param array<string,mixed> $website
     * @return array<string,mixed>
     */
    private function normalizeWebsiteRecord(array $website): array
    {
        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));
        if ($domain === '') {
            return $website;
        }

        $website['domain'] = $domain;
        $website['root_path'] = $this->normalizeRootPath((string) ($website['root_path'] ?? ''), $domain);
        $website['site_owner'] = $this->extractSiteOwnerFromRootPath((string) $website['root_path']);
        $website['wordpress_version'] = $this->normalizeWordPressVersion((string) ($website['wordpress_version'] ?? 'latest'));

        return $website;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function visibleRequestsForActor(?User $actor = null): Collection
    {
        $actor ??= request()->user();

        return collect($this->readRequests())
            ->filter(fn (array $website): bool => $this->actorCanAccessWebsite($website, $actor))
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $requests
     * @return array<int, array<string, mixed>>
     */
    private function decorateWebsiteRecords(Collection $requests): array
    {
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
            ->map(function (array $item) use ($usersById): array {
                $item = $this->normalizeWebsiteRecord($item);
                $domain = (string) ($item['domain'] ?? '');

                if (empty($item['command'])) {
                    $item['command'] = $this->buildCommand([
                        'domain' => $domain,
                        'root_path' => (string) ($item['root_path'] ?? ''),
                        'php_version' => (string) ($item['php_version'] ?? ''),
                        'enable_ssl' => (bool) ($item['enable_ssl'] ?? false),
                    ]);
                }

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

    /**
     * @return array<string, mixed>
     */
    private function findAuthorizedWebsiteOrFail(string $id, ?User $actor = null): array
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

        $actor ??= request()->user();
        abort_unless($this->actorCanAccessWebsite($website, $actor), 403);

        return $this->normalizeWebsiteRecord($website);
    }

    private function actorCanAccessWebsite(array $website, ?User $actor = null): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor->hasRole('admin')) {
            return true;
        }

        if ($actor->hasRole('reseller')) {
            return (int) ($website['assigned_reseller_id'] ?? 0) === (int) $actor->id;
        }

        if ($actor->hasRole('general') || $actor->hasRole('general_user')) {
            return (int) ($website['assigned_user_id'] ?? 0) === (int) $actor->id;
        }

        return false;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path));
    }

    private function pathStartsWith(string $path, string $prefix): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $prefix = $this->normalizeAbsolutePath($prefix);

        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return str_starts_with(strtolower($path), strtolower($prefix));
        }

        return str_starts_with($path, $prefix);
    }

    private function isValidWebsiteRootPath(string $rootPath): bool
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
    private function initializeWebsiteStarterFiles(string $rootPath, string $domain, ?string $phpVersion = null): void
    {
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        if ($rootPath === '') {
            return;
        }

        if (! is_dir($rootPath) && ! @mkdir($rootPath, 0755, true) && ! is_dir($rootPath)) {
            return;
        }

        $entries = @scandir($rootPath);
        if (! is_array($entries)) {
            return;
        }

        $existing = array_values(array_filter($entries, fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
        if (count($existing) > 0) {
            return;
        }

        $normalizedDomain = $this->normalizeDomain($domain);
        $selectedPhpVersion = trim((string) $phpVersion);
        if ($selectedPhpVersion === '' || preg_match('/^\d+\.\d+$/', $selectedPhpVersion) !== 1) {
            $selectedPhpVersion = 'auto';
        }

        $indexPhp = <<<PHP
<?php
\$domain = '{$normalizedDomain}';
\$configuredPhpVersion = '{$selectedPhpVersion}';
\$runtimePhpVersion = PHP_VERSION;
\$generatedAt = date('Y-m-d H:i:s');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars(\$domain, ENT_QUOTES, 'UTF-8'); ?> | Ready</title>
    <style>
        :root {
            color-scheme: light;
            --bg-1: #0f172a;
            --bg-2: #1e293b;
            --card: #ffffff;
            --accent: #0ea5e9;
            --text: #0f172a;
            --muted: #64748b;
            --ring: rgba(14, 165, 233, 0.22);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background: radial-gradient(circle at top right, #1d4ed8 0%, #0f172a 38%, #020617 100%);
            display: grid;
            place-items: center;
            padding: 28px;
        }
        .card {
            width: min(780px, 100%);
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(2, 6, 23, 0.35);
            overflow: hidden;
        }
        .hero {
            padding: 28px 30px;
            background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 65%, #1d4ed8 100%);
            color: #fff;
        }
        .hero h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            letter-spacing: 0.2px;
        }
        .hero p {
            margin: 10px 0 0;
            opacity: 0.95;
            font-size: 14px;
        }
        .body {
            padding: 24px 30px 30px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .meta {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            background: #f8fafc;
            box-shadow: inset 0 0 0 1px var(--ring);
        }
        .label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: var(--muted);
        }
        .value {
            display: block;
            margin-top: 6px;
            font-size: 17px;
            font-weight: 700;
            color: var(--text);
            word-break: break-word;
        }
        .footer {
            margin-top: 18px;
            border-top: 1px dashed #cbd5e1;
            padding-top: 12px;
            color: var(--muted);
            font-size: 13px;
        }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            background: #e2e8f0;
            border-radius: 6px;
            padding: 2px 6px;
            color: #0f172a;
        }
    </style>
</head>
<body>
    <article class="card">
        <header class="hero">
            <h1><?php echo htmlspecialchars(\$domain, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Your new website is live and ready for deployment.</p>
        </header>
        <section class="body">
            <div class="grid">
                <div class="meta">
                    <span class="label">Configured PHP</span>
                    <span class="value"><?php echo htmlspecialchars(\$configuredPhpVersion, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="meta">
                    <span class="label">Runtime PHP</span>
                    <span class="value"><?php echo htmlspecialchars(\$runtimePhpVersion, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="meta">
                    <span class="label">Generated At</span>
                    <span class="value"><?php echo htmlspecialchars(\$generatedAt, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
            <p class="footer">
                Managed by <strong>ServerPanel</strong>. Start building from
                <code><?php echo htmlspecialchars(rtrim((string) dirname(__FILE__), '/'), ENT_QUOTES, 'UTF-8'); ?></code>.
            </p>
        </section>
    </article>
</body>
</html>

PHP;

        $indexHtml = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$normalizedDomain} | Ready</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;background:#0f172a;color:#0f172a;padding:24px}
        .card{width:min(720px,100%);background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 18px 40px rgba(2,6,23,.32)}
        .head{padding:24px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff}
        .head h1{margin:0;font-size:26px}
        .body{padding:22px}
        .meta{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px}
        .label{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;letter-spacing:.08em}
        .value{margin-top:6px;font-size:16px;font-weight:700}
    </style>
</head>
<body>
    <article class="card">
        <header class="head"><h1>{$normalizedDomain}</h1><p>Starter page generated by ServerPanel</p></header>
        <section class="body">
            <div class="meta">
                <div class="item"><div class="label">Configured PHP</div><div class="value">{$selectedPhpVersion}</div></div>
                <div class="item"><div class="label">Status</div><div class="value">Ready</div></div>
            </div>
        </section>
    </article>
</body>
</html>

HTML;

        @file_put_contents(rtrim($rootPath, '/').'/index.php', $indexPhp);
        @file_put_contents(rtrim($rootPath, '/').'/index.html', $indexHtml);

        $extraDir = rtrim($rootPath, '/').'/extra';
        if (! is_dir($extraDir)) {
            @mkdir($extraDir, 0755, true);
        }

        @file_put_contents(
            $extraDir.'/first-site-note.txt',
            "This folder was created on first website creation.\nDomain: {$normalizedDomain}\nConfigured PHP: {$selectedPhpVersion}\nCreated at: ".now()->toDateTimeString()."\n"
        );
    }

    /**
     * @return array{attempted: bool, installed: bool, message: string}
     */
    private function installSelectedApplication(string $installer, string $rootPath, string $domain, string $phpVersion, string $wordpressVersion = 'latest'): array
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

    private function hasWordPressFiles(string $rootPath): bool
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
                        $downloaded = @file_put_contents($tmpZip, $body) !== false;
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

    /**
     * @return array<int, string>
     */
    private function getWordPressVersionOptions(): array
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
    private function copyDirectoryContentsRecursive(string $sourceDirectory, string $targetDirectory): array
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

    /**
     * Move default Apache page to extra directory once.
     */
    private function relocateApacheDefaultPage(): void
    {
        if (! $this->canManageApacheVhosts()) {
            return;
        }

        $defaultRoot = '/var/www/html';
        $defaultIndex = $defaultRoot.'/index.html';
        $extraDir = $defaultRoot.'/extra';
        $marker = $extraDir.'/.default-page-moved';

        if (is_file($marker) || ! is_file($defaultIndex)) {
            return;
        }

        if (! is_dir($extraDir)) {
            @mkdir($extraDir, 0755, true);
        }

        $archivePath = $extraDir.'/apache-default-index-'.date('Ymd-His').'.html';
        if (! @rename($defaultIndex, $archivePath)) {
            if (@copy($defaultIndex, $archivePath)) {
                @unlink($defaultIndex);
            }
        }

        @file_put_contents(
            $defaultIndex,
            '<!doctype html><html><head><meta charset="utf-8"><title>ServerPanel</title></head><body><h1>ServerPanel</h1><p>Default Apache page moved to /var/www/html/extra/.</p></body></html>'
        );
        @file_put_contents($marker, now()->toDateTimeString());
    }

    private function syncLiveWebVhost(string $domain, string $rootPath, string $phpVersion, ?string $oldDomain = null): void
    {
        $scriptResult = $this->runSyncVhostScript('sync', $domain, $rootPath, $phpVersion, $oldDomain);
        if ($scriptResult['ran'] && $scriptResult['success']) {
            return;
        }

        $this->syncLiveApacheVhost($domain, $rootPath, $phpVersion, $oldDomain);
        $this->syncLiveNginxVhost($domain, $rootPath, $phpVersion, $oldDomain);
    }

    /**
     * @return array{generated: bool, messages: array<int, string>}
     */
    private function syncLiveWebVhostWithReport(string $domain, string $rootPath, string $phpVersion): array
    {
        $messages = [];
        $generated = false;
        $normalizedDomain = $this->normalizeDomain($domain);
        $isWindows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS');
        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $scriptResult = $this->runSyncVhostScript('sync', $normalizedDomain, $rootPath, $phpVersion);
        if ($scriptResult['ran'] && $scriptResult['success']) {
            $apacheConf = $this->existingApacheVhostPath($normalizedDomain);
            $nginxConf = $this->existingNginxVhostPath($normalizedDomain);
            $nginxEnabled = $this->existingNginxEnabledVhostPath($normalizedDomain);

            if (is_file($apacheConf)) {
                $generated = true;
                $messages[] = "Apache vhost synced: {$apacheConf}";
            }
            if (is_file($nginxConf) && (is_link($nginxEnabled) || is_file($nginxEnabled))) {
                $generated = true;
                $messages[] = "Nginx vhost synced: {$nginxConf}";
            }
            if ($scriptResult['output'] !== '') {
                $messages[] = $scriptResult['output'];
            }

            return [
                'generated' => $generated,
                'messages' => $messages,
            ];
        }
        if ($scriptResult['ran'] && ! $scriptResult['success'] && $scriptResult['output'] !== '') {
            $messages[] = 'Vhost script failed: '.$scriptResult['output'];
        }

        if ($this->canManageApacheVhosts()) {
            $this->syncLiveApacheVhost($normalizedDomain, $rootPath, $phpVersion);
            $apacheConf = $this->existingApacheVhostPath($normalizedDomain);
            if (is_file($apacheConf)) {
                $generated = true;
                $messages[] = "Apache vhost synced: {$apacheConf}";
            } else {
                $messages[] = "Apache sync attempted but file not found: {$apacheConf}";
            }
        } elseif ($isWindows) {
            $messages[] = 'Apache vhost skipped: Windows environment.';
        } elseif (! is_dir('/etc/apache2/sites-available')) {
            $messages[] = 'Apache vhost skipped: /etc/apache2/sites-available not found.';
        } elseif (! $isRoot) {
            $messages[] = 'Apache vhost skipped: process is not running as root.';
        } else {
            $messages[] = 'Apache vhost skipped: insufficient permissions.';
        }

        if ($this->canManageNginxVhosts()) {
            $this->syncLiveNginxVhost($normalizedDomain, $rootPath, $phpVersion);
            $nginxConf = $this->existingNginxVhostPath($normalizedDomain);
            $nginxEnabled = $this->existingNginxEnabledVhostPath($normalizedDomain);
            if (is_file($nginxConf) && (is_link($nginxEnabled) || is_file($nginxEnabled))) {
                $generated = true;
                $messages[] = "Nginx vhost synced: {$nginxConf}";
            } else {
                $messages[] = "Nginx sync attempted but config/link missing: {$nginxConf}";
            }
        } elseif ($isWindows) {
            $messages[] = 'Nginx vhost skipped: Windows environment.';
        } elseif (! is_dir('/etc/nginx/sites-available') || ! is_dir('/etc/nginx/sites-enabled')) {
            $messages[] = 'Nginx vhost skipped: /etc/nginx/sites-available or sites-enabled not found.';
        } elseif (! $isRoot) {
            $messages[] = 'Nginx vhost skipped: process is not running as root.';
        } else {
            $messages[] = 'Nginx vhost skipped: insufficient permissions.';
        }

        return [
            'generated' => $generated,
            'messages' => $messages,
        ];
    }

    private function removeLiveWebVhost(string $domain): void
    {
        $scriptResult = $this->runSyncVhostScript('remove', $domain);
        if ($scriptResult['ran'] && $scriptResult['success']) {
            return;
        }

        $this->removeLiveApacheVhost($domain);
        $this->removeLiveNginxVhost($domain);
    }

    private function syncLiveApacheVhost(string $domain, string $rootPath, string $phpVersion, ?string $oldDomain = null): void
    {
        if (! $this->canManageApacheVhosts()) {
            return;
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return;
        }

        $confPath = $this->apacheVhostPath($domain);
        $legacyConfPath = $this->apacheLegacyVhostPath($domain);
        @file_put_contents($confPath, $this->buildApacheVhostConfig($domain, $rootPath, $phpVersion));
        @chmod($confPath, 0644);

        if ($legacyConfPath !== $confPath) {
            $this->runSystemCommand('a2dissite '.escapeshellarg(basename($legacyConfPath)));
            if (is_file($legacyConfPath)) {
                @unlink($legacyConfPath);
            }
        }

        if ($oldDomain !== null) {
            $normalizedOldDomain = $this->normalizeDomain($oldDomain);
            if ($normalizedOldDomain !== '' && $normalizedOldDomain !== $domain) {
                $this->removeLiveApacheVhost($normalizedOldDomain);
            }
        }

        $confName = basename($confPath);
        $this->runSystemCommand('a2ensite '.escapeshellarg($confName));
        $this->runSystemCommand('systemctl enable apache2 >/dev/null 2>&1 || true');
        $this->runSystemCommand('apache2ctl -t && (systemctl reload apache2 || systemctl restart apache2 || systemctl start apache2)');
    }

    private function removeLiveApacheVhost(string $domain): void
    {
        if (! $this->canManageApacheVhosts()) {
            return;
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return;
        }

        foreach ($this->apacheVhostPaths($domain) as $confPath) {
            $confName = basename($confPath);
            $this->runSystemCommand('a2dissite '.escapeshellarg($confName));
            if (is_file($confPath)) {
                @unlink($confPath);
            }
        }

        $this->runSystemCommand('systemctl enable apache2 >/dev/null 2>&1 || true');
        $this->runSystemCommand('apache2ctl -t && (systemctl reload apache2 || systemctl restart apache2 || systemctl start apache2)');
    }

    private function apacheVhostPath(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);

        return '/etc/apache2/sites-available/'.$this->vhostFileBaseName($domain).'.conf';
    }

    private function apacheLegacyVhostPath(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);

        return '/etc/apache2/sites-available/'.$domain.'.conf';
    }

    /**
     * @return array<int, string>
     */
    private function apacheVhostPaths(string $domain): array
    {
        $primaryPath = $this->apacheVhostPath($domain);
        $legacyPath = $this->apacheLegacyVhostPath($domain);

        if ($legacyPath === $primaryPath) {
            return [$primaryPath];
        }

        return [$primaryPath, $legacyPath];
    }

    private function existingApacheVhostPath(string $domain): string
    {
        foreach ($this->apacheVhostPaths($domain) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $this->apacheVhostPath($domain);
    }

    private function syncLiveNginxVhost(string $domain, string $rootPath, string $phpVersion, ?string $oldDomain = null): void
    {
        if (! $this->canManageNginxVhosts()) {
            return;
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return;
        }

        $confPath = $this->nginxVhostPath($domain);
        $enabledPath = $this->nginxEnabledVhostPath($domain);
        $legacyConfPath = $this->nginxLegacyVhostPath($domain);
        $legacyEnabledPath = $this->nginxLegacyEnabledVhostPath($domain);
        @file_put_contents($confPath, $this->buildNginxVhostConfig($domain, $rootPath, $phpVersion));
        @chmod($confPath, 0644);

        if ($legacyEnabledPath !== $enabledPath && (is_link($legacyEnabledPath) || is_file($legacyEnabledPath))) {
            @unlink($legacyEnabledPath);
        }
        if ($legacyConfPath !== $confPath && is_file($legacyConfPath)) {
            @unlink($legacyConfPath);
        }

        if ($oldDomain !== null) {
            $normalizedOldDomain = $this->normalizeDomain($oldDomain);
            if ($normalizedOldDomain !== '' && $normalizedOldDomain !== $domain) {
                $this->removeLiveNginxVhost($normalizedOldDomain);
            }
        }

        $currentLinkTarget = is_link($enabledPath) ? (string) @readlink($enabledPath) : '';
        if (! is_link($enabledPath) || $currentLinkTarget !== $confPath) {
            @unlink($enabledPath);
            @symlink($confPath, $enabledPath);
        }
        $this->runSystemCommand('systemctl enable nginx >/dev/null 2>&1 || true');
        $this->runSystemCommand('nginx -t && (systemctl reload nginx || systemctl restart nginx || systemctl start nginx)');
    }

    private function removeLiveNginxVhost(string $domain): void
    {
        if (! $this->canManageNginxVhosts()) {
            return;
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return;
        }

        foreach ($this->nginxEnabledVhostPaths($domain) as $enabledPath) {
            if (is_link($enabledPath) || is_file($enabledPath)) {
                @unlink($enabledPath);
            }
        }

        foreach ($this->nginxVhostPaths($domain) as $confPath) {
            if (is_file($confPath)) {
                @unlink($confPath);
            }
        }

        $this->runSystemCommand('systemctl enable nginx >/dev/null 2>&1 || true');
        $this->runSystemCommand('nginx -t && (systemctl reload nginx || systemctl restart nginx || systemctl start nginx)');
    }

    private function nginxVhostPath(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);

        return '/etc/nginx/sites-available/'.$this->vhostFileBaseName($domain).'.conf';
    }

    private function nginxEnabledVhostPath(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);

        return '/etc/nginx/sites-enabled/'.$this->vhostFileBaseName($domain).'.conf';
    }

    private function nginxLegacyVhostPath(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);

        return '/etc/nginx/sites-available/'.$domain.'.conf';
    }

    private function nginxLegacyEnabledVhostPath(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);

        return '/etc/nginx/sites-enabled/'.$domain.'.conf';
    }

    /**
     * @return array<int, string>
     */
    private function nginxVhostPaths(string $domain): array
    {
        $primaryPath = $this->nginxVhostPath($domain);
        $legacyPath = $this->nginxLegacyVhostPath($domain);

        if ($legacyPath === $primaryPath) {
            return [$primaryPath];
        }

        return [$primaryPath, $legacyPath];
    }

    /**
     * @return array<int, string>
     */
    private function nginxEnabledVhostPaths(string $domain): array
    {
        $primaryPath = $this->nginxEnabledVhostPath($domain);
        $legacyPath = $this->nginxLegacyEnabledVhostPath($domain);

        if ($legacyPath === $primaryPath) {
            return [$primaryPath];
        }

        return [$primaryPath, $legacyPath];
    }

    private function existingNginxVhostPath(string $domain): string
    {
        foreach ($this->nginxVhostPaths($domain) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $this->nginxVhostPath($domain);
    }

    private function existingNginxEnabledVhostPath(string $domain): string
    {
        foreach ($this->nginxEnabledVhostPaths($domain) as $path) {
            if (is_link($path) || is_file($path)) {
                return $path;
            }
        }

        return $this->nginxEnabledVhostPath($domain);
    }

    private function buildApacheVhostConfig(string $domain, string $rootPath, string $phpVersion): string
    {
        $domain = $this->normalizeDomain($domain);
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        $phpVersion = $this->normalizePhpVersionForSocket($phpVersion);
        $serverAlias = $this->shouldAddWwwAlias($domain) ? "\n    ServerAlias www.{$domain}" : '';
        $backendPort = $this->apacheBackendPort();
        $socketPath = "/run/php/php{$phpVersion}-fpm.sock";
        $logName = $this->vhostLogBaseName($domain);

        return <<<CONF
<VirtualHost *:{$backendPort}>
    ServerName {$domain}{$serverAlias}
    DocumentRoot {$rootPath}

    <Directory {$rootPath}>
        AllowOverride All
        Options FollowSymLinks
        Require all granted

        <IfModule mod_dir.c>
            FallbackResource /index.php
        </IfModule>
    </Directory>

    DirectoryIndex index.php index.html index.htm

    <FilesMatch \\.php$>
        SetHandler "proxy:unix:{$socketPath}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/{$logName}_error.log
    CustomLog \${APACHE_LOG_DIR}/{$logName}_access.log combined
</VirtualHost>

CONF;
    }

    private function buildNginxVhostConfig(string $domain, string $rootPath, string $phpVersion): string
    {
        $domain = $this->normalizeDomain($domain);
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        if ($rootPath === '') {
            $rootPath = '/var/www/html';
        }
        $listenPort = $this->nginxPrimaryPort();
        $backendPort = $this->apacheBackendPort();
        $logName = $this->vhostLogBaseName($domain);
        $serverAlias = $this->shouldAddWwwAlias($domain) ? " www.{$domain}" : '';

        return <<<CONF
server {
    listen {$listenPort};
    listen [::]:{$listenPort};
    server_name {$domain}{$serverAlias};
    root {$rootPath};
    index index.php index.html index.htm;

    access_log /var/log/nginx/{$logName}_access.log;
    error_log /var/log/nginx/{$logName}_error.log;

    location ^~ /.well-known/acme-challenge/ {
        allow all;
        try_files \$uri @apache;
    }

    location ~ /\\. {
        deny all;
    }

    location ~* \\.(?:css|js|mjs|map|jpg|jpeg|gif|png|webp|svg|ico|ttf|otf|woff|woff2|eot|mp4|webm|ogg|mp3|wav|pdf|txt|xml|json|webmanifest)\$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, max-age=2592000, immutable";
        try_files \$uri @apache;
    }

    location ~ \\.php(?:\$|/) {
        proxy_pass http://127.0.0.1:{$backendPort};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_next_upstream error timeout http_502 http_503 http_504;
    }

    location / {
        proxy_pass http://127.0.0.1:{$backendPort};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_next_upstream error timeout http_502 http_503 http_504;
    }

    location @apache {
        proxy_pass http://127.0.0.1:{$backendPort};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_next_upstream error timeout http_502 http_503 http_504;
    }
}

CONF;
    }

    /**
     * @param array<string, mixed> $website
     * @return array<string, mixed>
     */
    private function buildVhostPreview(array $website): array
    {
        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));
        $rootPath = (string) ($website['root_path'] ?? '');
        $phpVersion = (string) ($website['php_version'] ?? '8.0');

        if ($domain === '' || $rootPath === '') {
            return [
                'apache' => [
                    'path' => '',
                    'exists' => false,
                    'source' => 'missing website data',
                    'content' => '',
                ],
                'nginx' => [
                    'path' => '',
                    'exists' => false,
                    'source' => 'missing website data',
                    'content' => '',
                ],
            ];
        }

        $apachePath = $this->existingApacheVhostPath($domain);
        $nginxPath = $this->existingNginxVhostPath($domain);

        $apacheExists = is_file($apachePath);
        $nginxExists = is_file($nginxPath);

        $apacheContent = $apacheExists
            ? (string) @file_get_contents($apachePath)
            : $this->buildApacheVhostConfig($domain, $rootPath, $phpVersion);
        $nginxContent = $nginxExists
            ? (string) @file_get_contents($nginxPath)
            : $this->buildNginxVhostConfig($domain, $rootPath, $phpVersion);

        return [
            'apache' => [
                'path' => $apachePath,
                'exists' => $apacheExists,
                'source' => $apacheExists ? 'loaded from file' : 'rendered template preview',
                'content' => $apacheContent,
            ],
            'nginx' => [
                'path' => $nginxPath,
                'exists' => $nginxExists,
                'source' => $nginxExists ? 'loaded from file' : 'rendered template preview',
                'content' => $nginxContent,
            ],
        ];
    }

    private function normalizePhpVersionForSocket(string $phpVersion): string
    {
        $normalized = trim($phpVersion);
        if (preg_match('/^\d+\.\d+$/', $normalized) !== 1) {
            return '8.0';
        }

        return $normalized;
    }

    private function apacheBackendPort(): int
    {
        return $this->normalizePort(config('app.apache_backend_port'), self::DEFAULT_APACHE_BACKEND_PORT);
    }

    private function panelPort(): int
    {
        return $this->normalizePort(config('app.panel_port'), 8090);
    }

    private function nginxPrimaryPort(): int
    {
        return $this->normalizePort(config('app.nginx_primary_port'), self::DEFAULT_NGINX_PRIMARY_PORT);
    }

    private function phpMyAdminPort(): int
    {
        return $this->normalizePort(config('app.phpmyadmin_port'), 8090);
    }

    private function redisServiceUnit(): string
    {
        $configured = strtolower(trim((string) config('app.redis_service', 'auto')));
        if ($configured === '' || $configured === 'auto') {
            return 'auto';
        }

        return preg_match('/^[a-z0-9_.@-]+$/', $configured) === 1 ? $configured : 'auto';
    }

    private function normalizePort(mixed $value, int $fallback): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ((is_string($value) || is_int($value)) && preg_match('/^\d+$/', (string) $value) === 1) {
            $port = (int) $value;
            if ($port >= 1 && $port <= 65535) {
                return $port;
            }
        }

        return $fallback;
    }

    private function vhostTokenFromDomain(string $domain): string
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        $token = strtolower((string) preg_replace('/[^a-z0-9.-]+/', '-', $normalizedDomain));
        $token = trim($token, '-');

        return $token !== '' ? $token : 'site';
    }

    private function vhostFileBaseName(string $domain): string
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        $token = $this->vhostTokenFromDomain($normalizedDomain);
        $hash = substr(sha1($normalizedDomain), 0, 12);
        if (strlen($token) > 110) {
            $token = substr($token, 0, 110);
        }

        return $token.'-'.$hash;
    }

    private function vhostLogBaseName(string $domain): string
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        $token = $this->vhostTokenFromDomain($normalizedDomain);
        $hash = substr(sha1($normalizedDomain), 0, 12);
        if (strlen($token) > 52) {
            $token = substr($token, 0, 52);
        }

        return $token.'-'.$hash;
    }

    private function shouldAddWwwAlias(string $domain): bool
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

    private function canManageLinuxWebServerFiles(): bool
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return false;
        }

        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return false;
        }

        return true;
    }

    private function canManageApacheVhosts(): bool
    {
        return $this->canManageLinuxWebServerFiles() && is_dir('/etc/apache2/sites-available');
    }

    private function canManageNginxVhosts(): bool
    {
        return $this->canManageLinuxWebServerFiles()
            && is_dir('/etc/nginx/sites-available')
            && is_dir('/etc/nginx/sites-enabled');
    }

    /**
     * @param array<string, mixed> $website
     * @return array<string, int|float|string|null>
     */
    private function safeBuildDynamicMetrics(array $website): array
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
     * @param array<string, mixed> $website
     * @return array<string, mixed>
     */
    private function inspectWebsiteSslStatus(array $website): array
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

    private function autoRenewWebsiteSslIfNeeded(array $website): ?string
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

        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 'SSL auto-renew is disabled on Windows servers.';
        }

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

        $this->syncLiveWebVhost(
            $domain,
            $rootPath,
            (string) ($website['php_version'] ?? '8.0'),
            (string) ($website['domain'] ?? ''),
        );

        return 'SSL auto-renew completed successfully.';
    }

    /**
     * @param array<string, mixed> $website
     * @return array<string, int|float|string|null>
     */
    private function buildDynamicMetrics(array $website): array
    {
        $basePath = $this->resolveFileManagerBasePath($website);
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
     * @param array<string, int|float|string|null> $currentMetrics
     * @return array<string, array<int, array<string, int|float|string>>>
     */
    private function buildDynamicHistories(string $websiteId, array $currentMetrics): array
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
    private function readWebsiteMetricsHistory(string $websiteId): array
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
     * @param array<int, array<string, int|float|string>> $history
     */
    private function writeWebsiteMetricsHistory(string $websiteId, array $history): void
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

    private function websiteMetricsHistoryPath(string $websiteId): string
    {
        $token = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($websiteId)) ?? '';
        if ($token === '') {
            $token = substr(sha1($websiteId), 0, 20);
        }

        return storage_path(self::WEBSITE_USAGE_HISTORY_DIR.'/'.$token.'.json');
    }

    private function cleanupWebsiteMetricsHistoryFiles(): void
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

    /**
     * @param array<string, bool> $validWebsiteIdMap
     */
    private function shouldDeleteWebsiteMetricsHistoryFile(string $fullPath, array $validWebsiteIdMap, Carbon $staleCutoff): bool
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
    private function readRequests(): array
    {
        return Website::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Website $website): array => $this->websiteModelToArray($website))
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     */
    private function writeRequests(array $requests): void
    {
        $this->persistRequestsToDatabase($requests);
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     */
    private function persistRequestsToDatabase(array $requests): void
    {
        $rows = collect($requests)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): array {
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    $id = (string) str()->uuid();
                }

                $domain = $this->normalizeDomain((string) ($row['domain'] ?? ''));
                $rootPath = $domain !== ''
                    ? $this->normalizeRootPath((string) ($row['root_path'] ?? ''), $domain)
                    : '';
                $siteOwner = $rootPath !== ''
                    ? $this->extractSiteOwnerFromRootPath($rootPath)
                    : (isset($row['site_owner']) ? (string) $row['site_owner'] : null);
                $createdAt = $this->normalizeDatabaseDatetime((string) ($row['created_at'] ?? ''));
                $updatedAt = $this->normalizeDatabaseDatetime((string) ($row['updated_at'] ?? ''), $createdAt);

                return [
                    'id' => $id,
                    'domain' => $domain,
                    'root_path' => $rootPath,
                    'site_owner' => $siteOwner,
                    'php_version' => (string) ($row['php_version'] ?? ''),
                    'app_installer' => strtolower(trim((string) ($row['app_installer'] ?? 'none'))) ?: 'none',
                    'wordpress_version' => $this->normalizeWordPressVersion((string) ($row['wordpress_version'] ?? 'latest')),
                    'enable_ssl' => (bool) ($row['enable_ssl'] ?? false),
                    'assigned_user_id' => isset($row['assigned_user_id']) && $row['assigned_user_id'] !== '' ? (int) $row['assigned_user_id'] : null,
                    'assigned_reseller_id' => isset($row['assigned_reseller_id']) && $row['assigned_reseller_id'] !== '' ? (int) $row['assigned_reseller_id'] : null,
                    'command' => isset($row['command']) ? (string) $row['command'] : null,
                    'status' => (string) ($row['status'] ?? 'pending'),
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
            })
            ->filter(fn (array $row): bool => trim($row['domain']) !== '')
            ->reverse()
            ->unique(fn (array $row): string => strtolower(trim((string) $row['domain'])))
            ->reverse()
            ->values();

        DB::transaction(function () use ($rows): void {
            if ($rows->isEmpty()) {
                Website::query()->delete();
                return;
            }

            Website::query()->upsert(
                $rows->all(),
                ['id'],
                [
                    'domain',
                    'root_path',
                    'site_owner',
                    'php_version',
                    'app_installer',
                    'wordpress_version',
                    'enable_ssl',
                    'assigned_user_id',
                    'assigned_reseller_id',
                    'command',
                    'status',
                    'created_at',
                    'updated_at',
                ],
            );

            $ids = $rows->pluck('id')->all();
            Website::query()->whereNotIn('id', $ids)->delete();
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function websiteModelToArray(Website $website): array
    {
        $domain = $this->normalizeDomain((string) ($website->domain ?? ''));
        $rootPath = $domain !== ''
            ? $this->normalizeRootPath((string) ($website->root_path ?? ''), $domain)
            : '';
        $siteOwner = $rootPath !== ''
            ? $this->extractSiteOwnerFromRootPath($rootPath)
            : ($website->site_owner !== null ? (string) $website->site_owner : null);
        $storedStatus = (string) ($website->status ?? 'pending');
        $status = $this->detectRuntimeStatus([
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
            'root_path' => $rootPath,
            'site_owner' => $siteOwner,
            'php_version' => (string) ($website->php_version ?? ''),
            'app_installer' => strtolower(trim((string) ($website->app_installer ?? 'none'))) ?: 'none',
            'wordpress_version' => $this->normalizeWordPressVersion((string) ($website->wordpress_version ?? 'latest')),
            'enable_ssl' => (bool) ($website->enable_ssl ?? false),
            'assigned_user_id' => $website->assigned_user_id,
            'assigned_reseller_id' => $website->assigned_reseller_id,
            'command' => $website->command,
            'status' => $status,
            'created_at' => $website->created_at?->toIso8601String(),
            'updated_at' => $website->updated_at?->toIso8601String(),
        ];
    }

    private function normalizeDatabaseDatetime(string $value, ?string $fallback = null): string
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
    private function scanWebsiteFilesystemStats(string $basePath): array
    {
        $sizeBytes = 0;
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
                    $sizeBytes += $item->getSize();
                }
            }
        } catch (\Throwable $e) {
            // Keep metrics available even if scanning fails.
        }

        return [
            'size_bytes' => $sizeBytes,
            'file_count' => $fileCount,
            'last_modified_at' => $latestMtime ? date('c', (int) $latestMtime) : null,
        ];
    }

    private function detectRuntimeStatus(array $website): string
    {
        $storedStatus = strtolower(trim((string) ($website['status'] ?? 'pending')));
        if ($storedStatus === 'disabled') {
            return 'disabled';
        }

        $rootPath = (string) ($website['root_path'] ?? '');
        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));

        $hasRoot = $rootPath !== '' && is_dir($rootPath);
        $hasApacheVhost = $this->apacheVhostExists($domain);
        $hasNginxVhost = $this->nginxVhostExists($domain);
        $hasEntryFile = $hasRoot && (
            is_file(rtrim($rootPath, '/').'/index.php')
            || is_file(rtrim($rootPath, '/').'/index.html')
        );

        if ($hasApacheVhost || $hasNginxVhost) {
            return 'live';
        }
        if ($hasRoot && $hasEntryFile) {
            return 'live';
        }
        if ($hasRoot) {
            return 'partial';
        }

        return (string) ($website['status'] ?? 'pending');
    }

    private function apacheVhostExists(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return false;
        }

        foreach ($this->apacheVhostPaths($domain) as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    private function nginxVhostExists(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return false;
        }

        foreach ($this->nginxVhostPaths($domain) as $path) {
            if (is_file($path)) {
                return true;
            }
        }
        foreach ($this->nginxEnabledVhostPaths($domain) as $path) {
            if (is_link($path) || is_file($path)) {
                return true;
            }
        }

        return false;
    }

    private function detectServiceStatusForWebsitePage(string $linuxService, string $windowsService): string
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            $service = trim($windowsService);
            if ($service === '') {
                return 'unknown';
            }

            $output = (string) @shell_exec('sc query '.escapeshellarg($service).' 2>&1');
            $normalized = strtoupper($output);
            if (str_contains($normalized, 'RUNNING')) {
                return 'running';
            }
            if (str_contains($normalized, 'STOPPED')) {
                return 'stopped';
            }

            return 'unknown';
        }

        $service = trim($linuxService);
        if ($service === '') {
            return 'unknown';
        }

        $output = trim((string) @shell_exec('systemctl is-active '.escapeshellarg($service).' 2>/dev/null'));
        if ($output === '') {
            return 'unknown';
        }

        return strtolower($output);
    }

    private function countWebsiteCronJobs(string $websiteId): int
    {
        return (int) CronJob::query()
            ->where('website_id', $websiteId)
            ->where('status', 'active')
            ->count();
    }

    private function countWebsiteDatabases(string $domain): int
    {
        $normalized = strtolower(trim($domain));

        return (int) DatabaseRequest::query()
            ->whereRaw('LOWER(domain) = ?', [$normalized])
            ->count();
    }

    private function countActiveConnections(string $domain): int
    {
        if ($domain === '') {
            return 0;
        }

        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
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

    private function currentCpuUsagePercent(): float
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 0.0;
        }

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

    private function currentRamUsageMb(): int
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 0;
        }

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
    private function getPhpVersionsForWebsites(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable(self::PHP_SETTINGS_TABLE)) {
                return array_values(array_unique(array_merge(['latest'], self::FALLBACK_PHP_VERSIONS)));
            }

            $row = DB::table(self::PHP_SETTINGS_TABLE)
                ->where('setting_key', self::PHP_STATE_KEY)
                ->first();

            if ($row === null || ! isset($row->setting_value) || ! is_string($row->setting_value) || trim($row->setting_value) === '') {
                DB::table(self::PHP_SETTINGS_TABLE)->updateOrInsert(
                    ['setting_key' => self::PHP_STATE_KEY],
                    [
                        'setting_value' => json_encode(['versions' => self::FALLBACK_PHP_VERSIONS], JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $row = DB::table(self::PHP_SETTINGS_TABLE)
                    ->where('setting_key', self::PHP_STATE_KEY)
                    ->first();
            }

            $decoded = json_decode((string) ($row->setting_value ?? ''), true);
            $versions = collect((array) ($decoded['versions'] ?? []))
                ->map(fn ($version): string => trim((string) $version))
                ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
                ->unique()
                ->sort(fn ($a, $b) => version_compare($b, $a))
                ->values()
                ->all();

            $usedVersions = Website::query()
                ->select('php_version')
                ->whereNotNull('php_version')
                ->get()
                ->map(fn ($item): string => trim((string) ($item->php_version ?? '')))
                ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
                ->unique()
                ->values()
                ->all();

            $merged = collect([...$versions, ...$usedVersions])
                ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
                ->unique()
                ->sort(fn ($a, $b) => version_compare($b, $a))
                ->values()
                ->all();

            $versions = count($merged) > 0 ? $merged : self::FALLBACK_PHP_VERSIONS;

            return array_values(array_unique(array_merge(['latest'], $versions)));
        } catch (\Throwable $e) {
            return array_values(array_unique(array_merge(['latest'], self::FALLBACK_PHP_VERSIONS)));
        }
    }

    private function normalizeWebsitePhpVersion(string $phpVersion): string
    {
        $normalized = strtolower(trim($phpVersion));
        if ($normalized === '' || $normalized === 'latest') {
            $versions = collect($this->getPhpVersionsForWebsites())
                ->map(fn (string $version): string => trim($version))
                ->filter(fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1)
                ->values()
                ->all();

            return (string) ($versions[0] ?? '8.0');
        }

        if (preg_match('/^\d+\.\d+$/', $normalized) === 1) {
            return $normalized;
        }

        return '8.0';
    }

    private function resolveFileManagerBasePath(array $website): string
    {
        $configured = (string) ($website['root_path'] ?? '');
        $domain = (string) ($website['domain'] ?? 'site');
        $resolvedRoot = $this->normalizeRootPath($configured, $domain);
        $resolvedRoot = str_replace('\\', '/', trim($resolvedRoot));

        if ($resolvedRoot === '') {
            abort(422, 'Website root path is missing. Please set a valid root path first.');
        }

        if (! is_dir($resolvedRoot) && ! @mkdir($resolvedRoot, 0755, true) && ! is_dir($resolvedRoot)) {
            abort(422, 'Website root path is not accessible: '.str_replace('\\', '/', $resolvedRoot));
        }

        return str_replace('\\', '/', rtrim($resolvedRoot, '/'));
    }

    private function sanitizeRelativePath(string $path): string
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

    private function resolvePathInsideBase(string $basePath, string $relative): string
    {
        $relative = $this->sanitizeRelativePath($relative);
        $full = rtrim($basePath, '/').($relative !== '' ? '/'.$relative : '');

        return str_replace('\\', '/', $full);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listDirectoryItems(string $basePath, string $directory, bool $showHidden = false): array
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
    private function buildDirectoryTree(string $basePath, string $relative, int $depth, bool $showHidden, string $activePath = ''): array
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
    private function readSelectedFile(string $basePath, string $fileRelative): ?array
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

    private function deleteDirectoryRecursive(string $directory): void
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

    private function applyPermissionsRecursively(string $path, int $mode): bool
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

    private function sanitizeFilename(string $filename): string
    {
        $filename = trim(str_replace(['\\', '/'], '-', $filename));
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?? '';

        return trim($filename, '-.');
    }

    private function formatPermissions(string $path): string
    {
        $perms = @fileperms($path);
        if ($perms === false) {
            return '-';
        }

        return substr(sprintf('%o', $perms), -4);
    }

    private function isSafeZipEntryPath(string $entryPath): bool
    {
        $entryPath = str_replace('\\', '/', trim($entryPath));
        if ($entryPath === '') {
            return false;
        }

        if (str_starts_with($entryPath, '/')) {
            return false;
        }

        if (preg_match('/^[a-zA-Z]:\//', $entryPath) === 1) {
            return false;
        }

        foreach (explode('/', $entryPath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' || str_contains($segment, "\0")) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int|bool $openResult
     */
    private function zipOpenErrorMessage($openResult): string
    {
        if ($openResult === true) {
            return 'Failed to open zip file.';
        }

        $map = [
            ZipArchive::ER_EXISTS => 'Zip open failed: file already exists.',
            ZipArchive::ER_INCONS => 'Zip open failed: archive is inconsistent.',
            ZipArchive::ER_INVAL => 'Zip open failed: invalid argument.',
            ZipArchive::ER_MEMORY => 'Zip open failed: memory allocation error.',
            ZipArchive::ER_NOENT => 'Zip open failed: file not found.',
            ZipArchive::ER_NOZIP => 'Zip open failed: invalid zip archive.',
            ZipArchive::ER_OPEN => 'Zip open failed: cannot open file.',
            ZipArchive::ER_READ => 'Zip open failed: read error.',
            ZipArchive::ER_SEEK => 'Zip open failed: seek error.',
        ];

        $status = (int) $openResult;

        return $map[$status] ?? ('Zip open failed with code '.$status.'.');
    }

    private function zipExtensionMissingMessage(): string
    {
        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        return 'PHP zip extension is not installed on server. Run: sudo apt update && sudo apt install -y php'.$version.'-zip php-zip && sudo systemctl restart apache2 php'.$version.'-fpm serverpanel';
    }

    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $prefix): void
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
