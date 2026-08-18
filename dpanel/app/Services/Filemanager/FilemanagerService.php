<?php

namespace App\Services\Filemanager;

use App\Services\ScriptPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FilemanagerService
{
    public function ensureWebsiteFoldersExist(Request $request, string $rootPath, string $projectRoot, string $context = 'create', bool $forceJson = false, ?string $username = null): RedirectResponse|JsonResponse|null
    {
        $targets = array_values(array_unique(array_filter([
            $this->normalizeAbsolutePath($projectRoot),
            $this->normalizeAbsolutePath($rootPath),
        ], static fn (string $path): bool => $path !== '')));

        try {
            $account = $username !== null && trim($username) !== ''
                ? $this->normalizeUsername($username)
                : $this->normalizeUsername(basename($this->normalizeAbsolutePath($projectRoot)));
            $this->createDirectoriesViaApi($targets, $account);
            $this->verifyDirectoriesViaApi($targets);

            return null;
        } catch (\Throwable $e) {
            $errors = ['folder_command' => $e->getMessage()];
        }

        $message = $context === 'update'
            ? 'Website folder check failed during update.'
            : 'Website folder check failed during create.';

        if ($forceJson || $request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => $errors,
            ], 422);
        }

        return back()->withErrors($errors);
    }

    /**
     * Create a cPanel-style home directory for an account.
     *
     * @return array{user_created: bool, home: string, project_root: string, root_path: string, public_html: string}
     */
    public function createAccountHome(string $username, ?string $home = null, string $shell = '/bin/bash', string $siteDirectory = 'public_html'): array
    {
        $username = $this->normalizeUsername($username);
        $home = $home !== null && trim($home) !== '' ? $this->normalizeAbsolutePath($home) : '/home/'.$username;
        $projectRoot = rtrim($home, '/');
        $siteDirectory = $this->normalizeRelativeDirectory($siteDirectory, 'public_html');
        $rootPath = $projectRoot.'/'.$siteDirectory;
        $publicHtml = $projectRoot.'/public_html';
        $result = $this->filemanagerApiRequest('user', [
            'action' => 'create',
            'username' => $username,
            'home' => $projectRoot,
            'shell' => $shell,
            'site_directory' => $siteDirectory,
        ]);

        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to create website account home.');
        }

        return [
            'user_created' => false,
            'home' => $projectRoot,
            'project_root' => $projectRoot,
            'root_path' => $rootPath,
            'public_html' => $publicHtml,
        ];
    }

    public function repositoryScript(string $group, string $need = ''): array|string
    {
        return ScriptPathResolver::resolveScriptPath($group, $need);
    }

    public function writeTextFile(string $username, string $path, string $content, bool $mustExist = false): void
    {
        $username = $this->normalizeUsername($username);
        $path = $this->normalizeAbsolutePath($path);
        if ($path === '') {
            throw new \InvalidArgumentException('File path is required.');
        }

        $result = $this->filemanagerApiRequest('write', [
            'username' => $username,
            'path' => $path,
            'content' => $content,
            'must_exist' => $mustExist,
        ]);
        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to write file through the filemanager API.');
        }
    }

    public function uploadFile(string $username, string $path, string $sourcePath): void
    {
        $username = $this->normalizeUsername($username);
        $path = $this->normalizeAbsolutePath($path);
        if ($path === '' || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new \InvalidArgumentException('A readable upload file and target path are required.');
        }

        $baseUrl = $this->filemanagerApiBaseUrl();
        if ($baseUrl === '') {
            throw new \RuntimeException('Filemanager API is not configured.');
        }

        $stream = @fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open the temporary upload file.');
        }

        try {
            $request = Http::acceptJson()
                ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600));
            $token = trim((string) config('serverpanel.execution_api_token', ''));
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request
                ->attach('upload', $stream, basename($path))
                ->post(rtrim($baseUrl, '/').'/upload', [
                    'username' => $username,
                    'path' => $path,
                ]);
            $json = $response->json();
            $message = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
            if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
                throw new \RuntimeException($message !== '' ? $message : trim((string) $response->body()));
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            fclose($stream);
        }
    }

    public function createDirectory(string $username, string $path): void
    {
        $username = $this->normalizeUsername($username);
        $path = $this->normalizeAbsolutePath($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Folder path is required.');
        }

        $result = $this->filemanagerApiRequest('create', [
            'username' => $username,
            'paths' => [$path],
        ]);
        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to create folder through the filemanager API.');
        }
    }

    /** @return array{current_path:string,items:array<int,array<string,mixed>>,directory_tree:array<int,array<string,mixed>>} */
    public function browseDirectory(string $username, string $basePath, string $path = '', bool $showHidden = false): array
    {
        $result = $this->filemanagerApiRequest('browse', [
            'username' => $this->normalizeUsername($username),
            'base_path' => $this->normalizeAbsolutePath($basePath),
            'path' => trim(str_replace('\\', '/', $path), '/'),
            'show_hidden' => $showHidden,
        ]);
        if (! $result['success']) {
            throw new \RuntimeException($result['output'] !== '' ? $result['output'] : 'Rust filemanager could not list the directory.');
        }

        return [
            'current_path' => (string) ($result['data']['current_path'] ?? ''),
            'items' => array_values((array) ($result['data']['items'] ?? [])),
            'directory_tree' => array_values((array) ($result['data']['directory_tree'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    public function inspectWebsiteApplication(string $username, string $rootPath): array
    {
        $result = $this->filemanagerApiRequest('inspect', ['username' => $this->normalizeUsername($username), 'root_path' => $this->normalizeAbsolutePath($rootPath)]);
        if (! $result['success']) {
            throw new \RuntimeException($result['output'] ?: 'Rust could not inspect the website application.');
        }

        return $result['data'];
    }

    /** @return array{success:bool,output:string,exit_code:int} */
    public function runArtisanCommand(string $username, string $projectPath, string $command): array
    {
        $result = $this->filemanagerApiRequest('artisan', [
            'username' => $this->normalizeUsername($username),
            'project_path' => $this->normalizeAbsolutePath($projectPath),
            'command' => trim($command),
        ], 300);

        return [
            'success' => $result['success'],
            'output' => $result['success'] ? (string) ($result['data']['output'] ?? '') : $result['output'],
            'exit_code' => $result['success'] ? (int) ($result['data']['exit_code'] ?? 0) : 1,
        ];
    }

    /** @return array{content:string,readonly:bool,message:?string,size:int} */
    public function readTextFile(string $username, string $path): array
    {
        $result = $this->filemanagerApiRequest('read', [
            'username' => $this->normalizeUsername($username),
            'path' => $this->normalizeAbsolutePath($path),
        ]);
        if (! $result['success']) {
            throw new \RuntimeException($result['output'] !== '' ? $result['output'] : 'Rust filemanager could not read the file.');
        }

        return [
            'content' => (string) ($result['data']['content'] ?? ''),
            'readonly' => (bool) ($result['data']['readonly'] ?? false),
            'message' => isset($result['data']['message']) ? (string) $result['data']['message'] : null,
            'size' => (int) ($result['data']['size'] ?? 0),
        ];
    }

    public function movePath(string $username, string $source, string $destination): void
    {
        $username = $this->normalizeUsername($username);
        $source = $this->normalizeAbsolutePath($source);
        $destination = $this->normalizeAbsolutePath($destination);
        if ($source === '' || $destination === '') {
            throw new \InvalidArgumentException('Source and destination paths are required.');
        }

        $result = $this->filemanagerApiRequest('move', [
            'username' => $username,
            'source' => $source,
            'destination' => $destination,
        ]);
        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to move path through the filemanager API.');
        }
    }

    public function ensureDirectoryExists(string $username, string $path): void
    {
        $this->createDirectoriesViaApi([$path], $username);
    }

    public function deletePath(string $username, string $path): void
    {
        $username = $this->normalizeUsername($username);
        $path = $this->normalizeAbsolutePath($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Path is required.');
        }

        $result = $this->filemanagerApiRequest('delete', [
            'username' => $username,
            'path' => $path,
        ]);
        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to delete path through the filemanager API.');
        }
    }

    public function changePermissions(string $username, string $path, string $permissions, bool $recursive = false): void
    {
        $username = $this->normalizeUsername($username);
        $path = $this->normalizeAbsolutePath($path);
        $permissions = trim($permissions);
        if ($path === '' || ! preg_match('/^[0-7]{3,4}$/', $permissions)) {
            throw new \InvalidArgumentException('A valid path and permissions mode are required.');
        }

        $result = $this->filemanagerApiRequest('chmod', [
            'username' => $username,
            'path' => $path,
            'mode' => $permissions,
            'recursive' => $recursive,
        ]);
        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to change permissions through the filemanager API.');
        }
    }

    /** @return array{success: bool, output: string} */
    public function fixWebsitePermissions(string $username, string $rootPath): array
    {
        $username = $this->normalizeUsername($username);
        $rootPath = $this->normalizeAbsolutePath($rootPath);
        if ($rootPath === '') {
            throw new \InvalidArgumentException('Website project path is required.');
        }

        return $this->filemanagerApiRequest('fix-permissions', [
            'username' => $username,
            'root_path' => $rootPath,
            'all' => false,
        ]);
    }

    public function unzipFile(string $username, string $path, ?string $destination = null): void
    {
        $username = $this->normalizeUsername($username);
        $path = $this->normalizeAbsolutePath($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Zip path is required.');
        }

        $payload = [
            'username' => $username,
            'path' => $path,
        ];
        $destination = $destination !== null ? $this->normalizeAbsolutePath($destination) : '';
        if ($destination !== '') {
            $payload['destination'] = $destination;
        }

        $result = $this->filemanagerApiRequest(
            'unzip',
            $payload,
            (int) config('serverpanel.execution_api_upload_timeout', 3600)
        );
        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to extract zip through the filemanager API.');
        }
    }

    /** @param array<int, string> $paths */
    public function zipPaths(string $username, array $paths, string $destination): void
    {
        $username = $this->normalizeUsername($username);
        $paths = array_values(array_filter(array_map(
            fn ($path) => $this->normalizeAbsolutePath((string) $path),
            $paths
        ), fn (string $path) => $path !== ''));
        $destination = $this->normalizeAbsolutePath($destination);
        if ($paths === [] || $destination === '') {
            throw new \InvalidArgumentException('Zip source paths and destination are required.');
        }

        $result = $this->filemanagerApiRequest('zip', [
            'username' => $username,
            'paths' => $paths,
            'destination' => $destination,
        ], (int) config('serverpanel.execution_api_upload_timeout', 3600));
        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Failed to create zip through the filemanager API.');
        }
    }

    public function installWordPress(string $username, string $path, string $version = 'latest'): void
    {
        $username = $this->normalizeUsername($username);
        $path = $this->normalizeAbsolutePath($path);
        $result = $this->filemanagerApiRequest('wordpress-install', [
            'username' => $username,
            'path' => $path,
            'version' => trim($version) !== '' ? trim($version) : 'latest',
        ], 300);

        if (! $result['success']) {
            $output = trim((string) $result['output']);
            throw new \RuntimeException($output !== '' ? $output : 'Rust WordPress installation failed.');
        }
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function createDirectoriesViaApi(array $paths, string $username): void
    {
        $paths = array_values(array_unique(array_filter(array_map(
            fn (string $path): string => $this->normalizeAbsolutePath($path),
            $paths,
        ), static fn (string $path): bool => $path !== '')));

        if ($paths === []) {
            return;
        }

        $result = $this->filemanagerApiRequest('create', [
            'username' => $this->normalizeUsername($username),
            'paths' => $paths,
        ]);
        if ($result['success']) {
            return;
        }

        $output = trim((string) $result['output']);
        throw new \RuntimeException($output !== '' ? $output : 'Filemanager command failed.');
    }

    /** @param array<int, string> $paths */
    private function verifyDirectoriesViaApi(array $paths): void
    {
        $result = $this->filemanagerApiRequest('exists', [
            'paths' => $paths,
            'check_file' => false,
        ]);
        if ($result['success']) {
            return;
        }

        $output = trim((string) $result['output']);
        throw new \RuntimeException($output !== '' ? $output : 'Rust filemanager could not verify the website directories.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, output: string, data: array<string, mixed>}
     */
    private function filemanagerApiRequest(string $operation, array $payload, ?int $timeout = null): array
    {
        $baseUrl = $this->filemanagerApiBaseUrl();

        if ($baseUrl === '') {
            return ['success' => false, 'output' => 'Filemanager API is not configured.', 'data' => []];
        }

        $token = trim((string) config('serverpanel.execution_api_token', ''));
        $request = Http::acceptJson()->asJson()->timeout(
            $timeout ?? (int) config('serverpanel.execution_api_timeout', 60)
        );
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post(rtrim($baseUrl, '/').'/'.ltrim($operation, '/'), $payload);
            $json = $response->json();
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';

            return [
                'success' => $response->successful() && (bool) ($json['success'] ?? false),
                'output' => $message !== '' ? $message : trim((string) $response->body()),
                'data' => is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : [],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage(), 'data' => []];
        }
    }

    private function filemanagerApiBaseUrl(): string
    {
        $baseUrl = trim((string) config('serverpanel.filemanager_api_url', ''));
        if ($baseUrl !== '') {
            return $baseUrl;
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/').'/api/v1/filemanager' : '';
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        return $path !== '' ? rtrim($path, '/') : '';
    }

    private function normalizeUsername(string $username): string
    {
        $username = strtolower(trim($username));
        if ($username === '' || preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username) !== 1) {
            throw new \InvalidArgumentException("Invalid username: {$username}");
        }

        return $username;
    }

    private function normalizeRelativeDirectory(string $path, string $fallback): string
    {
        $path = strtolower(trim(str_replace('\\', '/', $path)));
        $path = trim($path, '/');
        $path = (string) preg_replace('/[^a-z0-9._-]+/', '_', $path);
        $path = trim($path, '._-');

        if ($path === '' || $path === '.' || $path === '..') {
            return $fallback;
        }

        return substr($path, 0, 64);
    }
}
