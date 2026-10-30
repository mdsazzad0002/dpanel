<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The panel's own mail (login codes, password reset and email verification
 * links, security codes) is sent from a "system email": a mailbox hosted on
 * this server, chosen by an admin. It goes through the local Postfix
 * submission port with that mailbox's login, so it is DKIM-signed and
 * passes SPF like any mail the mailbox sends.
 *
 * A fresh install has none, and Laravel's default `log` mailer writes those
 * messages to a log file instead of sending them, so nobody receives a
 * login code. The dashboard warns admins until one is set (or a real
 * mailer is configured in .env).
 */
class SystemMailer
{
    /** Mailers that never deliver anything. */
    private const UNDELIVERED = ['log', 'array'];

    public function __construct(private readonly MailSettings $settings) {}

    public function mailbox(): ?Mailbox
    {
        $id = $this->settings->read()['system_mailbox_id'];

        return $id !== null ? Mailbox::query()->find($id) : null;
    }

    /**
     * Why $mailbox cannot be the system email, or null when it can.
     */
    public function problemWith(?Mailbox $mailbox): ?string
    {
        return match (true) {
            $mailbox === null => 'The selected mailbox no longer exists.',
            $mailbox->status !== 'active' => "{$mailbox->email} is turned off.",
            $mailbox->isSandbox() => "{$mailbox->email} is in sandbox mode, so mail would never reach other servers. Switch it to Live.",
            (string) ($mailbox->client_password ?? '') === '' => "{$mailbox->email} has no stored password; reset it on the Edit Email page.",
            default => null,
        };
    }

    /**
     * Point Laravel's mailer at the system mailbox. Runs when the mail
     * manager is first resolved (see AppServiceProvider), so requests that
     * send no mail never query for it.
     */
    public function apply(): void
    {
        try {
            $mailbox = $this->mailbox();
        } catch (Throwable) {
            // No database yet (install, migrations): keep the .env mailer.
            return;
        }
        if ($mailbox === null || $this->problemWith($mailbox) !== null) {
            return;
        }

        config([
            'mail.mailers.system' => [
                'transport' => 'smtp',
                'host' => '127.0.0.1',
                'port' => 587,
                'username' => $mailbox->email,
                'password' => (string) $mailbox->client_password,
                'timeout' => 20,
                // Local hop only: the submission port's certificate names the
                // mail hostname, not 127.0.0.1.
                'verify_peer' => false,
                'local_domain' => $mailbox->domain,
            ],
            'mail.default' => 'system',
            'mail.from' => ['address' => $mailbox->email, 'name' => (string) config('app.name', 'dPanel')],
        ]);
    }

    /**
     * source: 'system' (a mailbox here), 'env' (a real mailer set in .env) or 'none'.
     *
     * @return array{ready: bool, source: string, email: string|null, problem: string|null}
     */
    public function status(): array
    {
        $id = $this->settings->read()['system_mailbox_id'];
        if ($id !== null) {
            $mailbox = Mailbox::query()->find($id);
            $problem = $this->problemWith($mailbox);

            return ['ready' => $problem === null, 'source' => 'system', 'email' => $mailbox?->email, 'problem' => $problem];
        }

        $mailer = (string) config('mail.default');
        if (! in_array($mailer, self::UNDELIVERED, true)) {
            return ['ready' => true, 'source' => 'env', 'email' => (string) config('mail.from.address'), 'problem' => null];
        }

        return [
            'ready' => false,
            'source' => 'none',
            'email' => null,
            'problem' => "No system email is set, and the panel's mailer is \"{$mailer}\": login codes, password reset and verification emails are not sent to anyone.",
        ];
    }

    public function sendTest(string $to): void
    {
        Mail::raw(
            "This is a test from your dPanel system email.\n\nLogin codes, password reset and verification links will be sent from this address.",
            static fn ($message) => $message->to($to)->subject('dPanel system email test'),
        );
    }
}
