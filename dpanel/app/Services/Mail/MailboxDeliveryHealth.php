<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * Finds mailboxes that Postfix accepts mail for but Dovecot cannot find.
 *
 * Postfix and Dovecot each look an address up in the panel database. When
 * they disagree, mail is accepted, then bounced ("550 5.1.1 User doesn't
 * exist"), and the bounce notice to the sender fails too. The check repairs
 * the lookups once; a mailbox Dovecot still cannot find is set to
 * 'unhealthy'. That status is not 'active', so Postfix refuses mail for it at
 * the door and its SMTP login stops, until a later check finds it again.
 */
class MailboxDeliveryHealth
{
    public const UNHEALTHY = 'unhealthy';

    private const CHUNK = 200;

    public function __construct(private readonly ScriptExecutionGateway $gateway)
    {
    }

    /**
     * Checks every active mailbox and retries every unhealthy one.
     *
     * @return array{ok: bool, error: string|null, checked: int, repaired: bool, unhealthy: array<int, string>, restored: array<int, string>}
     */
    public function checkAll(bool $dryRun = false): array
    {
        $active = Mailbox::query()->where('status', 'active')->orderBy('email')->get();
        $unhealthy = Mailbox::query()->where('status', self::UNHEALTHY)->orderBy('email')->get();
        $report = ['ok' => true, 'error' => null, 'checked' => $active->count() + $unhealthy->count(), 'repaired' => false, 'unhealthy' => [], 'restored' => []];

        $missing = $this->missing($active);
        if (is_string($missing)) {
            return ['ok' => false, 'error' => $missing] + $report;
        }

        if ($missing !== [] && ! $dryRun) {
            $report['repaired'] = $this->repair($active->whereIn('email', array_keys($missing)));
            $missing = $this->missing($active->whereIn('email', array_keys($missing)));
            if (is_string($missing)) {
                return ['ok' => false, 'error' => $missing] + $report;
            }
        }

        // Dovecot finding none of several mailboxes is its configuration, not
        // the mailboxes; switching them all off would only hide that.
        if ($active->count() >= 3 && count($missing) === $active->count()) {
            return ['ok' => false, 'error' => 'Dovecot cannot find any mailbox. Its SQL settings are broken; run mail-repair-dovecot.sh or check `doveconf -n`.', 'unhealthy' => array_keys($missing)] + $report;
        }

        $report['unhealthy'] = array_keys($missing);
        if ($dryRun) {
            return $report;
        }

        foreach ($active as $mailbox) {
            $this->record($mailbox, $missing[$mailbox->email] ?? null);
        }
        foreach ($unhealthy as $mailbox) {
            $result = $this->recheck($mailbox);
            if ($result['ok'] && $mailbox->status === 'active') {
                $report['restored'][] = $mailbox->email;
            } elseif ($result['ok']) {
                $report['unhealthy'][] = $mailbox->email;
            }
        }

        return $report;
    }

    /**
     * Turns one mailbox back on if Dovecot can now find it. Dovecot only sees
     * active mailboxes, so it is made active for the lookup and put back if
     * the lookup still fails.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function recheck(Mailbox $mailbox): array
    {
        $previous = $mailbox->status;
        $mailbox->forceFill(['status' => 'active'])->save();

        $missing = $this->missing(collect([$mailbox]));
        if (is_string($missing)) {
            $mailbox->forceFill(['status' => $previous])->save();

            return ['ok' => false, 'error' => $missing];
        }
        if ($missing !== []) {
            $this->repair(collect([$mailbox]));
            $missing = $this->missing(collect([$mailbox]));
            if (is_string($missing)) {
                $mailbox->forceFill(['status' => $previous])->save();

                return ['ok' => false, 'error' => $missing];
            }
        }

        $this->record($mailbox, $missing[$mailbox->email] ?? null);

        return ['ok' => true, 'error' => null];
    }

    private function record(Mailbox $mailbox, ?string $reason): void
    {
        $mailbox->forceFill($reason === null
            ? ['status' => 'active', 'health_error' => null, 'health_checked_at' => now()]
            : [
                'status' => self::UNHEALTHY,
                'health_error' => 'Dovecot cannot find this mailbox, so mail accepted for it would bounce. Turned off until a check finds it again. Dovecot said: '.$reason,
                'health_checked_at' => now(),
            ])->save();
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

    /** @param  Collection<int, Mailbox>  $mailboxes */
    private function repair(Collection $mailboxes): bool
    {
        // Rows without a Maildir path are fixed by the panel's own migration.
        if ($mailboxes->contains(fn (Mailbox $mailbox) => trim((string) $mailbox->mail_home) === '')) {
            Artisan::call('mail:migrate-dovecot-sql');
        }

        try {
            $result = $this->gateway->execute(ScriptPathResolver::resolveRepositoryRoot().'/scripts/mail-repair-dovecot.sh', [], [], true);
        } catch (\Throwable) {
            return false;
        }

        return (bool) $result['success'];
    }
}
