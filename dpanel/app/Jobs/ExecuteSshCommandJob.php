<?php

namespace App\Jobs;

use App\Models\CommandJob;
use App\Services\ServerPanel\CommandRunnerService;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteSshCommandJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $commandJobId)
    {
        // Leave room past the SSH command timeout so the job can still record its result.
        $this->timeout = (int) config('serverpanel.command_timeout', 300) + 60;
    }

    /**
     * Execute the job.
     */
    public function handle(SshClientService $sshClient, CommandRunnerService $commandRunner): void
    {
        $job = CommandJob::query()->with('server')->find($this->commandJobId);
        if (! $job || ! $job->server || ! in_array($job->status, ['queued', 'running'], true)) {
            return;
        }

        $commandRunner->markStarted($job);

        try {
            $result = $sshClient->executeOnServer($job->server, $job->command);
        } catch (\Throwable $exception) {
            $result = [
                'output' => '',
                'error_output' => $exception->getMessage(),
                'exit_code' => 1,
            ];
        }

        $commandRunner->markFinished($job->fresh(), $result);
    }

    /**
     * Never leave a command stuck in "running" when the worker is killed.
     */
    public function failed(?\Throwable $exception): void
    {
        $job = CommandJob::query()->find($this->commandJobId);
        if (! $job || ! in_array($job->status, ['queued', 'running'], true)) {
            return;
        }

        app(CommandRunnerService::class)->markFinished($job, [
            'output' => (string) $job->output,
            'error_output' => $exception?->getMessage() ?: 'Command worker stopped before the command finished.',
            'exit_code' => null,
        ]);
    }
}
