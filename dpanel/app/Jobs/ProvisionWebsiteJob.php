<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Website\WebsiteWebServerSyncService;
use App\Services\Website\WebsiteLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionWebsiteJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        public string $websiteId,
        public string $action = 'create',
    ) {
    }

    public function handle(
        WebsiteLifecycleService $lifecycle,
        WebsiteWebServerSyncService $webServerSync,
    ): void {
        $website = Website::find($this->websiteId);
        if (! $website) {
            return;
        }

        match ($this->action) {
            'create' => $lifecycle->provision($website),
            'delete' => $lifecycle->deprovision($website),
            'suspend' => $lifecycle->suspend($website),
            'restore' => $lifecycle->restore($website),
            'sync' => $webServerSync->sync($website),
            default => null,
        };
    }
}
