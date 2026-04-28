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

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $commandJobId)
    {
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
}
