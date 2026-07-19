<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Website\WebsiteWebServerSyncService;
use Illuminate\Console\Command;

class ReconcileSslCertificatesCommand extends Command
{
    protected $signature = 'serverpanel:ssl-reconcile';
    protected $description = 'Inspect and issue or renew enabled website certificates through drust';

    public function handle(SslLifecycleService $ssl, WebsiteWebServerSyncService $vhosts): int
    {
        $failed = false;
        Website::query()->where('enable_ssl', true)->orderBy('id')->chunk(50, function ($websites) use ($ssl, $vhosts, &$failed): void {
            foreach ($websites as $website) {
                try {
                    $ssl->ensureForWebsite($website);
                    $vhosts->syncWebsite($website);
                    $this->info("SSL valid: {$website->domain}");
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("SSL failed for {$website->domain}: {$e->getMessage()}");
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
