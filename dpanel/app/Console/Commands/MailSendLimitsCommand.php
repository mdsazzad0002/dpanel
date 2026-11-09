<?php

namespace App\Console\Commands;

use App\Services\Mail\Backend\MailBackendException;
use App\Services\Mail\SendLimits\MailSendLimitService;
use Illuminate\Console\Command;

class MailSendLimitsCommand extends Command
{
    protected $signature = 'mail:send-limits {--force : Write the limits even when unchanged}';

    protected $description = 'Give Rspamd each account\'s outbound mail limit and alert accounts that reached it';

    public function handle(MailSendLimitService $limits): int
    {
        $failed = false;
        try {
            $state = $limits->sync((bool) $this->option('force'));
            if ($state['changed']) {
                $this->info($state['ready'] ? 'Mail limits written.' : "Mail limits written, but not enforced: {$state['reason']}.");
            }
        } catch (MailBackendException $e) {
            // Without any limit there is nothing to enforce, so a server
            // without Rspamd is not an error.
            if ($limits->limitedUsers()->isNotEmpty()) {
                $this->error($e->getMessage());
                $failed = true;
            }
        }

        $sent = $limits->alert();
        if ($sent > 0) {
            $this->info("{$sent} account(s) reached their mail limit.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
