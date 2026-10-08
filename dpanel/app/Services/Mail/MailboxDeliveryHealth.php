<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use App\Support\MailPasswordHash;
use Illuminate\Support\Collection;

/**
 * Mailbox on/off, and the checks that decide whether a mailbox can work.
 *
 * Postfix and Dovecot each look an address up in the panel database. When
 * they disagree, mail is accepted, then bounced ("550 5.1.1 User doesn't
 * exist"). Turning a mailbox on runs every check first and only switches it
 * on when all pass; turning it off is always allowed. The hourly check only
 * reports problems on active mailboxes — it never switches anything off.
 */
class MailboxDeliveryHealth
{
    public const DISABLED = 'disabled';

    private const CHUNK = 200;

    public function __construct(private readonly ScriptExecutionGateway $gateway)
    {
    }

    /**
     * Runs every check and switches the mailbox on only if all pass.
     *
     * @return array{enabled: bool, checks: array<int, array{name: string, ok: bool, message: string}>}
     */
    public function enable(Mailbox $mailbox): array
    {
        $checks = [$this->rowCheck($mailbox), $this->passwordCheck($mailbox)];
        // Dovecot only sees active mailboxes, so it is asked only once
        // everything else passed, with the mailbox briefly switched on.
        if (collect($checks)->every('ok')) {
            $previous = $mailbox->status;
            $mailbox->forceFill(['status' => 'active'])->save();
            $checks[] = $dovecot = $this->dovecotCheck($mailbox);
            if (! $dovecot['ok']) {
                $mailbox->forceFill(['status' => $previous === 'active' ? self::DISABLED : $previous])->save();
            }
        } else {
            $checks[] = ['name' => 'Dovecot can find the mailbox', 'ok' => false, 'message' => 'Not checked: fix the problems above first.'];
        }

        $enabled = collect($checks)->every('ok');
        $failed = collect($checks)->reject(fn ($check) => $check['ok'])->pluck('message')->implode(' ');
        $mailbox->forceFill([
            'status' => $enabled ? 'active' : ($mailbox->status === 'active' ? self::DISABLED : $mailbox->status),
            'health_error' => $enabled ? null : $failed,
            'health_checked_at' => now(),
        ])->save();

        return ['enabled' => $enabled, 'checks' => $checks];
    }

    public function disable(Mailbox $mailbox): void
    {
        $mailbox->forceFill(['status' => self::DISABLED])->save();
    }

    /**
     * Asks Dovecot about every active mailbox and records problems on them as
     * warnings. Changes no status.
     *
     * @return array{ok: bool, error: string|null, checked: int, problems: array<int, string>}
     */
    public function report(): array
    {
        $active = Mailbox::query()->where('status', 'active')->orderBy('email')->get();
        $missing = $this->missing($active);
        if (is_string($missing)) {
            return ['ok' => false, 'error' => $missing, 'checked' => $active->count(), 'problems' => []];
        }

        foreach ($active as $mailbox) {
            $reason = $missing[$mailbox->email] ?? null;
            $mailbox->forceFill([
                'health_error' => $reason === null ? null : 'Dovecot cannot find this mailbox, so mail for it bounces. Turn it off and on again in Email Management to see which check fails. Dovecot said: '.$reason,
                'health_checked_at' => now(),
            ])->save();
        }

        return ['ok' => true, 'error' => null, 'checked' => $active->count(), 'problems' => array_keys($missing)];
    }

    /** @return array{name: string, ok: bool, message: string} */
    private function rowCheck(Mailbox $mailbox): array
    {
        $missing = array_keys(array_filter([
            'mail_home' => trim((string) $mailbox->mail_home) === '',
            'mail_uid' => $mailbox->mail_uid === null,
            'mail_gid' => $mailbox->mail_gid === null,
        ]));

        return [
            'name' => 'Mailbox storage is set up',
            'ok' => $missing === [],
            'message' => $missing === []
                ? (string) $mailbox->mail_home
                : 'Missing '.implode(', ', $missing).". Run 'php artisan mail:migrate-dovecot-sql' in the panel directory.",
        ];
    }

    /** @return array{name: string, ok: bool, message: string} */
    private function passwordCheck(Mailbox $mailbox): array
    {
        $name = 'Mailbox password works';
        try {
            $password = (string) ($mailbox->client_password ?? '');
        } catch (\Throwable) {
            return ['name' => $name, 'ok' => false, 'message' => 'The stored password cannot be read (it was encrypted with another APP_KEY). Set a new password in Edit.'];
        }
        if ($password === '') {
            return ['name' => $name, 'ok' => true, 'message' => 'No stored password to compare; login uses the saved hash.'];
        }

        return MailPasswordHash::verify($password, (string) $mailbox->password)
            ? ['name' => $name, 'ok' => true, 'message' => 'The saved hash matches the password.']
            : ['name' => $name, 'ok' => false, 'message' => "The saved hash does not match the password, so logins fail. Run 'php artisan mail:repair-dovecot-auth' or set the password again in Edit."];
    }

    /** @return array{name: string, ok: bool, message: string} */
    private function dovecotCheck(Mailbox $mailbox): array
    {
        $name = 'Dovecot can find the mailbox';
        $missing = $this->missing(collect([$mailbox]));
        if (is_string($missing)) {
            return ['name' => $name, 'ok' => false, 'message' => 'The check could not run: '.$missing];
        }
        if (isset($missing[$mailbox->email])) {
            return ['name' => $name, 'ok' => false, 'message' => "Dovecot said: {$missing[$mailbox->email]}. Mail for it would bounce. Check the Dovecot SQL settings with 'sudo doveconf -n' on the server, or run 'sudo /opt/dpanel/runtime/scripts/mail-repair-dovecot.sh' to rewrite them."];
        }

        return ['name' => $name, 'ok' => true, 'message' => 'Mail for it is delivered.'];
    }

    /**
     * @param  Collection<int, Mailbox>  $mailboxes
     * @return array<string, string>|string email => Dovecot's answer for each one it cannot find, or an error when Dovecot could not answer
     */
    private function missing(Collection $mailboxes): array|string
    {
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/mail-mailbox-check.sh';
        $missing = [];
        foreach ($mailboxes->pluck('email')->filter()->unique()->chunk(self::CHUNK) as $emails) {
            try {
                $result = $this->gateway->execute($script, $emails->values()->all(), [], true);
            } catch (\Throwable $e) {
                return $e->getMessage();
            }
            $output = (string) $result['output'];
            if (preg_match('/^ERROR (.+)$/m', $output, $error)) {
                return trim($error[1]);
            }
            if (! $result['success']) {
                return trim($output) ?: 'The mailbox check could not run.';
            }
            $seen = 0;
            foreach (preg_split('/\R/', $output) ?: [] as $line) {
                if (preg_match('/^OK \S+$/', $line)) {
                    $seen++;
                } elseif (preg_match('/^MISSING (\S+) ?(.*)$/', $line, $match)) {
                    $seen++;
                    $missing[$match[1]] = trim($match[2]) ?: 'user doesn\'t exist';
                }
            }
            // A truncated answer must not read as "everything is fine".
            if ($seen !== $emails->count()) {
                return 'The mailbox check answered for '.$seen.' of '.$emails->count().' addresses.';
            }
        }

        return $missing;
    }
}
