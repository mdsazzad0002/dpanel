<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\WordpressStaging;
use App\Services\Wordpress\WordpressHealthCheck;
use App\Services\Wordpress\WpCliClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Backlog W5: after production was copied over its staging site, the update
 * runs on staging first. Production is only updated when staging still
 * works; otherwise production is left as it was and the reasons are kept.
 */
class WordpressSafeUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /** @param  list<string>  $names */
    public function __construct(public int $stagingId, public string $kind, public array $names, public string $productionHome)
    {
        $this->onQueue('heavy');
    }

    public function handle(WpCliClient $wp, WordpressHealthCheck $health): void
    {
        $link = WordpressStaging::query()->find($this->stagingId);
        if (! $link instanceof WordpressStaging || $link->direction !== WordpressStaging::SAFE_UPDATE || $link->stage !== 'testing') {
            return;
        }
        $staging = $link->staging;
        $production = $link->production;
        if (! $staging instanceof Website || ! $production instanceof Website) {
            $link->update(['stage' => 'failed', 'message' => 'One of the two websites no longer exists.']);

            return;
        }

        $stagingHome = WordpressStagingFinishJob::targetHome($this->productionHome, (string) $staging->domain);
        $mark = $health->logMark($staging);
        // A site that was already broken is not the update's fault, but it
        // cannot prove the update safe either.
        $already = $health->problems($staging, $stagingHome, $mark);
        if ($already !== []) {
            $this->fail($link, 'The staging copy was already broken before the update, so nothing was changed: '.implode(' ', $already));

            return;
        }

        try {
            $this->update($wp, $staging);
        } catch (\RuntimeException $e) {
            $this->fail($link, 'The update failed on staging, so production was not touched: '.$e->getMessage());

            return;
        }
        $problems = $health->problems($staging, $stagingHome, $mark);
        if ($problems !== []) {
            $this->fail($link, 'Staging broke after the update, so production was not touched: '.implode(' ', $problems));

            return;
        }

        $link->update(['stage' => 'updating', 'message' => 'Staging passed; updating production.']);
        $mark = $health->logMark($production);
        try {
            $this->update($wp, $production);
        } catch (\RuntimeException $e) {
            $this->fail($link, 'Staging passed, but the update failed on production: '.$e->getMessage());

            return;
        }
        $after = $health->problems($production, $this->productionHome, $mark);
        $link->update([
            'stage' => $after === [] ? 'done' : 'failed',
            'message' => $after === []
                ? 'Updated: staging passed, then production was updated.'
                : 'Staging passed, but production shows problems after the update: '.implode(' ', $after),
            'deployed_at' => now(),
        ]);
    }

    private function update(WpCliClient $wp, Website $website): void
    {
        $results = $wp->run($website, 'update_'.$this->kind, ['names' => $this->names]);
        $failed = collect($results)->reject(fn (array $result) => $result['ok']);
        if ($failed->isNotEmpty()) {
            throw new \RuntimeException($failed->map(fn (array $result) => trim($result['error'] ?: $result['output']))->filter()->implode(' ') ?: 'wp-cli reported a failure.');
        }
    }

    private function fail(WordpressStaging $link, string $message): void
    {
        $link->update(['stage' => 'failed', 'message' => mb_strimwidth($message, 0, 2000, '…')]);
    }
}
