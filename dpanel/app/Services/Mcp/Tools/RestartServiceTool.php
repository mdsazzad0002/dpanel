<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\LocalHostAccess;
use App\Services\Mcp\McpCommandExecutor;
use App\Services\Mcp\McpToolException;

class RestartServiceTool implements McpTool
{
    public function __construct(
        private readonly McpCommandExecutor $executor,
        private readonly LocalHostAccess $localAccess,
    ) {}

    public function name(): string
    {
        return 'restart_service';
    }

    public function title(): string
    {
        return 'Restart / reload service';
    }

    public function description(): string
    {
        return 'Restart, reload, start or stop a systemd unit on a server, then report its new status. Needs a full-access token.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string', 'description' => 'systemd unit name.'],
                'action' => ['type' => 'string', 'enum' => ['restart', 'reload', 'start', 'stop'], 'default' => 'restart'],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['service'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        if (! $token->allowsMutations()) {
            throw new McpToolException('This MCP token is read-only. Create a full-access token in dPanel (Settings → MCP Access) to restart services.');
        }

        $unit = ServiceName::validate($arguments['service'] ?? null);
        $action = (string) ($arguments['action'] ?? 'restart');
        if (! in_array($action, ['restart', 'reload', 'start', 'stop'], true)) {
            throw new McpToolException('action must be one of restart, reload, start, stop.');
        }

        $server = $this->executor->resolveServer($arguments['server_id'] ?? null);
        if ($server === null && $this->localAccess->level() === LocalHostAccess::READ) {
            throw new McpToolException('The panel host only allows read-only MCP commands. An admin can allow fixes with: sudo dpanel mcp local on full');
        }
        $quoted = escapeshellarg($unit);

        return $this->executor->run(
            $server,
            "systemctl {$action} {$quoted} 2>&1; echo \"--- status after {$action} ---\"; systemctl status {$quoted} --no-pager --lines=15 2>&1 || true",
            $token,
            trusted: true,
        );
    }
}
