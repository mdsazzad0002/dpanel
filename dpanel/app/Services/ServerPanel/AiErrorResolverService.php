<?php

namespace App\Services\ServerPanel;

use App\Events\AiFixSuggested;
use App\Jobs\ExecuteSshCommandJob;
use App\Models\AiErrorResolution;
use App\Models\CommandJob;
use App\Services\ServerPanel\Contracts\AiSuggestionProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class AiErrorResolverService
{
    public function __construct(
        private readonly ErrorSignatureService $errorSignature,
        private readonly CommandSafetyService $commandSafety,
        private readonly AiSuggestionProvider $provider,
    ) {
    }

    /**
     * @return array{resolution:AiErrorResolution,child_jobs:array<int,CommandJob>}
     */
    public function analyze(CommandJob $job): array
    {
        $job->loadMissing(['server', 'events']);

        $signature = $this->errorSignature->signatureFrom((string) $job->error_output, (string) $job->command);

        $known = AiErrorResolution::query()
            ->where('error_signature', $signature)
            ->orderByDesc('usage_count')
            ->first();

        if ($known) {
            $known->increment('usage_count');
            $known->forceFill(['last_used_at' => now()])->save();

            return [
                'resolution' => $known,
                'child_jobs' => $this->createFixJobs($job, (array) ($known->fix_commands ?? [])),
            ];
        }

        $response = $this->provider->suggest([
            'server' => [
                'name' => $job->server?->name,
                'host' => $job->server?->host,
                'os_name' => $job->server?->os_name,
                'os_version' => $job->server?->os_version,
            ],
            'command' => $job->command,
            'output' => $job->output,
            'error_output' => $job->error_output,
            'error_signature' => $signature,
            'memory_hint' => null,
        ]);

        $resolution = AiErrorResolution::query()->create([
            'server_id' => $job->server_id,
            'command_job_id' => $job->id,
            'error_signature' => $signature,
            'problem_title' => (string) ($response['problem_title'] ?? 'Command execution failed'),
            'problem_summary' => (string) ($response['problem_summary'] ?? 'The command failed and needs investigation.'),
            'detected_cause' => (string) ($response['detected_cause'] ?? ''),
            'suggested_fix' => (string) ($response['suggested_fix'] ?? ''),
            'fix_commands' => $response['fix_commands'] ?? [],
            'risk_level' => in_array(($response['risk_level'] ?? 'approval_required'), ['safe', 'approval_required', 'blocked'], true)
                ? $response['risk_level']
                : 'approval_required',
            'tags' => $response['tags'] ?? [],
            'last_used_at' => now(),
            'usage_count' => 1,
        ]);

        $children = $this->createFixJobs($job, (array) ($resolution->fix_commands ?? []));

        Event::dispatch(new AiFixSuggested($job));

        return [
            'resolution' => $resolution,
            'child_jobs' => $children,
        ];
    }

    /**
     * @param  array<int,mixed>  $commands
     * @return array<int,CommandJob>
     */
    private function createFixJobs(CommandJob $job, array $commands): array
    {
        $children = [];

        foreach ($commands as $command) {
            $fixCommand = trim((string) $command);
            if ($fixCommand === '') {
                continue;
            }

            $classification = $this->commandSafety->classify($fixCommand);

            $child = CommandJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'server_id' => $job->server_id,
                'parent_id' => $job->id,
                'task_id' => $job->task_id,
                'command' => $fixCommand,
                'normalized_command' => $classification['normalized_command'],
                'command_hash' => hash('sha256', $classification['normalized_command']),
                'risk_level' => $classification['risk_level'],
                'risk_reason' => 'AI suggested fix: '.$classification['risk_reason'],
                'status' => $classification['risk_level'] === 'blocked'
                    ? 'blocked'
                    : ($classification['risk_level'] === 'safe' && config('serverpanel.auto_run_safe_fixes', false) ? 'queued' : 'pending_approval'),
                'requested_by' => $job->requested_by,
                'tags' => ['ai_fix', 'signature:'.$this->errorSignature->signatureFrom((string) $job->error_output, (string) $job->command)],
            ]);

            $child->events()->create([
                'type' => 'fix_suggested',
                'message' => 'Fix command suggested by AI resolver.',
                'meta' => ['parent_id' => $job->id],
            ]);

            if ($child->status === 'queued') {
                ExecuteSshCommandJob::dispatch($child->id)->onQueue('server-commands');
            }

            $children[] = $child;
        }

        return $children;
    }
}
