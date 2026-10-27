<?php

namespace App\Services\Mcp\Tools;

use App\Models\ActivityLog;
use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;

class ListRecentActivityTool implements McpTool
{
    public function name(): string
    {
        return 'list_recent_activity';
    }

    public function title(): string
    {
        return 'Recent panel activity';
    }

    public function description(): string
    {
        return 'The panel activity log (who changed what, and when), newest first. Useful to answer "what changed before this broke?".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'description' => 'Only actions containing this text, e.g. website or ssl.'],
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
        $action = trim((string) ($arguments['action'] ?? ''));

        $logs = ActivityLog::query()
            ->with('user:id,name,email')
            ->when($action !== '', fn ($q) => $q->where('action', 'like', '%'.$action.'%'))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'activity' => $logs->map(fn (ActivityLog $log) => [
                'action' => $log->action,
                'user' => $log->user?->email,
                'subject' => $log->subject_type ? class_basename($log->subject_type).'#'.$log->subject_id : null,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
