<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ResticBackupService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Backlog W7: one file, folder, database or mailbox back from a restore
 * point. The page polls `status()`; the user is also notified.
 */
class RestoreResticItemJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 21600;

    public int $tries = 1;

    /** @param  array<string, string>  $item */
    public function __construct(
        public string $restoreId,
        public int $userId,
        public string $websiteId,
        public string $snapshotId,
        public array $item,
    ) {
        $this->onQueue('heavy');
    }

    public static function key(string $restoreId): string
    {
        return 'restic-restore:'.$restoreId;
    }

    /** @return array<string, mixed>|null */
    public static function status(string $restoreId): ?array
    {
        $status = Cache::get(self::key($restoreId));

        return is_array($status) ? $status : null;
    }

    public static function record(string $restoreId, int $userId, string $stage, string $message, array $result = []): void
    {
        Cache::put(self::key($restoreId), [
            'id' => $restoreId, 'user_id' => $userId, 'stage' => $stage, 'message' => $message, 'result' => $result,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDay());
    }

    public function handle(ResticBackupService $restic, NotificationService $notifications): void
    {
        $website = Website::query()->find($this->websiteId);
        $user = User::query()->find($this->userId);
        if (! $website || ! $user) {
            self::record($this->restoreId, $this->userId, 'failed', 'The website or its user is gone.');

            return;
        }
        self::record($this->restoreId, $this->userId, 'running', 'Restoring…');

        try {
            $result = $restic->restore($website, $this->snapshotId, $this->item);
        } catch (Throwable $exception) {
            self::record($this->restoreId, $this->userId, 'failed', 'Restore failed: '.$exception->getMessage());
            $notifications->notifyUser($user, 'backup', 'failed', "Restore on {$website->domain} failed", $exception->getMessage(), $website);

            return;
        }

        $message = match ($result['kind'] ?? '') {
            'database' => "Database {$result['database']} is back as it was. What it held before is saved at {$result['before_restore']}.",
            'mailbox' => "The messages are back in the mail folder {$result['folder']}.",
            default => 'Restored to '.($result['restored_to'] ?? 'your home folder').'. Nothing in place was changed; move what you need.',
        };
        self::record($this->restoreId, $this->userId, 'completed', $message, $result);
        $notifications->notifyUser($user, 'backup', 'completed', "Restore on {$website->domain} finished", $message, $website);
    }
}
