<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;

class ListWebsitesTool implements McpTool
{
    public function name(): string
    {
        return 'list_websites';
    }

    public function title(): string
    {
        return 'List websites';
    }

    public function description(): string
    {
        return 'Websites hosted on this panel with runtime, PHP version, SSL mode and status. Filter by part of the domain or by status.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Part of the domain to match.'],
                'status' => ['type' => 'string', 'description' => 'Exact status, e.g. active, suspended, pending.'],
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
        $limit = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
        $search = trim((string) ($arguments['search'] ?? ''));
        $status = trim((string) ($arguments['status'] ?? ''));

        $query = Website::query()
            ->visibleTo($token->user)
            ->when($search !== '', fn ($q) => $q->where('domain', 'like', '%'.$search.'%'))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('domain');

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'websites' => $query->limit($limit)->get()->map(fn (Website $site) => [
                'id' => $site->id,
                'domain' => $site->domain,
                'type' => $site->type,
                'status' => $site->status,
                'runtime' => $site->runtime ?: 'php',
                'php_version' => $site->php_version,
                'ssl_mode' => $site->ssl_mode,
                'site_owner' => $site->site_owner,
            ])->values()->all(),
        ];
    }
}
