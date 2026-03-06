<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    /**
     * @var array<int, string>
     */
    private const FALLBACK_PHP_VERSIONS = ['8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];
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
        ]);
    }

    /**
     * List created website requests/commands.
     */
    public function index(): Response
    {
        $rawRequests = collect($this->readRequests());
        $assignmentUserIds = $rawRequests
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

        $requests = $rawRequests
            ->map(function (array $item): array {
                $domain = $this->normalizeDomain((string) ($item['domain'] ?? ''));
                if ($domain !== '') {
                    $item['domain'] = $domain;
                    $item['root_path'] = $this->normalizeRootPath((string) ($item['root_path'] ?? ''), $domain);
                    $item['site_owner'] = $this->extractSiteOwnerFromRootPath((string) $item['root_path']);
                }

                if (empty($item['command'])) {
                    $item['command'] = $this->buildCommand([
                        'domain' => $domain,
                        'root_path' => (string) ($item['root_path'] ?? ''),
                        'php_version' => (string) ($item['php_version'] ?? ''),
                        'enable_ssl' => (bool) ($item['enable_ssl'] ?? false),
                    ]);
                }

                return $item;
            })
            ->map(function (array $item) use ($usersById): array {
                $assignedUserId = (int) ($item['assigned_user_id'] ?? 0);
                $assignedResellerId = (int) ($item['assigned_reseller_id'] ?? 0);
                $item['assigned_user_id'] = $assignedUserId > 0 ? $assignedUserId : null;
                $item['assigned_reseller_id'] = $assignedResellerId > 0 ? $assignedResellerId : null;
                $item['assigned_user_name'] = $assignedUserId > 0 ? ($usersById->get($assignedUserId)?->name ?? null) : null;
                $item['assigned_reseller_name'] = $assignedResellerId > 0 ? ($usersById->get($assignedResellerId)?->name ?? null) : null;

                return $item;
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();

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
        $validated['domain'] = $this->normalizeDomain($validated['domain']);
        $domainExists = collect($this->readRequests())
            ->contains(fn (array $item): bool => $this->normalizeDomain((string) ($item['domain'] ?? '')) === $validated['domain']);
        if ($domainExists) {
            return back()->withErrors(['domain' => 'This domain already exists.']);
        }
        $validated['root_path'] = $this->normalizeRootPath((string) ($validated['root_path'] ?? ''), $validated['domain']);
        $validated['site_owner'] = $this->extractSiteOwnerFromRootPath($validated['root_path']);

        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);

        $command = $this->buildCommand($validated);

        // Intentionally disabled: command execution must be manually enabled later.
        try {
            $output = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);
            $this->applyWebsiteFilesystemIsolation($validated['site_owner'], $validated['root_path']);
            $this->initializeWebsiteStarterFiles($validated['root_path'], $validated['domain']);
            $this->relocateApacheDefaultPage();
            $this->syncLiveApacheVhost(
                $validated['domain'],
                $validated['root_path'],
                (string) $validated['php_version'],
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $requests = $this->readRequests();
        $actor = $request->user();
        $defaultResellerId = $actor && $actor->hasRole('reseller') ? (int) $actor->id : null;
        $requests[] = [
            'id' => (string) str()->uuid(),
            'domain' => $validated['domain'],
            'root_path' => $validated['root_path'],
            'site_owner' => $validated['site_owner'],
            'php_version' => $validated['php_version'],
            'enable_ssl' => $validated['enable_ssl'],
            'assigned_user_id' => null,
            'assigned_reseller_id' => $defaultResellerId,
            'command' => $command,
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ];
        $this->writeRequests($requests);

        return redirect()->route('websites.list')->with('success', 'Website request created successfully.');
    }

    /**
     * Edit website request.
     */
    public function edit(string $id): Response
    {
        $requestItem = collect($this->readRequests())->firstWhere('id', $id);

        abort_if($requestItem === null, 404);
        $requestItem = $this->normalizeWebsiteRecord($requestItem);

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
        ]);
    }

    /**
     * Show website management and usage history.
     */
    public function manage(string $id): Response
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);
        $website = $this->normalizeWebsiteRecord($website);

        $metrics = $this->buildDynamicMetrics($website);
        $histories = $this->buildDynamicHistories((string) ($website['id'] ?? $id), $metrics);
        $runtimeStatus = $this->detectRuntimeStatus($website);

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
            [
                'label' => 'Apache VHost',
                'value' => $this->apacheVhostExists((string) ($website['domain'] ?? '')) ? 'configured' : 'not configured',
            ],
            [
                'label' => 'Last File Change',
                'value' => $metrics['last_modified_at'] ?? null,
            ],
        ];

        return Inertia::render('Websites/Manage', [
            'website' => $website,
            'metrics' => $metrics,
            'histories' => $histories,
            'activities' => $activities,
        ]);
    }

    /**
     * Preview website files from dynamic base dir + normalized domain path.
     */
    public function preview(string $id, ?string $path = null): BinaryFileResponse|\Illuminate\Http\Response
    {
        $website = Website::query()->firstWhere('id', $id);
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
        $existingRequest = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($existingRequest === null, 404);

        $validated = $this->validatePayload($request);
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

        $this->applyWebsiteFilesystemIsolation($validated['site_owner'], $validated['root_path']);
        $this->initializeWebsiteStarterFiles($validated['root_path'], $validated['domain']);
        $this->relocateApacheDefaultPage();
        $this->syncLiveApacheVhost(
            $validated['domain'],
            $validated['root_path'],
            (string) $validated['php_version'],
            (string) ($existingRequest['domain'] ?? ''),
        );

        $requests = collect($this->readRequests())->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            $item['domain'] = $validated['domain'];
            $item['root_path'] = $validated['root_path'];
            $item['site_owner'] = $validated['site_owner'];
            $item['php_version'] = $validated['php_version'];
            $item['enable_ssl'] = $validated['enable_ssl'];
            $item['command'] = $this->buildCommand($validated);
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($requests);

        return redirect()->route('websites.list')->with('success', 'Website request updated successfully.');
    }

    /**
     * File manager for website root.
     */
    public function fileManager(Request $request, string $id): Response
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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
        $directoryTree = $this->buildDirectoryTree($basePath, '', 2, $showHidden);

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
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1500'],
            'upload' => ['required', 'file', 'max:10240'],
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
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

        $validated = $request->validate([
            'item_path' => ['required', 'string', 'max:1500'],
            'current_path' => ['nullable', 'string', 'max:1500'],
            'permissions' => ['required', 'string', 'regex:/^[0-7]{3,4}$/'],
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
        if (! @chmod($itemPath, $mode)) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to change permissions.');
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', 'Permissions updated.');
    }

    public function renameItem(Request $request, string $id): RedirectResponse
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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

    public function downloadFile(Request $request, string $id): BinaryFileResponse|RedirectResponse
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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

        $extractTo = dirname($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to open zip file.');
        }

        $ok = $zip->extractTo($extractTo);
        $zip->close();

        if (! $ok) {
            return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('error', 'Failed to extract zip file.');
        }

        return redirect()->route('websites.filemanager', ['id' => $id, 'path' => $currentPath])->with('success', 'Zip extracted successfully.');
    }

    public function deleteItem(Request $request, string $id): RedirectResponse
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

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
        $existingRequest = $requests->firstWhere('id', $id);
        $before = $requests->count();
        $filtered = $requests->reject(fn (array $item) => ($item['id'] ?? null) === $id)->values();

        if ($filtered->count() === $before) {
            return redirect()->route('websites.list')->with('error', 'Website request not found.');
        }

        if (is_array($existingRequest)) {
            $this->removeLiveApacheVhost((string) ($existingRequest['domain'] ?? ''));
        }

        $this->writeRequests($filtered->all());

        return redirect()->route('websites.list')->with('success', 'Website request deleted successfully.');
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
        if ($owner !== $layout['site_owner']) {
            return $layout['root_path'];
        }

        $requestedSiteDir = (string) ($parts[1] ?? '');
        $siteDir = $this->normalizeSiteDirectory($requestedSiteDir, $layout['site_dir']);

        return $homeBase."/{$layout['site_owner']}/{$siteDir}";
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

        return $website;
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
    private function initializeWebsiteStarterFiles(string $rootPath, string $domain): void
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
        $indexPhp = <<<PHP
