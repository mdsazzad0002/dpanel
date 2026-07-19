<?php

namespace App\Services\Filemanager;

use App\Services\ScriptPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FilemanagerService
{
    public function ensureWebsiteFoldersExist(Request $request, string $rootPath, string $projectRoot, string $context = 'create', bool $forceJson = false): RedirectResponse|JsonResponse|null
    {
        $errors = [];
        $created = [];
        $targets = array_values(array_unique(array_filter([
            $this->normalizeAbsolutePath($projectRoot),
            $this->normalizeAbsolutePath($rootPath),
        ], static fn (string $path): bool => $path !== '')));
        $existing = array_fill_keys(array_filter($targets, static fn (string $path): bool => is_dir($path)), true);
        $missing = array_values(array_diff($targets, array_keys($existing)));

        if ($missing !== []) {
            try {
                $this->createDirectoriesViaApi($missing);
            } catch (\Throwable $e) {
                $errors['folder_command'] = $e->getMessage();
            }
        }

        foreach ($targets as $path) {
            if (! is_dir($path)) {
                $errors[$path === $this->normalizeAbsolutePath($projectRoot) ? 'project_root' : 'root_path'] = "Could not create directory: {$path}";

                continue;
            }

            if (! isset($existing[$path])) {
                $created[] = $path;
            }
        }

        if ($errors === []) {
            return null;
        }

        $message = $context === 'update'
            ? 'Website folder check failed during update.'
            : 'Website folder check failed during create.';

        if ($created !== []) {
            $message .= ' Created missing folder(s): '.implode(', ', array_values(array_unique($created))).'.';
        }

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

    /**
     * @param  array<int, string>  $paths
     */
    private function createDirectoriesViaApi(array $paths): void
    {
        $paths = array_values(array_unique(array_filter(array_map(
            fn (string $path): string => $this->normalizeAbsolutePath($path),
            $paths,
        ), static fn (string $path): bool => $path !== '')));

        if ($paths === []) {
            return;
        }

        $result = $this->filemanagerApiRequest('create', ['paths' => $paths]);
        if ($result['success']) {
            return;
        }

        $output = trim((string) $result['output']);
        throw new \RuntimeException($output !== '' ? $output : 'Filemanager command failed.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, output: string}
     */
    private function filemanagerApiRequest(string $operation, array $payload): array
    {
        $baseUrl = trim((string) config('serverpanel.filemanager_api_url', ''));
        if ($baseUrl === '') {
            $scriptUrl = trim((string) config('serverpanel.execution_api_url', ''));
            $baseUrl = preg_replace('#/api/v1/script/run/?$#', '/api/v1/filemanager', $scriptUrl) ?: '';
        }

        if ($baseUrl === '') {
            return ['success' => false, 'output' => 'Filemanager API is not configured.'];
        }

        $token = trim((string) config('serverpanel.execution_api_token', ''));
        $request = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
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
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
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
