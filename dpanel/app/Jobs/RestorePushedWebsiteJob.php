<?php

namespace App\Jobs;

use App\Http\Controllers\SitePushApiController;
use App\Models\User;
use App\Models\WebsitePushToken;
use App\Services\Backup\ClonePackageRestorer;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Live side of Push to Live: restores a package another panel finished
 * uploading (SitePushApiController::complete) into the token's website. The
 * site's current files go to its .trash first, since this overwrites a live
 * site with whatever was pushed.
 */
class RestorePushedWebsiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public int $tokenId,
        public string $packagePath,
        public string $sourceDomain = '',
    ) {
        $this->onQueue('heavy');
    }

    public function handle(ClonePackageRestorer $restorer, NotificationService $notifications): void
    {
        $token = WebsitePushToken::query()->with('website')->find($this->tokenId);
        $target = $token?->website;
        $user = $token?->user_id ? User::find($token->user_id) : null;
        $user ??= $target?->assigned_user_id ? User::find($target->assigned_user_id) : null;
        if (! $token || ! $target || ! $user) {
            $this->markFailed($token, 'The target website or its owner no longer exists.');

            return;
        }

        try {
            $restorer->restore($user, $target, $this->packagePath, 'push_overwrite', fn (string $stage) => CloneShareJobStatus::set($this->jobId, ['stage' => $stage]));
        } catch (Throwable $e) {
            $this->markFailed($token, $e->getMessage());
            $notifications->notifyUser(
                actor: $user,
                type: 'clone_website',
                status: 'blocked',
                title: "Push to live failed: {$target->domain}",
                message: $e->getMessage(),
                subject: $target,
            );

            return;
        } finally {
            $this->cleanup();
        }

        $from = $this->sourceDomain !== '' ? " from {$this->sourceDomain}" : '';
        CloneShareJobStatus::set($this->jobId, ['stage' => 'completed', 'message' => "{$target->domain} was updated{$from}. Previous files are in the site's .trash."]);
        $token->forceFill(['last_push_status' => 'completed', 'last_push_message' => 'Pushed'.$from.' at '.now()->toDateTimeString()])->save();
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'completed',
            title: "Pushed to live: {$target->domain}",
            message: $from !== '' ? 'Received'.$from : null,
            subject: $target,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(WebsitePushToken::find($this->tokenId), 'Restore stopped: '.($exception?->getMessage() ?: 'worker timeout or crash'));
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
