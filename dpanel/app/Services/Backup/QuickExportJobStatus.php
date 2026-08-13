<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;

/**
 * Shared read/write for a queued QuickExportJob's progress, polled by the
 * frontend (every 5s while the Quick Export page is open) via
 * BackupController::quickExportStatus. Kept well past the job's own runtime
 * so a slow poll or a page reopened later still sees the final state.
 */
class QuickExportJobStatus
{
    private const TTL_HOURS = 6;

    public static function key(string $exportId): string
    {
        return 'quick-export-job:'.$exportId;
    }

    public static function set(string $exportId, array $data): void
    {
        Cache::put(self::key($exportId), array_merge(['updated_at' => now()->toIso8601String()], $data), now()->addHours(self::TTL_HOURS));
    }

    public static function get(string $exportId): ?array
    {
        $status = Cache::get(self::key($exportId));

        return is_array($status) ? $status : null;
    }
}
