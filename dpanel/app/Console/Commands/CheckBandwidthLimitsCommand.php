<?php

namespace App\Console\Commands;

use App\Services\Bandwidth\BandwidthLimitService;
use Illuminate\Console\Command;

class CheckBandwidthLimitsCommand extends Command
{
    protected $signature = 'accounts:bandwidth-check';

    protected $description = 'Compare each account\'s monthly traffic with its package limit';

    public function handle(BandwidthLimitService $limits): int
    {
        $result = $limits->checkAll();
        $this->info("Checked {$result['checked']} account(s); {$result['over']} over the bandwidth limit.");

        return self::SUCCESS;
    }
}
