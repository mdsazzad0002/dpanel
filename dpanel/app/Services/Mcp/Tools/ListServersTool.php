<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Server;
use App\Services\Mcp\Contracts\McpTool;

class ListServersTool implements McpTool
{
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
        return 'Servers managed by dPanel over SSH, with their id, host, OS and last known status. Pass the id as server_id to the command and log tools.';
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

        return ['servers' => $servers];
    }
}
