<?php

namespace App\Services\Mcp\Tools;

use App\Models\CronJob;
use App\Models\McpAccessToken;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;

class ListCronJobsTool implements McpTool
{
    public function name(): string
    {
        return 'list_cron_jobs';
    }

    public function title(): string
    {
        return 'List cron jobs';
    }

    public function description(): string
    {
        return 'Cron jobs managed by the panel: schedule, command, status and website. Use it when scheduled work (Laravel scheduler, WordPress cron, backups) does not run.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'Only jobs of this website.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        $visible = Website::query()->visibleTo($token->user)->pluck('domain')->all();

        return [
            'cron_jobs' => CronJob::query()
                ->when(! $token->user?->hasRole('admin'), fn ($q) => $q->whereIn('domain', $visible))
                ->when($domain !== '', fn ($q) => $q->where('domain', $domain))
                ->orderBy('domain')
                ->limit(max(1, min(200, (int) ($arguments['limit'] ?? 100))))
                ->get()
                ->map(fn (CronJob $job) => [
                    'name' => $job->name,
                    'domain' => $job->domain,
                    'schedule' => $job->expression,
                    'command' => $job->command,
                    'status' => $job->status,
                    'description' => $job->description,
                ])->values()->all(),
            'hint' => 'To see whether a job actually ran, read_log_file /var/log/syslog filtered for CRON, or the log file the command writes to.',
        ];
    }
}
