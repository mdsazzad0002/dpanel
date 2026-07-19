<?php

namespace App\Services;

use RuntimeException;

class ScriptPathResolver
{
    /**
     * @return array<int, string>
     */
    public static function repositorySearchPaths(): array
    {
        $configured = config('serverpanel.installer_search_paths', []);
        if (! is_array($configured)) {
            $configured = [];
        }

        $fallbacks = array_filter([
            dirname(base_path()).'/installer/bootstrap/dscript',
            base_path('installer/bootstrap/dscript'),
            dirname(base_path()).'/dscript',
            base_path('dscript'),
            '/var/www/dscript',
            '/var/www/panel/dscript',
            '/usr/local/panel/scripts',
            '/root/ServerPanel/dscript',
            '/home/ubuntu/ServerPanel/dscript',
        ]);

        return array_values(array_unique(array_filter(array_merge($configured, $fallbacks), static fn ($path) => is_string($path) && trim($path) !== '')));
    }

    public static function resolveRepositoryRoot(): string
    {
        foreach (self::repositorySearchPaths() as $candidate) {
            $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
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
            'sync-vhost' => 'scripts/sync-vhost.sh',
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
            $candidate = rtrim(str_replace('\\', '/', (string) $candidate), '/');
            if ($candidate === '') {
                continue;
            }

            $path = $candidate.DIRECTORY_SEPARATOR.$scriptName;
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

    /**
     * @param array<string, string|int|float|bool|null> $env
     * @return array{success: bool, output: string, exit_code: int, path: string}
     */
    private static function runCommandWithOutput(string $command, string $scriptPath, array $env = []): array
    {
        try {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $environment = $_ENV;
            if (! is_array($environment)) {
                $environment = [];
            }
            foreach ($env as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                $environment[$key] = (string) $value;
            }

            $process = proc_open($command, $descriptors, $pipes, null, $environment);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start script process.');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $status = proc_close($process);
            $output = trim((string) $stdout);
            $errorOutput = trim((string) $stderr);
            $combined = trim($output."\n".$errorOutput);

            if ($status !== 0) {
                logger()->error('Script execution failed', [
                    'path' => $scriptPath,
                    'status' => $status,
                    'output' => $combined,
                ]);
            }

            return [
                'success' => $status === 0,
                'output' => $combined,
                'exit_code' => $status,
                'path' => $scriptPath,
            ];
        } catch (\Throwable $e) {
            logger()->error($e->getMessage());

            return [
                'success' => false,
                'output' => $e->getMessage(),
                'exit_code' => 1,
                'path' => self::normalizePath($scriptPath ?? $path ?? ''),
            ];
        }
    }

    private static function commandExists(string $command): bool
    {
        $result = trim((string) shell_exec('command -v '.escapeshellarg($command).' 2>/dev/null'));

        return $result !== '';
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
        return $realpath !== false ? $realpath : $path;
    }
}
