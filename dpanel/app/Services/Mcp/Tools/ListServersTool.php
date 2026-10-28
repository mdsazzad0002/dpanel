<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Server;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\LocalHostAccess;
use App\Services\Mcp\McpCommandExecutor;

class ListServersTool implements McpTool
{
    public function __construct(private readonly LocalHostAccess $localAccess) {}

    public function name(): string
    {
        return 'list_servers';
    }

    public function title(): string
    {
        return 'List servers';
    }

    public function description(): string
    {
        return 'Where commands can run: the panel host itself (id "local", when enabled) and the servers dPanel manages over SSH, with their host, OS and last known status. Pass the id as server_id to the command and log tools.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $servers = Server::query()->orderBy('name')->get()->map(fn (Server $server) => [
            'id' => $server->id,
            'name' => $server->name,
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'mode' => $server->mode,
            'status' => $server->status,
            'os' => $server->os_name,
            'kernel' => $server->kernel,
            'cpu_cores' => $server->cpu_cores,
            'ram_total_mb' => $server->ram_total_mb,
            'disk_total_gb' => $server->disk_total_gb,
            'last_connected_at' => $server->last_connected_at?->toIso8601String(),
            'error_message' => $server->error_message,
        ])->values()->all();

        $level = $this->localAccess->level();

        return [
            'panel_host' => [
                'id' => McpCommandExecutor::LOCAL,
                'name' => 'This panel host',
                'hostname' => gethostname() ?: null,
                'mcp_commands' => $level,
                'note' => match ($level) {
                    LocalHostAccess::OFF => 'Off. An admin can enable it with: sudo dpanel mcp local on [read|full]',
                    LocalHostAccess::READ => 'Diagnostic commands only, run as an unprivileged user.',
                    LocalHostAccess::FULL => 'Full-access tokens run commands as root; read-only tokens run unprivileged.',
                    default => 'Enabled, but the panel cannot read the setting.',
                },
            ],
            'servers' => $servers,
        ];
    }
}
