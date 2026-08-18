<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\WebsiteArchiver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunAccountBackupsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 86400;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    /** @param list<string> $websiteIds */
    public function __construct(
        public string $batchId,
        public int $userId,
        public array $websiteIds,
        public string $content,
        public int $delaySeconds = 0,
    ) {
        $this->onQueue('heavy');
    }

    public function uniqueId(): string
    {
        return 'account-backups:'.$this->userId;
    }

    public function handle(WebsiteArchiver $archiver): void
    {
        $user = User::find($this->userId);
        if (! $user instanceof User) {
            $this->status('failed', 0, 'The user who started this backup no longer exists.');

            return;
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        $completed = 0;
        $total = count($this->websiteIds);

        foreach ($this->websiteIds as $index => $websiteId) {
            $website = Website::query()->visibleTo($user)->find($websiteId);
            if (! $website instanceof Website) {
                $this->status('failed', $completed, 'An account is no longer available.');

                return;
            }

            $this->status('running', $completed, 'Backing up '.$website->domain.' ('.($index + 1).'/'.$total.')');

            try {
                $result = $archiver->archive($user, $website, $this->content, $baseUrl, $token);
            } catch (Throwable $exception) {
                $result = ['ok' => false, 'message' => $exception->getMessage()];
            }

            if (! $result['ok']) {
                $this->status('failed', $completed, 'Backup stopped at '.$website->domain.': '.$result['message']);

                return;
            }

            $completed++;
            if ($completed < $total && $this->delaySeconds > 0) {
                sleep($this->delaySeconds);
            }
        }

        $this->status('completed', $completed, $completed.' account backup(s) completed sequentially.');
    }

    private function status(string $stage, int $completed, string $message): void
    {
        Cache::put('account-backup-batch:'.$this->userId, [
            'id' => $this->batchId,
            'stage' => $stage,
            'completed' => $completed,
            'total' => count($this->websiteIds),
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDay());
    }
}
