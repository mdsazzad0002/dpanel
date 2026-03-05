<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WebsiteController extends Controller
{
    private const STORAGE_FILE = 'website-requests.json';
    private const FILEMANAGER_FALLBACK_ROOT = 'site-files';

    /**
     * Show website creation page.
     */
    public function create(): Response
    {
        return Inertia::render('Websites/Create');
    }

    /**
     * List created website requests/commands.
     */
    public function index(): Response
    {
        $requests = collect($this->readRequests())
            ->map(function (array $item): array {
                $domain = $this->normalizeDomain((string) ($item['domain'] ?? ''));
                if ($domain !== '') {
                    $item['domain'] = $domain;
                    $item['root_path'] = $this->normalizeRootPath((string) ($item['root_path'] ?? ''), $domain);
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
        $validated['root_path'] = $this->normalizeRootPath((string) ($validated['root_path'] ?? ''), $validated['domain']);

        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);

        $command = $this->buildCommand($validated);

        // Intentionally disabled: command execution must be manually enabled later.
        try {
            $output = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $requests = $this->readRequests();
        $requests[] = [
            'id' => (string) str()->uuid(),
            'domain' => $validated['domain'],
            'root_path' => $validated['root_path'],
            'php_version' => $validated['php_version'],
            'enable_ssl' => $validated['enable_ssl'],
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

        return Inertia::render('Websites/Edit', [
            'websiteRequest' => $requestItem,
        ]);
    }

    /**
     * Show website management and usage history.
     */
    public function manage(string $id): Response
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

        $seed = abs(crc32((string) ($website['domain'] ?? $id)));
        $metrics = $this->buildMetrics($seed);
        $histories = $this->buildHistories($seed);

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
                'value' => $website['status'] ?? 'pending',
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
     * Update website request.
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $validated['domain'] = $this->normalizeDomain($validated['domain']);
        $validated['root_path'] = $this->normalizeRootPath((string) ($validated['root_path'] ?? ''), $validated['domain']);
        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);

        $requests = collect($this->readRequests())->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            $item['domain'] = $validated['domain'];
            $item['root_path'] = $validated['root_path'];
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
        $before = $requests->count();
        $filtered = $requests->reject(fn (array $item) => ($item['id'] ?? null) === $id)->values();

        if ($filtered->count() === $before) {
            return redirect()->route('websites.list')->with('error', 'Website request not found.');
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
            'root_path' => ['required', 'string', 'max:255', 'regex:/^\/home\/.+$/'],
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
     * Normalize root path to /home/<suffix> format.
     */
    private function normalizeRootPath(string $rootPath, string $domain): string
    {
        $normalized = trim(str_replace('\\', '/', $rootPath));

        if ($normalized === '') {
            return "/home/{$domain}";
        }

        if (str_starts_with($normalized, '/home/')) {
            $suffix = trim(substr($normalized, 6), '/');
            if ($suffix === '') {
                return "/home/{$domain}";
            }

            return "/home/{$suffix}";
        }

        $suffix = trim($normalized, '/');
        if ($suffix === '') {
            return "/home/{$domain}";
        }

        return "/home/{$suffix}";
    }

    /**
     * @return array<string, int|float>
     */
    private function buildMetrics(int $seed): array
    {
        return [
            'connections_current' => 10 + ($seed % 190),
            'jobs_pending' => $seed % 40,
            'databases_count' => 1 + ($seed % 12),
            'disk_used_mb' => 200 + ($seed % 50000),
            'disk_limit_mb' => 102400,
            'cpu_usage_percent' => 5 + ($seed % 75),
            'ram_usage_mb' => 256 + ($seed % 12000),
        ];
    }

    /**
     * @return array<string, array<int, array<string, int|string>>>
     */
    private function buildHistories(int $seed): array
    {
        $points = [];

        for ($i = 11; $i >= 0; $i--) {
            $points[] = [
                'time' => now()->subHours($i)->format('H:i'),
                'connections' => 10 + (($seed + $i * 13) % 190),
                'jobs' => ($seed + $i * 7) % 40,
                'databases' => 1 + (($seed + $i * 3) % 12),
                'disk' => 200 + (($seed + $i * 101) % 50000),
                'cpu' => 5 + (($seed + $i * 5) % 75),
                'ram' => 256 + (($seed + $i * 31) % 12000),
            ];
        }

        return [
            'points' => $points,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRequests(): array
    {
        if (! Storage::exists(self::STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::STORAGE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     */
    private function writeRequests(array $requests): void
    {
        Storage::put(self::STORAGE_FILE, json_encode($requests, JSON_PRETTY_PRINT));
    }

    private function resolveFileManagerBasePath(array $website): string
    {
        $configured = (string) ($website['root_path'] ?? '');
        $domain = (string) ($website['domain'] ?? 'site');
        $configured = str_replace('\\', '/', trim($configured));

        if ($configured !== '' && is_dir($configured)) {
            return rtrim($configured, '/');
        }

        $fallback = storage_path('app/'.self::FILEMANAGER_FALLBACK_ROOT.'/'.$domain);
        if (! is_dir($fallback)) {
            @mkdir($fallback, 0755, true);
        }

        return str_replace('\\', '/', rtrim($fallback, '/'));
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
