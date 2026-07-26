<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Website\WebsiteWebServerSyncService;
use Illuminate\Console\Command;

class ResyncWebsiteVhostsCommand extends Command
{
    protected $signature = 'serverpanel:vhost-resync {--domain= : Only resync this domain}';
    protected $description = 'Regenerate Apache/Nginx vhost files for every website from the current templates';

    public function handle(WebsiteWebServerSyncService $vhosts): int
    {
        $domain = trim((string) $this->option('domain'));
        $query = Website::query()->orderBy('id');
        if ($domain !== '') {
            $query->where('domain', $domain);
        }

        $synced = 0;
        $failed = 0;
        $query->chunk(50, function ($websites) use ($vhosts, &$synced, &$failed): void {
            foreach ($websites as $website) {
                try {
                    $vhosts->syncWebsite($website);
                    $synced++;
                    $this->info("Vhost synced: {$website->domain}");
                } catch (\Throwable $e) {
                    // One broken website must not stop the rest of the server.
                    $failed++;
                    $this->error("Vhost sync failed for {$website->domain}: {$e->getMessage()}");
                }
            }
        });

        $this->line("Synced {$synced} website(s), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
