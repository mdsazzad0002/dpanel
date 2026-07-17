<?php

namespace App\Services\Filemanager;

use App\Services\ScriptPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
                $this->createDirectoriesByCommand($missing);
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
        $this->createDirectoriesByCommand(array_values(array_unique([$projectRoot, $rootPath, $publicHtml])));

        return [
            'user_created' => false,
            'home' => $projectRoot,
            'project_root' => $projectRoot,
            'root_path' => $rootPath,
            'public_html' => $publicHtml,
        ];
    }

    public function repositoryRoot(): string
    {
        return ScriptPathResolver::resolveRepositoryRoot();
    }

    public function repositoryScript(string $group, string $need = ''): array|string
    {
        return ScriptPathResolver::resolveScriptPath($group, $need);
    }

    private function ensureDirectory(string $path, string $missingMessage, array &$created, array &$errors, string $errorKey): void
    {
        $path = $this->normalizeAbsolutePath($path);

        if ($path === '') {
            $errors[$errorKey] = $missingMessage;
            return;
        }

        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0750, true) && ! is_dir($path)) {
            $errors[$errorKey] = "Could not create directory: {$path}";
            return;
        }

        $created[] = $path;
    }

    /**
     * @param array<int, string> $paths
     */
    private function createDirectoriesByCommand(array $paths): void
    {
        $paths = array_values(array_unique(array_filter(array_map(
            fn (string $path): string => $this->normalizeAbsolutePath($path),
            $paths,
        ), static fn (string $path): bool => $path !== '')));

        if ($paths === []) {
            return;
        }

        $scriptPath = $this->filemanagerScriptPath();
        $command = implode(' ', array_map('escapeshellarg', array_merge(
            [$scriptPath, 'install', 'create'],
            $paths,
        )));

        $this->runSystemCommand($command);
    }

    private function filemanagerScriptPath(): string
    {
        $scriptPath = $this->repositoryRoot().DIRECTORY_SEPARATOR.'repository'.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'filemanager'.DIRECTORY_SEPARATOR.'install.sh';

        if (! is_file($scriptPath)) {
            throw new \RuntimeException('Filemanager command script is missing: '.$scriptPath);
        }

        return $scriptPath;
    }

    private function runSystemCommand(string $command): void
    {
        $uid = (int) trim((string) shell_exec('id -u'));
        if ($uid !== 0) {
            $sudoPath = trim((string) shell_exec('command -v sudo 2>/dev/null'));
            if ($sudoPath !== '') {
                $command = escapeshellarg($sudoPath).' -n '.$command;
            }
        }

        $output = [];
        $exitCode = 1;
        exec($command.' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $message = trim(implode("\n", $output)) !== ''
                ? trim(implode("\n", $output))
                : "Command failed: {$command}";

            if (str_contains(strtolower($message), 'permission denied')) {
                $message .= ' You may need passwordless sudo for the web server user or ownership/write access on the target directory.';
            }

            throw new \RuntimeException($message);
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
