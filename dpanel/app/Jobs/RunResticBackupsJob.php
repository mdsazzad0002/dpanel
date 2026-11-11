<?php

namespace App\Jobs;

use App\Services\Backup\ResticBackupService;
use App\Services\NotificationService;
use App\Support\ResticSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backlog W6: one restore point for each website (or the ones given), one
 * after another, then the retention. A failed site is reported and the run
 * goes on with the next.
 */
class RunResticBackupsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 86400;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    /** @param  list<string>|null  $websiteIds  null: every website */
    public function __construct(public ?array $websiteIds = null)
    {
        $this->onQueue('heavy');
    }

    public function uniqueId(): string
    {
        return 'restic-backups';
    }

    public function handle(ResticBackupService $restic, ResticSettings $settings): void
    {
        $websites = $this->websiteIds === null
            ? ResticBackupService::websites()->get()
            : ResticBackupService::websites()->whereIn('id', $this->websiteIds)->get();
        $settings->write(['last_run_at' => now()->toIso8601String(), 'last_run_status' => 'running', 'last_run_message' => 'Backing up '.$websites->count().' website(s).']);

        $failed = [];
        foreach ($websites as $website) {
            try {
                $restic->backup($website);
            } catch (Throwable $exception) {
                $failed[] = $website->domain.': '.$exception->getMessage();
                Log::warning('Incremental backup failed.', ['website' => $website->domain, 'error' => $exception->getMessage()]);
            }
        }

        $removed = 0;
        if ($this->websiteIds === null) {
            try {
                $removed = $restic->forget();
            } catch (Throwable $exception) {
                $failed[] = 'retention: '.$exception->getMessage();
            }
        }

        $done = $websites->count() - count(array_filter($failed, fn ($line) => ! str_starts_with($line, 'retention: ')));
        $message = "{$done} of {$websites->count()} website(s) backed up".($removed > 0 ? ", {$removed} old restore point(s) dropped" : '').'.';
        if ($failed !== []) {
            $message .= ' Failed: '.implode('; ', array_slice($failed, 0, 10));
            $this->tellAdmins($message);
        }
        $settings->write(['last_run_at' => now()->toIso8601String(), 'last_run_status' => $failed === [] ? 'completed' : 'failed', 'last_run_message' => mb_substr($message, 0, 2000)]);
    }

    /** Admins see every notification, so one is enough. */
    private function tellAdmins(string $message): void
    {
        app(NotificationService::class)->create(type: 'backup', status: 'failed', title: 'Incremental backup had failures', message: mb_substr($message, 0, 1000));
    }
}
