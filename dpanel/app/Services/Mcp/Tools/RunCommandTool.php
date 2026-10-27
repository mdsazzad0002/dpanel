<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpCommandExecutor;
use App\Services\Mcp\McpToolException;

class RunCommandTool implements McpTool
{
    public function __construct(private readonly McpCommandExecutor $executor) {}

    public function name(): string
    {
        return 'run_command';
    }

    public function title(): string
    {
        return 'Run shell command';
    }

    public function description(): string
    {
        return 'Run a shell command on a managed server over SSH and return its output. Read-only tokens may only run known diagnostic commands (df -h, free -m, uptime, ls, systemctl status, journalctl -u, cat /var/log/…, php -v …), optionally piped into grep/head/tail/wc/sort. Full-access tokens may also run state-changing commands to fix problems; dangerous commands (rm -rf /, mkfs, reboot, …) are always refused. Every run is recorded in the panel command history. Prefer the dedicated tools for logs and service status.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'The shell command to run.'],
                'server_id' => ['type' => 'integer', 'description' => 'Target server; optional when only one exists.'],
            ],
            'required' => ['command'],
        ];
    }

    public function readOnly(): bool
    {
        // Whether a given command may change state is decided per call by the policy.
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $command = trim((string) ($arguments['command'] ?? ''));
        if ($command === '') {
            throw new McpToolException('command is required.');
        }
        if (mb_strlen($command) > 4000) {
            throw new McpToolException('command is too long (max 4000 characters).');
        }

        $server = $this->executor->resolveServer($arguments['server_id'] ?? null);

        return $this->executor->run($server, $command, $token);
    }
}
