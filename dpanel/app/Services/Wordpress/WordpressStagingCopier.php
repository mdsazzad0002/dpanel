<?php

namespace App\Services\Wordpress;

use App\Jobs\CloneWebsiteJob;
use App\Jobs\WordpressStagingFinishJob;
use App\Models\Website;
use App\Models\WordpressStaging;
use App\Services\Backup\CloneShareJobStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Starts a copy between the two sites of a staging pair (backlog W3): the
 * clone, then the address rewrite, then whatever `$then` adds (W5's test).
 */
class WordpressStagingCopier
{
    public function __construct(private readonly WpCliClient $wp) {}

    /**
     * @param  \Closure(string): list<object>|null  $then  given the source's address, jobs to run after the copy
     *
     * @throws \RuntimeException when the source's address cannot be read
     */
    public function start(WordpressStaging $link, Website $from, Website $to, string $direction, int $userId, ?\Closure $then = null): void
    {
        $home = trim($this->wp->run($from, 'home_url')['home']['output'] ?? '');
        if (preg_match('#^https?://#i', $home) !== 1) {
            throw new \RuntimeException("The site address of {$from->domain} could not be read.");
        }

        $cloneId = (string) Str::uuid();
        CloneShareJobStatus::set($cloneId, ['stage' => 'queued']);
        $link->update(['clone_id' => $cloneId, 'direction' => $direction, 'stage' => 'queued', 'message' => null]);

        Bus::chain([
            new CloneWebsiteJob($cloneId, (string) $from->id, (string) $to->id, $userId),
            new WordpressStagingFinishJob($link->id, $home),
            ...($then === null ? [] : $then($home)),
        ])->onQueue('heavy')->dispatch();
    }

    /** @return array<string, mixed> */
    public function describe(WordpressStaging $link): array
    {
        $stage = $link->stage;
        if ($link->busy() && $link->clone_id !== null) {
            $clone = CloneShareJobStatus::get($link->clone_id);
            if (($clone['stage'] ?? '') === 'failed') {
                $link->update(['stage' => 'failed', 'message' => (string) ($clone['message'] ?? 'The copy failed.')]);
                $stage = 'failed';
            } elseif ($stage === 'queued' && ! in_array($clone['stage'] ?? 'queued', ['queued', 'ready'], true)) {
                $stage = 'copying';
            }
        }

        return [
            'staging_website_id' => (string) $link->staging_website_id,
            'staging_domain' => (string) ($link->staging?->domain ?? ''),
            'direction' => $link->direction,
            'stage' => $stage,
            'message' => $link->message,
            'copied_at' => $link->copied_at?->toIso8601String(),
            'deployed_at' => $link->deployed_at?->toIso8601String(),
        ];
    }
}