<?php
echo "<h1>Welcome to {$normalizedDomain}</h1>";
echo "<p>Site created by ServerPanel.</p>";

PHP;

        @file_put_contents(rtrim($rootPath, '/').'/index.php', $indexPhp);
        @file_put_contents(
            rtrim($rootPath, '/').'/index.html',
            "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$normalizedDomain}</title></head><body><h1>{$normalizedDomain}</h1><p>Site is ready.</p></body></html>"
        );

        $extraDir = rtrim($rootPath, '/').'/extra';
        if (! is_dir($extraDir)) {
            @mkdir($extraDir, 0755, true);
        }

        @file_put_contents(
            $extraDir.'/first-site-note.txt',
            "This folder was created on first website creation.\nDomain: {$normalizedDomain}\nCreated at: ".now()->toDateTimeString()."\n"
        );
    }

    /**
     * Move default Apache page to extra directory once.
     */
    private function relocateApacheDefaultPage(): void
    {
        if ($this->cannotManageLiveWebServer()) {
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

    private function syncLiveApacheVhost(string $domain, string $rootPath, string $phpVersion, ?string $oldDomain = null): void
    {
        if ($this->cannotManageLiveWebServer()) {
            return;
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return;
        }

        $confPath = $this->apacheVhostPath($domain);
        @file_put_contents($confPath, $this->buildApacheVhostConfig($domain, $rootPath, $phpVersion));

        if ($oldDomain !== null) {
            $normalizedOldDomain = $this->normalizeDomain($oldDomain);
            if ($normalizedOldDomain !== '' && $normalizedOldDomain !== $domain) {
                $this->removeLiveApacheVhost($normalizedOldDomain);
            }
        }

        $confName = basename($confPath);
        $this->runSystemCommand('a2ensite '.escapeshellarg($confName));
        $this->runSystemCommand('apache2ctl -t && systemctl reload apache2');
    }

    private function removeLiveApacheVhost(string $domain): void
    {
        if ($this->cannotManageLiveWebServer()) {
            return;
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return;
        }

        $confPath = $this->apacheVhostPath($domain);
        $confName = basename($confPath);

        if (is_file($confPath)) {
            $this->runSystemCommand('a2dissite '.escapeshellarg($confName));
            @unlink($confPath);
            $this->runSystemCommand('apache2ctl -t && systemctl reload apache2');
        }
    }

    private function apacheVhostPath(string $domain): string
    {
        return '/etc/apache2/sites-available/'.$domain.'.conf';
    }

    private function buildApacheVhostConfig(string $domain, string $rootPath, string $phpVersion): string
    {
        $domain = $this->normalizeDomain($domain);
        $rootPath = trim(str_replace('\\', '/', $rootPath));
        $phpVersion = $this->normalizePhpVersionForSocket($phpVersion);
        $serverAlias = $this->shouldAddWwwAlias($domain) ? "\n    ServerAlias www.{$domain}" : '';
        $socketPath = "/run/php/php{$phpVersion}-fpm.sock";

        return <<<CONF
<VirtualHost *:80>
    ServerName {$domain}{$serverAlias}
    DocumentRoot {$rootPath}

    <Directory {$rootPath}>
        AllowOverride All
        Require all granted
    </Directory>

    DirectoryIndex index.php index.html

    <FilesMatch \\.php$>
        SetHandler "proxy:unix:{$socketPath}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/{$domain}_error.log
    CustomLog \${APACHE_LOG_DIR}/{$domain}_access.log combined
</VirtualHost>

CONF;
    }

    private function normalizePhpVersionForSocket(string $phpVersion): string
    {
        $normalized = trim($phpVersion);
        if (preg_match('/^\d+\.\d+$/', $normalized) !== 1) {
            return '8.3';
        }

        return $normalized;
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

    private function cannotManageLiveWebServer(): bool
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return true;
        }

        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return true;
        }

        return ! is_dir('/etc/apache2/sites-available');
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
        if (! DB::getSchemaBuilder()->hasTable(self::WEBSITE_METRICS_TABLE)) {
            return [
                'points' => [[
                    'time' => now()->format('H:i'),
                    'connections' => (int) ($currentMetrics['connections_current'] ?? 0),
                    'jobs' => (int) ($currentMetrics['jobs_pending'] ?? 0),
                    'databases' => (int) ($currentMetrics['databases_count'] ?? 0),
                    'disk' => (float) ($currentMetrics['disk_used_mb'] ?? 0),
                    'cpu' => (float) ($currentMetrics['cpu_usage_percent'] ?? 0),
                    'ram' => (int) ($currentMetrics['ram_usage_mb'] ?? 0),
                ]],
            ];
        }

        DB::table(self::WEBSITE_METRICS_TABLE)->insert([
            'website_id' => $websiteId,
            'connections' => (int) ($currentMetrics['connections_current'] ?? 0),
            'jobs' => (int) ($currentMetrics['jobs_pending'] ?? 0),
            'databases' => (int) ($currentMetrics['databases_count'] ?? 0),
            'disk' => (float) ($currentMetrics['disk_used_mb'] ?? 0),
            'cpu' => (float) ($currentMetrics['cpu_usage_percent'] ?? 0),
            'ram' => (int) ($currentMetrics['ram_usage_mb'] ?? 0),
            'captured_at' => now()->format('Y-m-d H:i:s'),
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $points = DB::table(self::WEBSITE_METRICS_TABLE)
            ->where('website_id', $websiteId)
            ->orderByDesc('captured_at')
            ->limit(24)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($row): array {
                return [
                    'time' => Carbon::parse((string) $row->captured_at)->format('H:i'),
                    'connections' => (int) ($row->connections ?? 0),
                    'jobs' => (int) ($row->jobs ?? 0),
                    'databases' => (int) ($row->databases ?? 0),
                    'disk' => (float) ($row->disk ?? 0),
                    'cpu' => (float) ($row->cpu ?? 0),
                    'ram' => (int) ($row->ram ?? 0),
                ];
            })
            ->all();

        return [
            'points' => $points,
        ];
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

                $createdAt = $this->normalizeDatabaseDatetime((string) ($row['created_at'] ?? ''));
                $updatedAt = $this->normalizeDatabaseDatetime((string) ($row['updated_at'] ?? ''), $createdAt);

                return [
                    'id' => $id,
                    'domain' => (string) ($row['domain'] ?? ''),
                    'root_path' => '',
                    'site_owner' => isset($row['site_owner']) ? (string) $row['site_owner'] : null,
                    'php_version' => (string) ($row['php_version'] ?? ''),
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
        $rootPath = $domain !== '' ? $this->normalizeRootPath('', $domain) : '';

        return [
            'id' => (string) $website->id,
            'domain' => $domain,
            'root_path' => $rootPath,
            'site_owner' => $website->site_owner,
            'php_version' => (string) ($website->php_version ?? ''),
            'enable_ssl' => (bool) ($website->enable_ssl ?? false),
            'assigned_user_id' => $website->assigned_user_id,
            'assigned_reseller_id' => $website->assigned_reseller_id,
            'command' => $website->command,
            'status' => (string) ($website->status ?? 'pending'),
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
        $rootPath = (string) ($website['root_path'] ?? '');
        $domain = $this->normalizeDomain((string) ($website['domain'] ?? ''));

        $hasRoot = $rootPath !== '' && is_dir($rootPath);
        $hasVhost = $this->apacheVhostExists($domain);

        if ($hasRoot && $hasVhost) {
            return 'live';
        }
        if ($hasRoot || $hasVhost) {
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

        return is_file($this->apacheVhostPath($domain));
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
                return self::FALLBACK_PHP_VERSIONS;
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

            return count($merged) > 0 ? $merged : self::FALLBACK_PHP_VERSIONS;
        } catch (\Throwable $e) {
            return self::FALLBACK_PHP_VERSIONS;
        }
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
    private function buildDirectoryTree(string $basePath, string $relative, int $depth, bool $showHidden): array
    {
        if ($depth < 0) {
            return [];
        }

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

            $tree[] = [
                'name' => $entry,
                'path' => $childRelative,
                'children' => $depth > 0 ? $this->buildDirectoryTree($basePath, $childRelative, $depth - 1, $showHidden) : [],
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
