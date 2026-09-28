<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\LaravelInstallJobStatus;
use App\Services\Website\LaravelInstallService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs LaravelInstallService::install() on the heavy queue — composer and the
 * Vite build can take many minutes — while LaravelInstaller.vue polls
 * LaravelInstallJobStatus for progress.
 */
class LaravelInstallJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3000;

    public int $tries = 1;

    /**
     * @param array{stack: string, laravel_version: string, database_id: string, database_suffix?: string|null, push_to_git?: bool} $input
     */
    public function __construct(
        public string $installId,
        public string $websiteId,
        public array $input,
        public int $userId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(LaravelInstallService $service): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            LaravelInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => 'Actor account was not found.']);

            return;
        }

        $website = Website::query()->visibleTo($user)->find($this->websiteId);
        if (! $website) {
            LaravelInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => 'Website account not found.']);

            return;
        }

        try {
            $result = $service->install(
                $website,
                $this->input,
                $user,
                fn (string $stage) => LaravelInstallJobStatus::set($this->installId, ['stage' => $stage]),
            );
        } catch (Throwable $e) {
            LaravelInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => $e->getMessage()]);

            return;
        }

        if (! ($result['success'] ?? false)) {
            LaravelInstallJobStatus::set($this->installId, [
                'stage' => 'failed',
                'message' => (string) ($result['message'] ?? 'Laravel installation failed.'),
            ]);

            return;
        }

        LaravelInstallJobStatus::set($this->installId, [
            'stage' => 'ready',
            'message' => (string) $result['message'],
            'website' => $website->fresh()?->toArray(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        LaravelInstallJobStatus::set($this->installId, [
            'stage' => 'failed',
            'message' => $e?->getMessage() ?: 'Laravel installation was interrupted.',
        ]);
    }
}
