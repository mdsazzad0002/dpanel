<?php

namespace App\Console\Commands;

use App\Services\AccountTerminationService;
use Illuminate\Console\Command;

class PurgeTerminatedAccountsCommand extends Command
{
    protected $signature = 'accounts:purge-terminated';

    protected $description = 'Archive and delete accounts whose termination grace period has ended';

    public function handle(AccountTerminationService $termination): int
    {
        $result = $termination->purgeDue();
        $this->info("Deleted {$result['purged']} terminated account(s); {$result['failed']} failed and stay for the next run.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
