<?php

namespace App\Services\Mcp\Tools;

use App\Models\DatabaseRequest;
use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;

class ListDatabasesTool implements McpTool
{
    public function name(): string
    {
        return 'list_databases';
    }

    public function title(): string
    {
        return 'List databases';
    }

    public function description(): string
    {
        return 'Databases created through the panel (MySQL/MariaDB, PostgreSQL, MongoDB): name, user, host, engine, the website domain they belong to, and status. Passwords are never returned. Use it to find which database a site uses before checking its config file.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Part of the database name, user or domain.'],
                'engine' => ['type' => 'string', 'description' => 'mysql, postgresql or mongodb.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $search = trim((string) ($arguments['search'] ?? ''));
        $engine = trim((string) ($arguments['engine'] ?? ''));

        $query = DatabaseRequest::query()
            ->visibleTo($token->user)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('database_name', 'like', "%{$search}%")
                ->orWhere('database_user', 'like', "%{$search}%")
                ->orWhere('domain', 'like', "%{$search}%")))
            ->when($engine !== '', fn ($q) => $q->where('engine', $engine))
            ->orderBy('domain')
            ->orderBy('database_name');

        return [
            'total' => (clone $query)->count(),
            'databases' => $query->limit(max(1, min(200, (int) ($arguments['limit'] ?? 50))))->get()
                ->map(fn (DatabaseRequest $db) => [
                    'name' => $db->database_name,
                    'user' => $db->database_user,
                    'host' => $db->database_host,
                    'engine' => $db->engine ?: 'mysql',
                    'domain' => $db->domain,
                    'charset' => $db->charset,
                    'status' => $db->status,
                ])->values()->all(),
        ];
    }
}
