<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Support\MailPasswordHash;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Dovecot checks the password hash in mailboxes.password, while the panel's
 * webmail logs in with client_password. When the two drift apart (a hash of a
 * hash, a failed doveadm call), IMAP answers AUTHENTICATIONFAILED. The panel
 * is the only writer of both, so client_password is the source of truth.
 */
class RepairDovecotAuthCommand extends Command
{
    protected $signature = 'mail:repair-dovecot-auth {--dry-run : Report mismatches without updating rows}';

    protected $description = 'Re-hash mailbox passwords whose Dovecot hash no longer matches the panel password.';

    public function handle(): int
    {
        if (! Schema::hasTable('mailboxes')) {
            $this->info('No mailboxes table; nothing to repair.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $repaired = 0;
        $unreadable = 0;
        $withoutHome = 0;

        foreach (Mailbox::query()->orderBy('email')->cursor() as $mailbox) {
            if (trim((string) $mailbox->mail_home) === '') {
                $withoutHome++;
                $this->warn("{$mailbox->email}: no mail_home; run 'php artisan mail:migrate-dovecot-sql'.");
            }

            try {
                $clientPassword = (string) ($mailbox->client_password ?? '');
            } catch (\Throwable) {
                // Encrypted with another APP_KEY; only a password reset can recover it.
                $unreadable++;
                $this->warn("{$mailbox->email}: stored password cannot be decrypted; reset it in the panel.");
                continue;
            }
            if ($clientPassword === '' || MailPasswordHash::verify($clientPassword, (string) $mailbox->password)) {
                continue;
            }

            $this->line("{$mailbox->email}: Dovecot hash does not match".($dryRun ? '.' : '; re-hashed.'));
            if (! $dryRun) {
                $mailbox->forceFill(['password' => MailPasswordHash::make($clientPassword)])->save();
            }
            $repaired++;
        }

        $this->info(sprintf(
            'Dovecot auth check complete: %s=%d, undecryptable=%d, without_mail_home=%d.',
            $dryRun ? 'mismatched' : 'repaired',
            $repaired,
            $unreadable,
            $withoutHome,
        ));

        return self::SUCCESS;
    }
}
