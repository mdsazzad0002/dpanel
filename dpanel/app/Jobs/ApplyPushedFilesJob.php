<?php

namespace App\Jobs;

use App\Http\Controllers\SitePushApiController;
use App\Models\User;
use App\Models\WebsitePushToken;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\NotificationService;
use App\Services\SitePush\FileSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Live side of a "Files only" Push to Live: writes the changed files another
 * panel uploaded into the token's website and removes the ones it deleted.
 * Unlike RestorePushedWebsiteJob the rest of the site — ignored files, the
 * database, .git — stays as it is; only the files replaced or removed are
 * moved to the site's .trash.
 */
class ApplyPushedFilesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public int $tokenId,
        public string $packagePath,
        public array $delete = [],
        public string $sourceDomain = '',
    ) {
        $this->onQueue('heavy');
    }

    public function handle(FileSyncService $sync, NotificationService $notifications): void
    {
        $token = WebsitePushToken::query()->with('website')->find($this->tokenId);
        $target = $token?->website;
        $user = $token?->user_id ? User::find($token->user_id) : null;
        $user ??= $target?->assigned_user_id ? User::find($target->assigned_user_id) : null;
        if (! $token || ! $target || ! $user) {
            $this->markFailed($token, 'The target website or its owner no longer exists.');

            return;
        }

        CloneShareJobStatus::set($this->jobId, ['stage' => 'applying']);
        try {
            $result = $sync->apply($target, $this->packagePath, $this->delete);
        } catch (Throwable $e) {
            $this->markFailed($token, $e->getMessage());
            $notifications->notifyUser(actor: $user, type: 'clone_website', status: 'blocked', title: "File sync failed: {$target->domain}", message: $e->getMessage(), subject: $target);

            return;
        } finally {
            $this->cleanup();
        }

        $from = $this->sourceDomain !== '' ? " from {$this->sourceDomain}" : '';
        $summary = "{$result['written']} files updated, {$result['deleted']} removed{$from}";
        CloneShareJobStatus::set($this->jobId, [
            'stage' => 'completed',
            'written' => $result['written'],
            'deleted' => $result['deleted'],
            'message' => "{$target->domain}: {$summary}. Replaced files are in the site's .trash.",
        ]);
        $token->forceFill(['last_push_status' => 'completed', 'last_push_message' => 'File sync: '.$summary.' at '.now()->toDateTimeString()])->save();
        $notifications->notifyUser(actor: $user, type: 'clone_website', status: 'completed', title: "Files synced: {$target->domain}", message: $summary, subject: $target);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(WebsitePushToken::find($this->tokenId), 'File sync stopped: '.($exception?->getMessage() ?: 'worker timeout or crash'));
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $directory = dirname($this->packagePath);
        if (str_starts_with($directory, dirname(SitePushApiController::directory('x')).'/')) {
            File::deleteDirectory($directory);
        }
    }

    private function markFailed(?WebsitePushToken $token, string $message): void
    {
        CloneShareJobStatus::set($this->jobId, ['stage' => 'failed', 'message' => $message]);
        $token?->forceFill(['last_push_status' => 'failed', 'last_push_message' => mb_substr($message, 0, 1000)])->save();
    }
}
