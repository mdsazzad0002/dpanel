<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpCommandExecutor;
use App\Services\Mcp\McpToolException;

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
        return 'Last lines of a log file under /var/log (e.g. /var/log/mail.log, /var/log/php8.3-fpm.log, /var/log/mysql/error.log), optionally filtered by a case-insensitive pattern. Use "ls /var/log" via run_command to discover files.';
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
        $path = $this->validatePath((string) ($arguments['path'] ?? ''));
        $lines = max(1, min(2000, (int) ($arguments['lines'] ?? 200)));
        $server = $this->executor->resolveServer($arguments['server_id'] ?? null);

        $grep = (string) ($arguments['grep'] ?? '');
        $command = $grep === ''
            ? 'tail -n '.$lines.' '.escapeshellarg($path)
            : 'grep -i -F -- '.escapeshellarg(mb_substr($grep, 0, 200)).' '.escapeshellarg($path).' | tail -n '.$lines;

        return $this->executor->run($server, $command.' 2>&1', $token, trusted: true);
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
