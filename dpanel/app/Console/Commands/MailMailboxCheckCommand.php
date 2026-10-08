<?php

namespace App\Console\Commands;

use App\Services\Mail\MailboxDeliveryHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class MailMailboxCheckCommand extends Command
{
    protected $signature = 'mail:mailbox-check';

    protected $description = 'Report active mailboxes that Postfix accepts mail for but Dovecot cannot find (changes no status).';

    public function handle(MailboxDeliveryHealth $health): int
    {
        if (! Schema::hasTable('mailboxes') || ! Schema::hasColumn('mailboxes', 'health_error')) {
            $this->info('Mailbox table not ready; run migrations first.');

            return self::SUCCESS;
        }

        $report = $health->report();
        if (! $report['ok']) {
            $this->error((string) $report['error']);

            return self::FAILURE;
        }

        $this->line("Checked {$report['checked']} active mailbox(es).");
        foreach ($report['problems'] as $email) {
            $this->warn("{$email}: Dovecot cannot find it; mail for it bounces. Turn it off and on in Email Management to see which check fails.");
        }

        return self::SUCCESS;
    }
}
