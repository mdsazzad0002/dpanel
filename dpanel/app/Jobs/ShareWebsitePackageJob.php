<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ClonePackageBuilder;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\Backup\CloneShareLinkFactory;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Share" half of Clone & Share: bundles the website's files + database into
 * one portable package (ClonePackageBuilder), then hands back a token + unauthenticated download URL
 * (via CloneShareLinkFactory) that a second server's admin can paste into
 * their own Clone & Share page to pull and restore the site there.
 */
class ShareWebsitePackageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $shareId,
        public string $websiteId,
        public int $userId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(ClonePackageBuilder $builder, CloneShareLinkFactory $links, NotificationService $notifications): void
    {
        CloneShareJobStatus::set($this->shareId, ['stage' => 'archiving']);

        $user = User::find($this->userId);
        $website = $user ? Website::query()->visibleTo($user)->find($this->websiteId) : null;
        if (! $user || ! $website) {
            CloneShareJobStatus::set($this->shareId, ['stage' => 'failed', 'message' => 'Website account not found.']);

            return;
        }

        $packagePath = storage_path('app/backups/clone-share/'.Str::uuid().'-package.zip');
        try {
            $builder->build($user, $website, $packagePath, fn (string $stage) => CloneShareJobStatus::set($this->shareId, ['stage' => $stage]));
        } catch (Throwable $e) {
            $this->fail($notifications, $user, $website, 'Share package failed: '.$e->getMessage());

            return;
        }

        $safeDomain = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $website->domain) ?: 'website';
        $fileName = $safeDomain.'-clone-'.now()->format('Y-m-d_H-i-s').'.zip';
        $link = $links->make($this->websiteId, $packagePath, $fileName);

        CloneShareJobStatus::set($this->shareId, ['stage' => 'ready', 'download_url' => $link['url'], 'token' => $link['token'], 'expires_at' => $link['expires_at']]);
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'completed',
            title: "Share link ready: {$website->domain}",
            subject: $website,
            data: ['download_url' => $link['url']],
        );
    }

    private function fail(NotificationService $notifications, User $user, Website $website, string $message): void
    {
        CloneShareJobStatus::set($this->shareId, ['stage' => 'failed', 'message' => $message]);
        $notifications->notifyUser(
            actor: $user,
            type: 'clone_website',
            status: 'blocked',
            title: "Share link failed: {$website->domain}",
            message: $message,
            subject: $website,
        );
    }
}
