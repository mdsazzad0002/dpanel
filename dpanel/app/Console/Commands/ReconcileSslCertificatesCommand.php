<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Ssl\CustomCertificateExpiryAlert;
use App\Services\Ssl\SslLifecycleService;
use Illuminate\Console\Command;

class ReconcileSslCertificatesCommand extends Command
{
    protected $signature = 'dpanel:ssl-reconcile';
    protected $description = 'Inspect and issue or renew enabled website certificates through drust';

    public function handle(SslLifecycleService $ssl, CustomCertificateExpiryAlert $expiry): int
    {
        $failed = false;
        Website::query()->where('enable_ssl', true)->orderBy('id')->chunk(50, function ($websites) use ($ssl, &$failed): void {
            foreach ($websites as $website) {
                try {
                    $ssl->ensureForWebsite($website);
                    $this->info("SSL valid: {$website->domain}");
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("SSL failed for {$website->domain}: {$e->getMessage()}");
                }
            }
        });

        $warned = $expiry->run();
        if ($warned > 0) {
            $this->warn("Uploaded certificates close to expiry: {$warned} owner(s) notified.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
