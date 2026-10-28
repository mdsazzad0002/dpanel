<?php

namespace App\Services\Mcp\Tools;

use App\Models\Backup;
use App\Models\McpAccessToken;
use App\Services\Mcp\Contracts\McpTool;

class ListBackupsTool implements McpTool
{
    public function name(): string
    {
        return 'list_backups';
    }

    public function title(): string
    {
        return 'List backups';
    }

    public function description(): string
    {
        return 'Recent backups made by the panel, newest first, with type, source, size, storage path, status and error message, plus a summary of failures in the last 7 days and when the last successful backup finished. Use it before a risky change, or when asked whether backups work.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Exact status, e.g. completed, failed, running.'],
                'search' => ['type' => 'string', 'description' => 'Part of the backup name.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $status = trim((string) ($arguments['status'] ?? ''));
        $search = trim((string) ($arguments['search'] ?? ''));
        $base = Backup::query()->when(! $token->user?->hasRole('admin'), fn ($q) => $q->where('assigned_user_id', $token->user?->id));

        $lastSuccess = (clone $base)->where('status', 'completed')->latest('completed_at')->first();

        return [
            'summary' => [
                'failed_last_7d' => (clone $base)->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
                'last_successful_at' => $lastSuccess?->completed_at?->toIso8601String(),
                'hours_since_last_success' => $lastSuccess?->completed_at ? (int) $lastSuccess->completed_at->diffInHours(now()) : null,
            ],
            'backups' => (clone $base)
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->latest()
                ->limit(max(1, min(100, (int) ($arguments['limit'] ?? 25))))
                ->get()
                ->map(fn (Backup $backup) => [
                    'name' => $backup->name,
                    'type' => $backup->type,
                    'source' => trim($backup->source_type.' '.$backup->source_id),
                    'status' => $backup->status,
                    'size_mb' => $backup->file_size ? round($backup->file_size / 1048576, 1) : null,
                    'storage' => $backup->storage_type,
                    'file_path' => $backup->file_path,
                    'error' => $backup->error_message ? mb_substr($backup->error_message, 0, 500) : null,
                    'started_at' => $backup->started_at?->toIso8601String(),
                    'completed_at' => $backup->completed_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
