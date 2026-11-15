<?php

namespace App\Console\Commands;

use App\Services\Local\LocalHostsSync;
use Illuminate\Console\Command;

/** Points every website's name at this PC in the hosts file (local profile, F6). */
class SyncLocalHostsCommand extends Command
{
    protected $signature = 'dpanel:hosts-sync';

    protected $description = 'Point every website name at this PC in the hosts file (local install only)';

    public function handle(LocalHostsSync $hosts): int
    {
        if (! LocalHostsSync::enabled()) {
            $this->error('Only a local install (DPANEL_PROFILE=local) manages the hosts file.');

            return self::FAILURE;
        }
        $outcome = $hosts->sync();
        if ($outcome === 'declined') {
            $this->warn('Not changed: the administrator prompt was declined.');

            return self::FAILURE;
        }
        $this->info(sprintf('hosts file %s: %d name(s).', $outcome, count($hosts->names())));

        return self::SUCCESS;
    }
}
