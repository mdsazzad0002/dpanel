<?php

namespace App\Jobs;

use App\Models\WebsiteGitDeployment;
use App\Services\Website\WebsiteGitService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs a Git action for a website off the request cycle (push-to-deploy webhooks). */
class RunWebsiteGitActionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public string $deploymentId, public string $action, public string $message = 'Push-to-deploy') {}

    public function handle(WebsiteGitService $git): void
    {
        $deployment = WebsiteGitDeployment::query()->with('website', 'githubAccount')->find($this->deploymentId);
        if (! $deployment || ! $deployment->enabled) {
            return;
        }

        try {
            $git->run($deployment, $this->action, null, $this->message);
        } catch (\Throwable $e) {
            report($e);
            $deployment->forceFill(['last_status' => 'failed', 'last_message' => mb_substr($e->getMessage(), 0, 12000)])->save();
        }
    }
}
