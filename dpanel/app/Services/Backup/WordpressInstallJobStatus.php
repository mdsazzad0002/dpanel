<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;

/**
 * Shared read/write for a queued WordpressInstallJob's progress, polled by
 * the install button on WordPressInstaller.vue so it can show a step-by-step
 * progress bar (downloading → creating database → connecting database)
 * instead of a single opaque "Applying..." state. Mirrors CloneShareJobStatus.
 */
class WordpressInstallJobStatus
{
    private const TTL_HOURS = 1;

    public static function key(string $jobId): string
    {
        return 'wordpress-install-job:'.$jobId;
    }

    public static function set(string $jobId, array $data): void
    {
        Cache::put(self::key($jobId), array_merge(['updated_at' => now()->toIso8601String()], $data), now()->addHours(self::TTL_HOURS));
    }

    public static function get(string $jobId): ?array
    {
        $status = Cache::get(self::key($jobId));

        return is_array($status) ? $status : null;
    }
}
