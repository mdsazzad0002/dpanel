<?php

namespace App\Jobs;

use App\Models\CommandJob;
use App\Services\ServerPanel\AiErrorResolverService;
use App\Services\ServerPanel\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeCommandErrorJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $commandJobId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(AiErrorResolverService $resolver, ReportService $reportService): void
    {
        $job = CommandJob::query()->with(['server', 'events'])->find($this->commandJobId);
        if (! $job || $job->status !== 'failed') {
            return;
        }

        $result = $resolver->analyze($job);
        $resolution = $result['resolution'];

        $job->forceFill([
            'ai_summary' => $resolution->problem_summary,
            'ai_fix_suggestion' => $resolution->suggested_fix,
            'ai_fix_commands' => $resolution->fix_commands,
        ])->save();

        $job->events()->create([
            'type' => 'ai_analyzed',
            'message' => 'AI error resolver analyzed command failure.',
            'meta' => [
                'error_signature' => $resolution->error_signature,
                'child_jobs_count' => count($result['child_jobs']),
            ],
        ]);

        $job->forceFill([
            'report_path' => $reportService->generate($job->fresh(['server', 'events', 'approvedBy', 'requestedBy'])),
        ])->save();
    }
}
