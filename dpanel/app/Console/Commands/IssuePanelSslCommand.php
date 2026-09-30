<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Ssl\SslLifecycleService;
use Illuminate\Console\Command;

class IssuePanelSslCommand extends Command
{
    protected $signature = 'serverpanel:panel-ssl';
    protected $description = 'Issue or verify the certificate for the panel domain (website id 1) and its aliases';

    public function handle(SslLifecycleService $ssl): int
    {
        $panel = Website::query()->find('1');
        if (! $panel || trim((string) $panel->domain) === '') {
            $this->error('The panel website (id 1) is not configured yet.');

            return self::FAILURE;
        }

        $wasEnabled = (bool) $panel->enable_ssl;
        $panel->forceFill(['enable_ssl' => true])->save();

        try {
            $result = $ssl->ensureForWebsite($panel->fresh());
        } catch (\Throwable $e) {
            // Leave the panel reachable over plain HTTP instead of pointing it at a missing certificate.
            $panel->forceFill(['enable_ssl' => $wasEnabled])->save();
            $this->error("SSL failed for {$panel->domain}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $action = ! empty($result['renewed']) ? 'renewed' : (! empty($result['issued']) ? 'issued' : 'verified');
        $this->info("SSL {$action}: {$panel->domain}");

        foreach (Website::query()->where('parent_id', $panel->id)->get() as $alias) {
            $aliasWasEnabled = (bool) $alias->enable_ssl;
            $alias->forceFill(['enable_ssl' => true])->save();
            try {
                $ssl->ensureForWebsite($alias->fresh());
                $this->info("SSL valid: {$alias->domain}");
            } catch (\Throwable $e) {
                $alias->forceFill(['enable_ssl' => $aliasWasEnabled])->save();
                $this->warn("SSL failed for alias {$alias->domain}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
