<?php

namespace App\Services\Backup;

use App\Jobs\DeleteQuickExportFileJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stashes a freshly built export behind a token so it can be fetched as a plain
 * GET download (native browser download UI) instead of loading the whole file
 * into JS as a blob. Deliberately not gated by the panel session/cookie — the
 * link is meant to be shareable with someone who isn't logged in — so the
 * random token itself is the only credential; it's short-lived and
 * single-purpose. The link stays valid, and the file stays on disk, for
 * TTL_HOURS so a manual "Download" retry still works well after the export
 * finished; a queued job (not the cache entry's own expiry) is what actually
 * deletes the file once that window closes.
 */
class QuickExportLinkFactory
{
    public const TTL_HOURS = 3;

    public function make(string $websiteId, string $path, string $fileName): string
    {
        $downloadToken = (string) Str::uuid();
        Cache::put('quick-export-download:'.$downloadToken, [
            'path' => $path,
            'file_name' => $fileName,
            'website_id' => $websiteId,
        ], now()->addHours(self::TTL_HOURS));

        DeleteQuickExportFileJob::dispatch($path, $downloadToken)
            ->delay(now()->addHours(self::TTL_HOURS));

        return route('quick-exports.download', ['downloadToken' => $downloadToken]);
    }
}
