<?php

namespace App\Jobs;

use App\Models\CronJob;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCronJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 2;

    public function __construct(
        public string $cronJobId,
        public string $action = 'sync',
    ) {
    }

    public function handle(SshClientService $sshClient): void
    {
        $cron = CronJob::find($this->cronJobId);
        if (! $cron) {
            return;
        }

        match ($this->action) {
            'create' => $this->createCronEntry($cron, $sshClient),
            'update' => $this->updateCronEntry($cron, $sshClient),
            'delete' => $this->deleteCronEntry($cron, $sshClient),
            'enable' => $this->enableCronEntry($cron, $sshClient),
            'disable' => $this->disableCronEntry($cron, $sshClient),
            default => null,
        };
    }

    private function createCronEntry(CronJob $cron, SshClientService $sshClient): void
    {
        // Add crontab entry
        $cron->update(['status' => 'active']);
    }

    private function updateCronEntry(CronJob $cron, SshClientService $sshClient): void
    {
        // Remove old entry and add new one
    }

    private function deleteCronEntry(CronJob $cron, SshClientService $sshClient): void
    {
        // Remove crontab entry
        $cron->update(['status' => 'deleted']);
    }

    private function enableCronEntry(CronJob $cron, SshClientService $sshClient): void
    {
        $cron->update(['status' => 'active']);
    }

    private function disableCronEntry(CronJob $cron, SshClientService $sshClient): void
    {
        $cron->update(['status' => 'disabled']);
    }
}
