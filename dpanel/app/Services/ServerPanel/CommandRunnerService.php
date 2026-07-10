<?php

namespace App\Services\ServerPanel;

use App\Events\CommandApproved;
use App\Events\CommandClassified;
use App\Events\CommandCreated;
use App\Events\CommandFailed;
use App\Events\CommandFinished;
use App\Events\CommandStarted;
use App\Jobs\AnalyzeCommandErrorJob;
use App\Jobs\ExecuteSshCommandJob;
use App\Models\CommandEvent;
use App\Models\CommandJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class CommandRunnerService
{
    public function __construct(
        private readonly CommandSafetyService $commandSafety,
        private readonly ReportService $reportService,
    ) {
    }

    public function createAndDispatch(Server $server, string $command, ?User $requestedBy = null, array $extra = []): CommandJob
    {
        $shouldDispatch = (bool) ($extra['dispatch'] ?? true);

        $job = CommandJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'server_id' => $server->id,
            'parent_id' => $extra['parent_id'] ?? null,
            'task_id' => $extra['task_id'] ?? null,
            'command' => $command,
            'risk_level' => 'safe',
            'status' => 'draft',
            'requested_by' => $requestedBy?->id,
            'tags' => $extra['tags'] ?? null,
        ]);

        $this->event($job, 'created', 'Command job created.');
        Event::dispatch(new CommandCreated($job));

        $classification = $this->commandSafety->classify($command);
        $job->forceFill([
            'normalized_command' => $classification['normalized_command'],
            'command_hash' => hash('sha256', $classification['normalized_command']),
            'risk_level' => $classification['risk_level'],
            'risk_reason' => $classification['risk_reason'],
            'status' => $classification['risk_level'] === 'blocked' ? 'blocked' : 'queued',
        ])->save();

        $this->event($job, 'classified', 'Command risk classified.', [
            'risk_level' => $job->risk_level,
            'risk_reason' => $job->risk_reason,
        ]);
        Event::dispatch(new CommandClassified($job));

        if ($job->status === 'blocked') {
            $this->event($job, 'blocked', 'Blocked dangerous command.');

            return $job;
        }

        if ($job->status === 'queued' && $shouldDispatch) {
            $this->dispatchExecution($job);
        }

        return $job;
    }

    public function approve(CommandJob $job, User $approver): CommandJob
    {
        $job->forceFill([
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'status' => 'queued',
        ])->save();

        $this->event($job, 'approved', 'Command approved by admin.', [
            'approved_by' => $approver->id,
        ]);
        Event::dispatch(new CommandApproved($job));

        $this->dispatchExecution($job);

        return $job;
    }

    public function dispatchExecution(CommandJob $job): void
    {
        $job->forceFill(['status' => 'queued'])->save();
        $this->event($job, 'queued', 'Command queued for execution.');
        ExecuteSshCommandJob::dispatch($job->id)->onQueue('server-commands');
    }

    /**
     * @param  array{output:string,error_output:string,exit_code:int|null}  $result
     */
    public function markFinished(CommandJob $job, array $result): CommandJob
    {
        $successful = (($result['exit_code'] ?? 1) === 0) && trim((string) ($result['error_output'] ?? '')) === '';

        $job->forceFill([
            'status' => $successful ? 'success' : 'failed',
            'finished_at' => now(),
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
            'error_output' => $result['error_output'],
        ])->save();

        $this->event($job, $successful ? 'success' : 'failed', $successful ? 'Command completed successfully.' : 'Command execution failed.', [
            'exit_code' => $result['exit_code'],
        ]);

        Event::dispatch($successful ? new CommandFinished($job) : new CommandFailed($job));

        $reportPath = $this->reportService->generate($job->fresh(['server', 'events', 'approvedBy', 'requestedBy']));
        $job->forceFill(['report_path' => $reportPath])->save();

        if (! $successful) {
            AnalyzeCommandErrorJob::dispatch($job->id)->onQueue('server-commands');
        }

        return $job;
    }

    public function markStarted(CommandJob $job): void
    {
        $job->forceFill([
            'status' => 'running',
            'started_at' => now(),
        ])->save();

        $this->event($job, 'started', 'Command execution started.');
        Event::dispatch(new CommandStarted($job));
    }

    public function cancel(CommandJob $job): CommandJob
    {
        $job->forceFill(['status' => 'cancelled'])->save();
        $this->event($job, 'failed', 'Command was cancelled.');

        return $job;
    }

    public function event(CommandJob $job, string $type, string $message, array $meta = []): CommandEvent
    {
        return $job->events()->create([
            'type' => $type,
            'message' => $message,
            'meta' => $meta,
        ]);
    }
}
