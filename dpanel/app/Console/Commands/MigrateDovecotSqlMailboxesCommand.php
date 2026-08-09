<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateDovecotSqlMailboxesCommand extends Command
{
    protected $signature = 'mail:migrate-dovecot-sql {--dry-run : Report changes without moving mail or updating rows}';

    protected $description = 'Move legacy vhost Maildirs into site-owner homes and populate Dovecot SQL fields.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && is_file('/etc/dovecot/serverpanel-users') && ! is_file('/etc/dovecot/serverpanel-users.pre-sql.bak')) {
            @copy('/etc/dovecot/serverpanel-users', '/etc/dovecot/serverpanel-users.pre-sql.bak');
        }
        $migrated = 0;
        $skipped = 0;

        foreach (Mailbox::query()->orderBy('email')->cursor() as $mailbox) {
            $website = Website::query()->whereRaw('LOWER(domain) = ?', [strtolower((string) $mailbox->domain)])->first();
            $owner = trim((string) ($website?->site_owner ?? ''));
            $account = $owner !== '' ? posix_getpwnam($owner) : false;
            if ($account === false) {
                $this->warn("Skipping {$mailbox->email}: website owner is missing or is not a system account.");
                $skipped++;
                continue;
            }

            $target = "/home/{$owner}/mail/{$mailbox->domain}/{$mailbox->mailbox}/Maildir";
            $legacy = "/var/mail/vhosts/{$mailbox->domain}/{$mailbox->mailbox}";
            $this->line("{$mailbox->email}: {$legacy} -> {$target}");
            if ($dryRun) {
                continue;
            }

            if (is_dir($legacy) && ! is_dir($target)) {
                if (! @mkdir(dirname($target), 0700, true) && ! is_dir(dirname($target))) {
                    $this->error("Cannot create parent directory for {$mailbox->email}.");
                    $skipped++;
                    continue;
                }
                if (! @rename($legacy, $target)) {
                    $this->error("Cannot move legacy Maildir for {$mailbox->email}.");
                    $skipped++;
                    continue;
                }
            }
            foreach (['cur', 'new', 'tmp'] as $folder) {
                @mkdir("{$target}/{$folder}", 0700, true);
            }
            @chown($target, $owner);
            @chgrp($target, (int) $account['gid']);
            @chmod($target, 0700);

            $password = (string) $mailbox->password;
            if (! str_starts_with($password, '{')) {
                $password = trim((string) shell_exec('doveadm pw -s SHA512-CRYPT -p '.escapeshellarg($password).' 2>/dev/null'));
            }
            if ($password === '') {
                $this->error("Cannot hash password for {$mailbox->email}; row was not updated.");
                $skipped++;
                continue;
            }
            DB::transaction(function () use ($mailbox, $owner, $account, $target, $password): void {
                $mailbox->forceFill([
                    'site_owner' => $owner,
                    'mail_home' => $target,
                    'mail_uid' => (int) $account['uid'],
                    'mail_gid' => (int) $account['gid'],
                    'password' => $password,
                ])->save();
            });
            $migrated++;
        }

        if (! $dryRun && $skipped === 0) {
            $validation = [];
            @exec('doveconf -n 2>&1', $validation, $code);
            if ($code !== 0) {
                $this->error('Dovecot validation failed; legacy users file backup is /etc/dovecot/serverpanel-users.pre-sql.bak.');
                return self::FAILURE;
            }
            @exec('systemctl restart dovecot.service 2>&1', $restart, $restartCode);
            if ($restartCode !== 0) {
                $this->error('Dovecot restart failed; legacy users file backup is /etc/dovecot/serverpanel-users.pre-sql.bak.');
                return self::FAILURE;
            }
        }
        $this->info("Dovecot SQL mailbox migration complete: migrated={$migrated}, skipped={$skipped}.");
        $this->line('Validate with: doveconf -n; test an IMAP login; then test Postfix local delivery.');
        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
