<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Progress of a queued RestoreBackupJob, polled by Backups/Index.vue via
 * BackupController::restoreStatus. The job owns the overall stage
 * (queued/running/completed/failed); while it waits on dRust, dRust writes
 * its own step + percent to progressPath(), which get() overlays so the UI
 * can show exactly which step (extract, database import, …) a restore is on
 * or died in.
 */
class BackupRestoreStatus
{
    private const TTL_HOURS = 6;

    public static function key(string $restoreId): string
    {
        return 'backup-restore-job:'.$restoreId;
    }

    public static function progressPath(string $restoreId): string
    {
        return storage_path('app/restore-progress/'.$restoreId.'.json');
    }

    public static function set(string $restoreId, array $data): void
    {
        $current = Cache::get(self::key($restoreId));
        Cache::put(
            self::key($restoreId),
            array_merge(is_array($current) ? $current : [], ['updated_at' => now()->toIso8601String()], $data),
            now()->addHours(self::TTL_HOURS)
        );
    }

    public static function get(string $restoreId): ?array
    {
        $status = Cache::get(self::key($restoreId));
        if (! is_array($status)) {
            return null;
        }
        $progress = self::readProgress($restoreId);
        if (($status['stage'] ?? '') === 'running' && $progress !== null) {
            $status['step'] = $progress['stage'] ?? $status['step'] ?? null;
            $status['percent'] = $progress['percent'] ?? $status['percent'] ?? 0;
            $status['message'] = $progress['message'] ?? $status['message'] ?? '';
        }

        return $status;
    }

    /** @return array{stage?:string,percent?:int,message?:string,failed_stage?:string}|null */
    public static function readProgress(string $restoreId): ?array
    {
        $path = self::progressPath($restoreId);
        if (! File::isFile($path)) {
            return null;
        }
        $data = json_decode((string) @File::get($path), true);

        return is_array($data) ? $data : null;
    }

    public static function clearProgress(string $restoreId): void
    {
        File::delete(self::progressPath($restoreId));
    }
}
