<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\NotificationService;
use App\Services\Tasks\BackgroundTaskQueue;
use App\Services\Website\WebsiteDeletionFailed;
use App\Services\Website\WebsiteDeletionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Deletes one website off the request path. Archiving a large site's files and
 * databases takes minutes, which used to hold the browser (and a PHP-FPM
 * worker) for the whole run, so the controller only queues this job and the
 * websites list follows it through BackgroundTaskQueue.
 *
 * Several deletions run side by side, but not without a cap: the job takes one
 * of the type's concurrency slots (3 by default — dpanel.background_tasks
 * .concurrency.website_delete) and releases itself back to the queue while all
 * of them are busy, so a long queue drains a few at a time instead of putting
 * every site's archive on the server at once. The 'deletions' queue runs as
 * many worker processes to match.
 */
class DeleteWebsiteJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** The task type its cards are shown under. */
    public const TASK_TYPE = 'website_delete';

    public int $timeout = 3600;

    /**
     * Unlimited attempts, because the job releases itself back to the queue
     * while another deletion holds the lock and a release counts as an
     * attempt. A real failure never retries: handle() catches everything and
     * marks the card failed instead. retryUntil() still bounds the waiting.
     */
    public int $tries = 0;

    public function __construct(
        public string $taskId,
        public string $websiteId,
        public int $userId,
        public bool $toTrash = true,
        public bool $dropDatabases = false,
    ) {
        // Its own queue, so a deletion never waits behind a big backup or
        // export on 'heavy'. Its workers run with a 3600s timeout and several
        // processes (see dscript/repository/templates/supervisor).
        $this->onQueue('deletions');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(WebsiteDeletionService $deletion, NotificationService $notifications): void
    {
        $lock = BackgroundTaskQueue::acquireSlot(self::TASK_TYPE, 3700);

        if ($lock === null) {
            // Every slot is busy: come back shortly, keeping the card in its
            // 'queued' state meanwhile.
            $this->release(5);

            return;
        }

        try {
            $this->deleteWebsite($deletion, $notifications);
        } catch (\Throwable $e) {
            // Nothing is retried: the card carries the failure instead.
            Log::error('Queued website deletion crashed', [
                'website_id' => $this->websiteId,
                'error' => $e->getMessage(),
            ]);
            BackgroundTaskQueue::set($this->taskId, [
                'stage' => BackgroundTaskQueue::STAGE_FAILED,
                'message' => 'Website deletion failed. '.$e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function deleteWebsite(WebsiteDeletionService $deletion, NotificationService $notifications): void
    {
        $user = User::find($this->userId);
        $website = $user ? Website::query()->visibleTo($user)->find($this->websiteId) : null;

        if (! $user instanceof User || ! $website instanceof Website) {
            BackgroundTaskQueue::set($this->taskId, [
                'stage' => BackgroundTaskQueue::STAGE_FAILED,
                'message' => 'Website account not found.',
            ]);

            return;
        }

        $domain = (string) $website->domain;
        BackgroundTaskQueue::set($this->taskId, ['stage' => BackgroundTaskQueue::STAGE_RUNNING, 'title' => $domain, 'message' => '']);

        try {
            $cleanupMessages = $deletion->delete($website, $user->id, $this->toTrash, $this->dropDatabases);
        } catch (\Throwable $e) {
            $previous = $e instanceof WebsiteDeletionFailed ? ($e->getPrevious() ?? $e) : $e;
            $message = $e instanceof WebsiteDeletionFailed
                ? trim($e->getMessage().' '.$previous->getMessage())
                : 'Website deletion failed. '.$e->getMessage();

            Log::error('Queued website deletion failed', [
                'website_id' => $this->websiteId,
                'domain' => $domain,
                'key' => $e instanceof WebsiteDeletionFailed ? $e->key : 'unexpected',
                'error' => $previous->getMessage(),
            ]);

            BackgroundTaskQueue::set($this->taskId, ['stage' => BackgroundTaskQueue::STAGE_FAILED, 'message' => $message]);
            $notifications->notifyUser(
                actor: $user,
                type: self::TASK_TYPE,
                status: 'blocked',
                title: "Website deletion failed: {$domain}",
                message: $message,
                data: ['website_id' => $this->websiteId, 'domain' => $domain],
            );

            return;
        }

        BackgroundTaskQueue::set($this->taskId, [
            'stage' => BackgroundTaskQueue::STAGE_DONE,
            'message' => implode(' ', $cleanupMessages),
        ]);

        $notifications->notifyUser(
            actor: $user,
            type: self::TASK_TYPE,
            status: 'completed',
            title: "Website deleted: {$domain}",
            message: $cleanupMessages === [] ? null : implode(' ', $cleanupMessages),
            data: ['website_id' => $this->websiteId, 'domain' => $domain],
        );
    }

    public function failed(\Throwable $e): void
    {
        BackgroundTaskQueue::set($this->taskId, [
            'stage' => BackgroundTaskQueue::STAGE_FAILED,
            'message' => 'Website deletion failed. '.$e->getMessage(),
        ]);
    }
}
