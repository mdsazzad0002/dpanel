<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpCommandExecutor;
use App\Services\Mcp\McpToolException;
use App\Services\PathService;
use App\Support\Platform\LocalCommand;

class ReadLogFileTool implements McpTool
{
    public function __construct(private readonly McpCommandExecutor $executor) {}

    public function name(): string
    {
        return 'read_log_file';
    }

    public function title(): string
    {
        return 'Read log file';
    }

    public function description(): string
    {
        return 'Last lines of a log file under /var/log (e.g. /var/log/mail.log, /var/log/php8.3-fpm.log, /var/log/mysql/error.log), optionally filtered by a case-insensitive pattern. Use "ls /var/log" via run_command to discover files. On a Windows panel host: a drive path under the dPanel log folder (C:/ProgramData/dPanel/logs).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Absolute path under /var/log.'],
                'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 200],
                'grep' => ['type' => 'string', 'description' => 'Only lines matching this fixed string.'],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['path'],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $server = $this->executor->resolveServer($arguments['server_id'] ?? null);
        $path = $server === null && app(LocalCommand::class)->isWindows()
            ? $this->validateDrivePath((string) ($arguments['path'] ?? ''))
            : $this->validatePath((string) ($arguments['path'] ?? ''));
        $lines = max(1, min(2000, (int) ($arguments['lines'] ?? 200)));

        $grep = (string) ($arguments['grep'] ?? '');
        $command = $grep === ''
            ? 'tail -n '.$lines.' '.McpCommandExecutor::quote($path)
            : 'grep -i -F -- '.McpCommandExecutor::quote(mb_substr($grep, 0, 200)).' '.McpCommandExecutor::quote($path).' | tail -n '.$lines;

        return $this->executor->run($server, $command.' 2>&1', $token, trusted: true);
    }

    /**
     * A Windows panel host keeps its logs on a drive, by default in the dPanel
     * log folder (Spec §4 log_dir). Git's bash reads "C:/x" as that file.
     */
    private function validateDrivePath(string $path): string
    {
        $path = PathService::normalizeAbsolutePath($path);

        if (! PathService::isPlainDrivePath($path) || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new McpToolException('path must be a plain log file path on a drive, such as C:/ProgramData/dPanel/logs/drust.log.');
        }

        $roots = (array) (config('mcp.windows_log_roots')
            ?: [PathService::normalizeAbsolutePath(rtrim((string) (getenv('ProgramData') ?: 'C:\\ProgramData'), '\\/')).'/dPanel/logs/']);

        foreach ($roots as $root) {
            if (PathService::pathStartsWith($path, (string) $root)) {
                return $path;
            }
        }

        throw new McpToolException('path must be under: '.implode(', ', $roots));
    }

    private function validatePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '..') || preg_match('#^[A-Za-z0-9/._@:+-]+$#', $path) !== 1) {
            throw new McpToolException('path must be an absolute log file path without "..".');
        }

        foreach ((array) config('mcp.log_roots', ['/var/log/']) as $root) {
            if (str_starts_with($path, (string) $root)) {
                return $path;
            }
        }

        throw new McpToolException('path must be under: '.implode(', ', (array) config('mcp.log_roots')));
    }
}
