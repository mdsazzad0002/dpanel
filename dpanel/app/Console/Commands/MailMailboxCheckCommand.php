<?php

namespace App\Console\Commands;

use App\Services\Mail\MailboxDeliveryHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class MailMailboxCheckCommand extends Command
{
    protected $signature = 'mail:mailbox-check {--dry-run : Report mailboxes Dovecot cannot find without repairing or switching them off}';

    protected $description = 'Find mailboxes Postfix accepts mail for but Dovecot cannot find; repair, else switch them off.';

    public function handle(MailboxDeliveryHealth $health): int
    {
        if (! Schema::hasTable('mailboxes') || ! Schema::hasColumn('mailboxes', 'health_error')) {
            $this->info('Mailbox table not ready; run migrations first.');

            return self::SUCCESS;
        }

        $report = $health->checkAll((bool) $this->option('dry-run'));
        $this->line("Checked {$report['checked']} mailbox(es).".($report['repaired'] ? ' Dovecot/Postfix lookups were rewritten.' : ''));
        foreach ($report['restored'] as $email) {
            $this->info("{$email}: Dovecot finds it again; turned back on.");
        }
        foreach ($report['unhealthy'] as $email) {
            $this->warn("{$email}: Dovecot cannot find it".($this->option('dry-run') ? '.' : '; switched off.'));
        }
        if (! $report['ok']) {
            $this->error((string) $report['error']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
