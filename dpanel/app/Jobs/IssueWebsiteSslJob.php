<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Ssl\SslLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Issues a new website's or alias's certificate in the background, so
 * creating the site does not wait on the ACME validation. Failures are
 * recorded instead of thrown, so the next certificate in a chain still runs.
 */
class IssueWebsiteSslJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    // drust's SSL ensure call may take up to 300s on its own.
    public int $timeout = 330;

    // Let's Encrypt rate-limits failed validations; a retry rarely helps.
    public int $tries = 1;

    /**
     * @param  bool  $disableSslOnFailure  Turn SSL off again when issuing fails (the auto-created www alias).
     */
    public function __construct(
        public string $websiteId,
        public bool $disableSslOnFailure = false,
    ) {
        $this->onQueue('default');
    }

    public function handle(SslLifecycleService $ssl): void
    {
        $website = Website::query()->find($this->websiteId);
        if ($website === null || ! $website->enable_ssl) {
            return;
        }

        try {
            $ssl->ensureForWebsite($website);
        } catch (\Throwable $e) {
            $website->forceFill($this->disableSslOnFailure
                ? ['enable_ssl' => false, 'ssl_mode' => 'none']
                : ['ssl_mode' => 'none'])->saveQuietly();

            Log::warning('Website SSL issue failed', [
                'website_id' => $website->id,
                'domain' => $website->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
