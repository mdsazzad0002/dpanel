<?php

namespace App\Services\Backup;

use App\Jobs\DeleteCloneSharePackageJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stashes a freshly built clone package (files + database, zipped together)
 * behind a token so a second server can pull it as a plain GET download
 * without a panel session — the random token is the only credential. Mirrors
 * QuickExportLinkFactory but with a longer TTL since the other server's admin
 * needs time to paste the URL/token into their own "Clone & Share" page.
 */
class CloneShareLinkFactory
{
    public const TTL_HOURS = 24;

    public function make(string $websiteId, string $path, string $fileName): array
    {
        $downloadToken = (string) Str::uuid();
        Cache::put('clone-share-download:'.$downloadToken, [
            'path' => $path,
            'file_name' => $fileName,
            'website_id' => $websiteId,
        ], now()->addHours(self::TTL_HOURS));

        DeleteCloneSharePackageJob::dispatch($path, $downloadToken)
            ->delay(now()->addHours(self::TTL_HOURS));

        return [
            'token' => $downloadToken,
            'url' => route('clone-share.download', ['downloadToken' => $downloadToken]),
            'expires_at' => now()->addHours(self::TTL_HOURS)->toIso8601String(),
        ];
    }
}
