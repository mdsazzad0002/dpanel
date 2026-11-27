<?php

namespace App\Services\Mcp\Tools;

use App\Models\CronJob;
use App\Models\McpAccessToken;
use App\Models\Website;
use App\Services\Cron\CronSystemService;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;
use Illuminate\Support\Str;

/**
 * Create, change or delete a website's cron job the way the Cron Jobs page
 * does: the row and the system scheduler change together, and a failed
 * scheduler sync rolls the row back. Panel-owned system jobs are refused.
 */
class ManageCronJobTool implements McpTool
{
    public function __construct(private readonly CronSystemService $cronSystem) {}

    public function name(): string
    {
        return 'manage_cron_job';
    }

    public function title(): string
    {
        return 'Create, update or delete a cron job';
    }

    public function description(): string
    {
        return 'Create, update or delete a cron job of a website; it runs as the website\'s system user. action=create needs domain, name, schedule (5-field cron) and command. action=update or delete needs domain and the job name (from list_cron_jobs); update changes only the fields given; status active|disabled pauses or resumes a job. Use list_cron_jobs first to avoid duplicates.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                'domain' => ['type' => 'string'],
                'name' => ['type' => 'string', 'description' => 'Job name; identifies the job for update and delete.'],
                'new_name' => ['type' => 'string', 'description' => 'update only: rename the job.'],
                'schedule' => ['type' => 'string', 'description' => 'Standard 5-field cron, e.g. "0 2 * * *".'],
                'command' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['active', 'disabled']],
                'description' => ['type' => 'string'],
            ],
            'required' => ['action', 'domain', 'name'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        if (! $token->allowsMutations()) {
            throw new McpToolException('This token is read-only. Switch the chat\'s server tools to full access to change cron jobs.');
        }

        $action = (string) ($arguments['action'] ?? '');
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        $name = trim((string) ($arguments['name'] ?? ''));
        $website = Website::query()->visibleTo($token->user)->where('domain', $domain)->first();
        if ($website === null) {
            throw new McpToolException("No website {$domain} that this account can manage. Call list_websites to see the domains.");
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new McpToolException('name is required (max 120 characters).');
        }

        if ($action === 'create') {
            return $this->create($website, $name, $arguments);
        }

        $job = CronJob::query()->where('website_id', $website->id)->where('name', $name)->first();
        if ($job === null) {
            throw new McpToolException("No cron job named \"{$name}\" on {$domain}. Call list_cron_jobs with this domain.");
        }
        if (array_key_exists((string) $job->id, CronSystemService::SYSTEM_JOB_USERS)) {
            throw new McpToolException('This is a panel system job; change it on the panel\'s own settings page, not here.');
        }

        return match ($action) {
            'update' => $this->update($website, $job, $arguments),
            'delete' => $this->delete($job),
            default => throw new McpToolException('action must be create, update or delete.'),
        };
    }

    private function create(Website $website, string $name, array $arguments): array
    {
        $schedule = $this->schedule((string) ($arguments['schedule'] ?? ''));
        $command = $this->command((string) ($arguments['command'] ?? ''));
        if (CronJob::query()->where('website_id', $website->id)->where('name', $name)->exists()) {
            throw new McpToolException("A job named \"{$name}\" already exists on {$website->domain}; use action=update.");
        }

        $job = CronJob::query()->create([
            'id' => (string) Str::uuid(),
            'website_id' => $website->id,
            'domain' => (string) $website->domain,
            'name' => $name,
            'expression' => $schedule,
            'command' => $command,
            'status' => $this->status($website, (string) ($arguments['status'] ?? 'active')),
            'description' => mb_substr(trim((string) ($arguments['description'] ?? '')), 0, 500),
        ]);

        try {
            $this->cronSystem->sync($job, $website);
        } catch (\Throwable $e) {
            $job->delete();
            throw new McpToolException('The scheduler refused the job, so nothing was created: '.$e->getMessage());
        }

        return ['created' => $this->present($job->fresh())];
    }

    private function update(Website $website, CronJob $job, array $arguments): array
    {
        $original = $job->only(['name', 'expression', 'command', 'status', 'description']);
        $changes = array_filter([
            'name' => isset($arguments['new_name']) ? mb_substr(trim((string) $arguments['new_name']), 0, 120) : null,
            'expression' => isset($arguments['schedule']) ? $this->schedule((string) $arguments['schedule']) : null,
            'command' => isset($arguments['command']) ? $this->command((string) $arguments['command']) : null,
            'status' => isset($arguments['status']) ? $this->status($website, (string) $arguments['status']) : null,
            'description' => isset($arguments['description']) ? mb_substr(trim((string) $arguments['description']), 0, 500) : null,
        ], fn ($value) => $value !== null && $value !== '');
        if ($changes === []) {
            throw new McpToolException('Nothing to change: give new_name, schedule, command, status or description.');
        }

        $job->update($changes);
        try {
            $this->cronSystem->sync($job->fresh(), $website);
        } catch (\Throwable $e) {
            $job->update($original);
            try {
                $this->cronSystem->sync($job->fresh(), $website);
            } catch (\Throwable) {
                // The row is back as it was even if the scheduler rollback fails.
            }
            throw new McpToolException('The scheduler refused the change, so it was undone: '.$e->getMessage());
        }

        return ['updated' => $this->present($job->fresh()), 'before' => $original];
    }

    private function delete(CronJob $job): array
    {
        $before = $this->present($job);
        try {
            $this->cronSystem->delete((string) $job->id);
        } catch (\Throwable $e) {
            throw new McpToolException('The scheduler refused to remove the job, so it was kept: '.$e->getMessage());
        }
        $job->delete();

        return ['deleted' => $before];
    }

    private function schedule(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if (preg_match('/^([0-9*,\/-]+ ){4}[0-9*,\/-]+$/', $value) !== 1) {
            throw new McpToolException('schedule must be a standard 5-field cron expression, e.g. "*/15 * * * *".');
        }

        return $value;
    }

    private function command(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 2000) {
            throw new McpToolException('command is required (max 2000 characters).');
        }
        if (preg_match('/\bsudo\b|curl[^|]*\|\s*(ba)?sh|wget[^|]*\|\s*(ba)?sh/i', $value) === 1) {
            throw new McpToolException('Commands with sudo or that pipe a download into a shell are not allowed in cron jobs.');
        }

        return $value;
    }

    /** Same rule as the Cron Jobs page: a suspended owner keeps the job suspended. */
    private function status(Website $website, string $requested): string
    {
        if ($requested === 'disabled') {
            return 'disabled';
        }

        return $website->assignedUser?->is_suspended ? 'suspended' : 'active';
    }

    private function present(CronJob $job): array
    {
        return ['name' => $job->name, 'domain' => $job->domain, 'schedule' => $job->expression, 'command' => $job->command, 'status' => $job->status];
    }
}
