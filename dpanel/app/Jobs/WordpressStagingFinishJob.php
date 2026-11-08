<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\WordpressStaging;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\Wordpress\WpCliClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs after the CloneWebsiteJob of a staging copy or deploy (backlog W3):
 * the copied database still names the source's address, so it is rewritten
 * to the target's with `wp search-replace`.
 */
class WordpressStagingFinishJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $stagingId, public string $sourceHome)
    {
        $this->onQueue('heavy');
    }

    public function handle(WpCliClient $wp): void
    {
        $staging = WordpressStaging::query()->find($this->stagingId);
        if (! $staging instanceof WordpressStaging || $staging->clone_id === null) {
            return;
        }

        $clone = CloneShareJobStatus::get($staging->clone_id);
        if (($clone['stage'] ?? '') !== 'ready') {
            $staging->update(['stage' => 'failed', 'message' => (string) ($clone['message'] ?? 'The copy did not finish.')]);

            return;
        }

        // Only a deploy copies onto production; a safe update copies to staging too.
        $toProduction = $staging->direction === WordpressStaging::TO_PRODUCTION;
        $target = $toProduction ? $staging->production : $staging->staging;
        if (! $target instanceof Website) {
            $staging->update(['stage' => 'failed', 'message' => 'The target website no longer exists.']);

            return;
        }

        $staging->update(['stage' => 'rewriting']);
        try {
            $wp->run($target, 'search_replace', ['from' => $this->sourceHome, 'to' => self::targetHome($this->sourceHome, (string) $target->domain)]);
        } catch (\RuntimeException $e) {
            $staging->update(['stage' => 'failed', 'message' => 'Copied, but the addresses could not be rewritten: '.$e->getMessage()]);

            return;
        }

        $staging->update([
            // A safe update goes on in WordpressSafeUpdateJob.
            'stage' => $staging->direction === WordpressStaging::SAFE_UPDATE ? 'testing' : 'done',
            'message' => null,
            $toProduction ? 'deployed_at' : 'copied_at' => now(),
        ]);
    }

    /** The source's address with the target's host: same scheme, port and path. */
    public static function targetHome(string $sourceHome, string $targetDomain): string
    {
        $parts = parse_url($sourceHome);
        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.strtolower($targetDomain).$port.rtrim((string) ($parts['path'] ?? ''), '/');
    }
}
