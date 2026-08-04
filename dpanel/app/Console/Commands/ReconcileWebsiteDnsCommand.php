<?php

namespace App\Console\Commands;

use App\Services\Dns\WebsiteDnsProvisionService;
use Illuminate\Console\Command;

class ReconcileWebsiteDnsCommand extends Command
{
    protected $signature = 'dns:reconcile-websites';

    protected $description = 'Reconcile website DNS zones based on current nameservers.';

    public function handle(WebsiteDnsProvisionService $service): int
    {
        $result = $service->reconcileAll();

        $this->info(sprintf(
            'DNS reconcile complete: managed=%d created=%d reactivated=%d skipped=%d',
            $result['managed'],
            $result['created'],
            $result['reactivated'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
