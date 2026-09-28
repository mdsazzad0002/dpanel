<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;

/**
 * Progress of a queued AppInstallJob (Joomla, CodeIgniter), polled by
 * AppInstaller.vue. Mirrors LaravelInstallJobStatus.
 */
class AppInstallJobStatus
{
    private const TTL_HOURS = 2;

    public static function key(string $jobId): string
    {
        return 'app-install-job:'.$jobId;
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
