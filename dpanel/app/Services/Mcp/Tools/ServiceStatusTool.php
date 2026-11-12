<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpCommandExecutor;
use App\Support\Platform\LocalCommand;

class ServiceStatusTool implements McpTool
{
    public function __construct(private readonly McpCommandExecutor $executor) {}

    public function name(): string
    {
        return 'get_service_status';
    }

    public function title(): string
    {
        return 'Service status';
    }

    public function description(): string
    {
        return 'systemctl status for one systemd unit on a server (e.g. edge-gateway, drust, php8.3-fpm, mariadb, redis-server, postfix, dovecot), including its last log lines. Needs systemd, so not on a Windows or macOS panel host.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string', 'description' => 'systemd unit name, e.g. mariadb or php8.3-fpm.'],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['service'],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $unit = ServiceName::validate($arguments['service'] ?? null);
        $server = $this->executor->resolveServer($arguments['server_id'] ?? null);
        $this->executor->requireHostTools($server, LocalCommand::SYSTEMD, 'systemctl (systemd)');

        // systemctl status exits 3 for an inactive unit; that's still a valid answer.
        return $this->executor->run($server, 'systemctl status '.McpCommandExecutor::quote($unit).' --no-pager --lines=30 2>&1 || true', $token, trusted: true);
    }
}
