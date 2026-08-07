<?php

namespace App\Console\Commands;

use App\Models\WebsiteGitDeployment;
use App\Services\Website\WebsiteGitService;
use Illuminate\Console\Command;

class SyncWebsiteGitDeploymentsCommand extends Command
{
    protected $signature = 'websites:git-sync';
    protected $description = 'Run due conflict-safe website Git synchronizations';

    public function handle(WebsiteGitService $git): int
    {
        WebsiteGitDeployment::query()
            ->where('enabled', true)->where('auto_action', '!=', 'off')
            ->where(fn ($query) => $query->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->with('website')->orderBy('next_sync_at')->each(function (WebsiteGitDeployment $deployment) use ($git): void {
                try {
                    $git->run($deployment, $deployment->auto_action, null, 'Automated website sync');
                } catch (\Throwable $e) {
                    report($e);
                    $deployment->forceFill([
                        'last_status' => 'failed', 'last_message' => mb_substr($e->getMessage(), 0, 12000),
                        'next_sync_at' => now()->addMinutes($deployment->interval_minutes),
                    ])->save();
                }
            });

        return self::SUCCESS;
    }
}
