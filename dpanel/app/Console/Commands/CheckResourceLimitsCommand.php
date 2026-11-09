<?php

namespace App\Console\Commands;

use App\Services\ResourceLimitService;
use Illuminate\Console\Command;

class CheckResourceLimitsCommand extends Command
{
    protected $signature = 'accounts:resource-check';

    protected $description = 'Give each account its package CPU and memory limit and sample its use';

    public function handle(ResourceLimitService $limits): int
    {
        $result = $limits->checkAll();
        $this->info("Checked {$result['checked']} limited account(s).");
        if ($result['unsupported'] > 0) {
            $this->warn("{$result['unsupported']} account(s) have a CPU or memory limit this server cannot enforce.");
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
