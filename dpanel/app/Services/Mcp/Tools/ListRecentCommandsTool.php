<?php

namespace App\Services\Mcp\Tools;

use App\Models\CommandJob;
use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;

class ListRecentCommandsTool implements McpTool
{
    public function name(): string
    {
        return 'list_recent_commands';
    }

    public function title(): string
    {
        return 'Recent commands';
    }

    public function description(): string
    {
        return 'Recent commands run on servers (from the panel or MCP) with status, exit code and the panel\'s AI error summary, newest first. Set failed_only to look at what has been going wrong.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server_id' => ['type' => 'integer'],
                'failed_only' => ['type' => 'boolean', 'default' => false],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));

        $jobs = CommandJob::query()
            ->with('server:id,name')
            ->when($arguments['server_id'] ?? null, fn ($q, $id) => $q->where('server_id', (int) $id))
            ->when((bool) ($arguments['failed_only'] ?? false), fn ($q) => $q->where('status', 'failed'))
            ->latest('id')
            ->limit($limit)
            ->get();

        return [
            'commands' => $jobs->map(fn (CommandJob $job) => [
                'id' => $job->id,
                'server' => $job->server?->name,
                'command' => $job->command,
                'status' => $job->status,
                'risk_level' => $job->risk_level,
                'exit_code' => $job->exit_code,
                'error_output' => mb_substr((string) $job->error_output, -2000),
                'ai_summary' => $job->ai_summary,
                'ai_fix_suggestion' => $job->ai_fix_suggestion,
                'via_mcp' => in_array('mcp', (array) $job->tags, true),
                'created_at' => $job->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
