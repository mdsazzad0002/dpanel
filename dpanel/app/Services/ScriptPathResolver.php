<?php

namespace App\Services;

use RuntimeException;

class ScriptPathResolver
{
    private const SERVER_SEARCH_PATHS = [
        '/var/www/dscript',
        '/var/www/panel/dscript',
        '/usr/local/panel/scripts',
        '/root/ServerPanel/dscript',
        '/home/ubuntu/ServerPanel/dscript',
    ];

    /**
     * @return array<int, string>
     */
    public static function repositorySearchPaths(): array
    {
        $configured = config('dpanel.installer_search_paths', []);
        if (! is_array($configured)) {
            $configured = [];
        }

        $fallbacks = [
            dirname(base_path()).'/dscript',
            base_path('dscript'),
        ];
        // On a drive, these would be read as folders of the current drive.
        if (! PathService::isDrivePath(base_path())) {
            $fallbacks = array_merge($fallbacks, self::SERVER_SEARCH_PATHS);
        }

        $paths = array_filter(array_merge($configured, $fallbacks), static fn ($path) => is_string($path) && trim($path) !== '');

        return array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
    }

    public static function resolveRepositoryRoot(): string
    {
        foreach (self::repositorySearchPaths() as $candidate) {
            $candidate = rtrim($candidate, '/');
            if ($candidate !== '' && is_dir($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to locate the dscript repository root.');
    }

    /**
     * Resolve a script or manifest path within the repository.
     *
     * @return array<string, mixed>|array<int, string>|string
     */
    public static function resolveScriptPath(string $group, string $need = ''): array|string
    {
        $scriptName = match ($group) {
            'filemanager' => 'repository/modules/filemanager/filemanager.json',
            'filemanager-install' => 'repository/modules/filemanager/install.sh',
            'fix-web-stack' => 'scripts/fix-web-stack.sh',
            'fix-panel-web-stack' => 'scripts/fix-panel-web-stack.sh',
            'issue-ssl' => 'scripts/issue-ssl.sh',
            'create-admin-user' => 'scripts/create-admin-user.sh',
            'disable-root-login' => 'scripts/disable-root-login.sh',
            'database-request' => 'scripts/database-request.sh',
            default => throw new RuntimeException("Unknown script group: {$group}"),
        };

        $scriptPath = '';
        foreach (self::repositorySearchPaths() as $candidate) {
            $candidate = rtrim((string) $candidate, '/');
            if ($candidate === '') {
                continue;
            }

            $path = $candidate.'/'.$scriptName;
            if (file_exists($path)) {
                $scriptPath = $path;
                break;
            }
        }

        if ($scriptPath === '') {
            throw new RuntimeException("Script not found: {$scriptName}");
        }

        if (in_array($group, ['php', 'filemanager'], true)) {
            $content = file_get_contents($scriptPath);
            $json = json_decode((string) $content, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $need !== '' ? ($json[$need] ?? '') : $json;
            }
        }

        if ($need !== '') {
            return '';
        }

        return $scriptPath;
    }

    public static function runSystemScript(string $path, array $args = []): bool
    {
        return self::runSystemScriptWithOutput($path, $args)['success'];
    }

    /**
     * Execute a script with root privileges when available.
     *
     * This still requires the host to allow passwordless sudo for the web user.
     *
     * @param array<int, string> $args
     * @param array<string, string|int|float|bool|null> $env
     * @return array{success: bool, output: string, exit_code: int, path: string}
     */
    public static function runSystemScriptAsRootWithOutput(string $path, array $args = [], array $env = []): array
    {
        $scriptPath = self::normalizePath($path);

        return app(ScriptExecutionGateway::class)->execute($scriptPath, $args, $env, true);
    }

    /**
     * Execute a script and capture its output.
     *
     * @param array<int, string> $args
     * @param array<string, string|int|float|bool|null> $env
     * @return array{success: bool, output: string, exit_code: int, path: string}
     */
    public static function runSystemScriptWithOutput(string $path, array $args = [], array $env = []): array
    {
        $scriptPath = self::normalizePath($path);

        return app(ScriptExecutionGateway::class)->execute($scriptPath, $args, $env, false);
    }

    private static function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if (is_file($path)) {
            return $path;
        }

        $realpath = realpath($path);
        return $realpath !== false ? str_replace('\\', '/', $realpath) : $path;
    }
}
