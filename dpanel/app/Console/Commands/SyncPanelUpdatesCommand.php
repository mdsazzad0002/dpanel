<?php

namespace App\Console\Commands;

use App\Models\PanelUpdate;
use App\Services\PanelUpdate\PanelUpdateRefused;
use App\Services\PanelUpdate\PanelUpdateService;
use Illuminate\Console\Command;

/**
 * Backlog U6: tells admins how a dPanel update ended even when nobody keeps
 * System → Updates open. drust is only asked while a run is unfinished here.
 */
class SyncPanelUpdatesCommand extends Command
{
    protected $signature = 'panel:update-sync';

    protected $description = 'Read the state of running dPanel updates from drust and notify admins when one ends';

    public function handle(PanelUpdateService $updates): int
    {
        if (! PanelUpdate::query()->whereIn('status', PanelUpdate::ACTIVE)->exists()) {
            return self::SUCCESS;
        }
        try {
            $updates->sync();
        } catch (PanelUpdateRefused $refused) {
            // drust restarts during an update; the next minute tries again.
            $this->warn($refused->getMessage());
        }

        return self::SUCCESS;
    }
}
