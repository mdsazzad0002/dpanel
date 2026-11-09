<?php

namespace App\Services\Mail\Backend;

use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\MailForwarder;

/**
 * The mail server behind the panel (spec section 6.11, backlog I4). Postfix
 * and Dovecot now; Stalwart on Windows and macOS later (J5, K4). The panel's
 * rows (mailboxes, mail_forwarders, mail_domains) are what was asked for; a
 * backend makes the mail server do it. Reading mail over IMAP
 * (MailboxImapService) is the same for every backend.
 */
interface MailBackend
{
    /** The backend's own name, such as `postfix-dovecot`. */
    public function name(): string;

    /**
     * Gets a domain ready to send: server hostname, DKIM key. Each step is
     * skipped when already done.
     *
     * @return array{ok: bool, messages: list<string>}
     */
    public function ensureDomain(string $domain): array;

    /**
     * Creates the mailbox's storage, or moves it from `$previousMailHome`.
     *
     * @throws MailBackendException
     */
    public function ensureMailbox(Mailbox $mailbox, ?string $previousMailHome = null): void;

    /** @throws MailBackendException */
    public function removeMailbox(Mailbox $mailbox): void;

    /** Copies of the mailbox's mail also go to `$target` (comma-separated), or stop with null. */
    public function setForwarding(Mailbox $mailbox, ?string $target): void;

    /**
     * An automatic reply, or none with null (M1).
     *
     * @throws MailBackendException
     * @throws UnsupportedMailFeature
     */
    public function setAutoresponder(Mailbox $mailbox, ?AutoresponderData $data): void;

    public function supportsAutoresponder(): bool;

    /** Mail to any address of the domain without a mailbox or forwarder goes to `$target`, or is refused with null (M2). */
    public function setCatchAll(MailDomain $domain, ?string $target): void;

    /** An address with no mailbox that only forwards (M3). */
    public function ensureForwarder(MailForwarder $forwarder): void;

    public function removeForwarder(MailForwarder $forwarder): void;

    /**
     * Sends all outgoing mail through another SMTP server, or directly again with null (M8).
     *
     * @throws MailBackendException
     */
    public function setRelay(?RelaySettings $relay): void;

    /** @return array{host: string, port: int|null, username: string} what the server uses now */
    public function relay(): array;

    /**
     * Each account's outbound mail limit (P5), one "key value" pair per line.
     * `$senders` maps a mailbox address, a domain or `user:<system user>` to
     * an account key (`a<user id>`); `$limits` maps an account key to
     * `<per hour>:<per day>`, 0 meaning none.
     *
     * @return array{ready: bool, reason: string} whether the server enforces them
     *
     * @throws MailBackendException
     * @throws UnsupportedMailFeature
     */
    public function setSendLimits(string $senders, string $limits): array;

    /** @return array{ready: bool, reason: string} whether the server can enforce limits now */
    public function sendLimitStatus(): array;

    /**
     * Deletes these queued messages for good (P5).
     *
     * @param  list<string>  $ids  queue ids
     * @return int how many were deleted
     *
     * @throws MailBackendException
     */
    public function deleteQueued(array $ids): int;

    /**
     * Tries to deliver every queued message now.
     *
     * @throws MailBackendException
     */
    public function retryQueue(): void;
}
