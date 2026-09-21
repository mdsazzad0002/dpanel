<?php

namespace App\Console\Commands;

use App\Services\ChatEngine\ScheduledMessageDispatcher;
use Illuminate\Console\Command;

class DispatchScheduledChatMessagesCommand extends Command
{
    protected $signature = 'chatengine:dispatch-scheduled';

    protected $description = 'Dispatch chat engine scheduled/broadcast messages that are due to run.';

    public function handle(ScheduledMessageDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDue();

        $this->info("Dispatched {$count} scheduled message(s).");

        return self::SUCCESS;
    }
}
