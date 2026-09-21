<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\WordpressInstallJobStatus;
use App\Services\Website\WordpressInstallService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs WordpressInstallService::install() in the background so the install
 * button on WordPressInstaller.vue can poll WordpressInstallJobStatus and
 * show a step-by-step progress bar (downloading → creating database →
 * connecting database) instead of blocking on a single synchronous request.
 */
class WordpressInstallJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $installId,
        public string $websiteId,
        public array $input,
        public int $userId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(WordpressInstallService $service): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            WordpressInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => 'Actor account was not found.']);

            return;
        }

        $website = Website::query()->visibleTo($user)->find($this->websiteId);
        if (! $website) {
            WordpressInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => 'Website account not found.']);

            return;
        }

        try {
            $result = $service->install(
                $website->toArray(),
                $this->input,
                $user,
                fn (string $stage) => WordpressInstallJobStatus::set($this->installId, ['stage' => $stage]),
            );
        } catch (Throwable $e) {
            WordpressInstallJobStatus::set($this->installId, ['stage' => 'failed', 'message' => $e->getMessage()]);

            return;
        }

        if (! ($result['success'] ?? false)) {
            WordpressInstallJobStatus::set($this->installId, [
                'stage' => 'failed',
                'message' => (string) ($result['message'] ?? 'WordPress installation failed.'),
            ]);

            return;
        }

        $updatedWebsite = (array) ($result['website'] ?? []);
        $website->forceFill([
            'wordpress_db_prefix' => (string) ($updatedWebsite['wordpress_db_prefix'] ?? $website->wordpress_db_prefix),
            'status' => (string) ($updatedWebsite['status'] ?? $website->status),
        ])->save();

        WordpressInstallJobStatus::set($this->installId, [
            'stage' => 'ready',
            'message' => (string) ($result['message'] ?? 'WordPress installed and configured successfully.'),
            'website' => $website->fresh()?->toArray(),
            'database_request' => $result['database_request'] ?? null,
        ]);
    }
}
