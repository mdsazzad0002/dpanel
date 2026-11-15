<?php

namespace App\Jobs;

use App\Services\Local\LocalHostsSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Brings the hosts file in line with the websites (F6). Queued, so the wait
 * for Windows' administrator prompt never holds up a page; unique, so
 * creating several sites asks once. It reads the list when it runs.
 */
class SyncLocalHostsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 300;

    public function handle(LocalHostsSync $hosts): void
    {
        if (! LocalHostsSync::enabled()) {
            return;
        }
        try {
            $outcome = $hosts->sync();
        } catch (\Throwable $e) {
            Log::warning('Updating the hosts file failed: '.$e->getMessage());

            return;
        }
        if ($outcome === 'declined') {
            Log::info('The hosts file was not updated: the administrator prompt was declined. Run `php artisan dpanel:hosts-sync` to try again.');
        }
    }
}
