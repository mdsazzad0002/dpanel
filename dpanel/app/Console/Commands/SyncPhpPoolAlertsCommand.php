<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Platform spec section 9: where PHP runs as a php-cgi pool (Windows), a site
 * whose PHP crashes too often answers 503 for a while. drust keeps an alert
 * for it; this turns each new one into a notification for the site's owner,
 * their reseller and the admins.
 */
class SyncPhpPoolAlertsCommand extends Command
{
    public const LAST_SEEN = 'php_pool_alerts.last_id';

    /** On a first run, older alerts are history, not news. */
    private const FIRST_RUN_WINDOW_MS = 10 * 60 * 1000;

    protected $signature = 'php:pool-alerts';

    protected $description = 'Notify about sites whose PHP kept crashing, from drust';

    public function handle(NotificationService $notifications): int
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return self::SUCCESS;
        }
        $lastSeen = Cache::get(self::LAST_SEEN);
        $after = is_numeric($lastSeen) ? (int) $lastSeen : (int) floor(microtime(true) * 1000) - self::FIRST_RUN_WINDOW_MS;

        try {
            $response = Http::acceptJson()->timeout(5)
                ->withToken(trim((string) config('dpanel.execution_api_token', '')))
                ->get(rtrim($baseUrl, '/').'/php-pool/alerts', ['after' => max(0, $after)]);
        } catch (\Throwable $error) {
            // drust may be restarting; the next minute asks again.
            $this->warn('drust did not answer: '.$error->getMessage());

            return self::SUCCESS;
        }
        $alerts = $response->json('alerts');
        if (! $response->successful() || ! is_array($alerts)) {
            return self::SUCCESS;
        }

        foreach ($alerts as $alert) {
            $id = (int) data_get($alert, 'id', 0);
            $site = (string) data_get($alert, 'site', '');
            if ($id <= $after || $site === '') {
                continue;
            }
            $this->notify($notifications, $site, (int) data_get($alert, 'crashes', 0), (int) data_get($alert, 'window_seconds', 0));
            $after = $id;
        }
        Cache::forever(self::LAST_SEEN, $after);

        return self::SUCCESS;
    }

    private function notify(NotificationService $notifications, string $site, int $crashes, int $window): void
    {
        // The pool is per account; any of its websites names the owner.
        $website = Website::query()->where('site_owner', $site)->orderBy('id')->first();
        $notifications->create(
            type: 'php_pool',
            status: 'failed',
            title: "PHP keeps crashing for {$site}",
            message: "PHP stopped {$crashes} times within {$window} seconds, so the account's websites answer 503 for a while. Check the website's PHP error log.",
            assignedUserId: $website?->assigned_user_id,
            assignedResellerId: $website?->assigned_reseller_id,
            subject: $website,
            data: ['site_owner' => $site, 'crashes' => $crashes, 'window_seconds' => $window],
        );
    }
}
