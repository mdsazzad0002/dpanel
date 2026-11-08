<?php

namespace App\Console\Commands;

use App\Services\DiskUsageService;
use Illuminate\Console\Command;

class CheckDiskUsageCommand extends Command
{
    protected $signature = 'accounts:disk-check';

    protected $description = 'Measure each account\'s disk use against its package limit';

    public function handle(DiskUsageService $usage): int
    {
        $result = $usage->checkAll();
        $this->info("Checked {$result['checked']} account(s); {$result['over']} over the limit; {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
