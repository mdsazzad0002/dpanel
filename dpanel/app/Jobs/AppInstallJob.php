<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\AppInstallJobStatus;
use App\Services\Website\AppInstallService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs AppInstallService::install() (Joomla or CodeIgniter) on the heavy
 * queue while AppInstaller.vue polls AppInstallJobStatus for progress.
 */
class AppInstallJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $input  includes the Joomla admin password, so the
     *                                        payload is encrypted on the queue
     */
    public function __construct(
        public string $installId,
        public string $app,
        public string $websiteId,
        public array $input,
        public int $userId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(AppInstallService $service): void
    {
        $user = User::find($this->userId);
        $website = $user ? Website::query()->visibleTo($user)->find($this->websiteId) : null;
        if (! $website) {
            AppInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => 'Website account not found.']);

            return;
        }

        try {
            $result = $service->install(
                $this->app,
                $website,
                $this->input,
                $user,
                fn (string $stage) => AppInstallJobStatus::set($this->installId, ['stage' => $stage]),
            );
        } catch (Throwable $e) {
            AppInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => $e->getMessage()]);

            return;
        }

        AppInstallJobStatus::set($this->installId, ($result['success'] ?? false)
            ? ['stage' => 'ready', 'message' => (string) $result['message'], 'website' => $website->fresh()?->toArray()]
            : ['stage' => 'failed', 'message' => (string) ($result['message'] ?? 'Installation failed.')]);
    }

    public function failed(?Throwable $e): void
    {
        AppInstallJobStatus::set($this->installId, [
            'stage' => 'failed',
            'message' => $e?->getMessage() ?: 'Installation was interrupted.',
        ]);
    }
}
