<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;

/**
 * Shared read/write for a queued CloneWebsiteJob/ShareWebsitePackageJob/
 * ImportSharedWebsiteJob's progress, polled every 5s by the Clone/Share
 * controls embedded in QuickImport.vue and QuickExport.vue. Mirrors
 * QuickExportJobStatus.
 */
class CloneShareJobStatus
{
    private const TTL_HOURS = 6;

    public static function key(string $jobId): string
    {
        return 'clone-share-job:'.$jobId;
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
