<?php

namespace App\Services\Mail\Backend;

use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\MailForwarder;
use App\Services\Mail\MailDomainProvisioner;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use Illuminate\Support\Facades\Http;

/**
 * Postfix and Dovecot read mailboxes, forwarders and catch-alls straight
 * from the panel's tables (the installer's SQL maps), so a row change is
 * live with the next message. What lives on disk (Maildirs, Sieve scripts)
 * or in Postfix's own config (the relay) goes through drust as root.
 */
class PostfixDovecotBackend implements MailBackend
{
    public function __construct(
        private readonly MailDomainProvisioner $provisioner,
        private readonly ScriptExecutionGateway $gateway,
    ) {}

    public function name(): string
    {
        return 'postfix-dovecot';
    }

    public function ensureDomain(string $domain): array
    {
        return $this->provisioner->ensureReady($domain);
    }

    public function ensureMailbox(Mailbox $mailbox, ?string $previousMailHome = null): void
    {
        if ($this->windows()) {
            return;
        }
        $move = $previousMailHome !== null && $previousMailHome !== '' && $previousMailHome !== (string) $mailbox->mail_home;
        $this->storage($move ? 'move' : 'create', $mailbox, ['previous_mail_home' => $move ? $previousMailHome : null]);
    }

    public function removeMailbox(Mailbox $mailbox): void
    {
        if ($this->windows()) {
            return;
        }
        $this->storage('remove', $mailbox);
    }

    public function setForwarding(Mailbox $mailbox, ?string $target): void
    {
        $mailbox->forceFill(['forwarding_to' => trim((string) $target)])->save();
    }

    public function supportsAutoresponder(): bool
    {
        return ! $this->windows() && is_file((string) config('mail.dpanel_sieve_config', '/etc/dovecot/conf.d/91-dpanel-sieve.conf'));
    }

    public function setAutoresponder(Mailbox $mailbox, ?AutoresponderData $data): void
    {
        if ($data !== null && ! $this->supportsAutoresponder()) {
            throw new UnsupportedMailFeature('Automatic replies need the Sieve filter (the Rspamd mail module) on this server.');
        }
        $this->storage('autoresponder', $mailbox, ['autoresponder' => $data === null ? null : [
            'email' => strtolower((string) $mailbox->email),
            'subject' => $data->subject,
            'body' => $data->body,
            'days' => $data->days,
            'starts_on' => $data->startsOn,
            'ends_on' => $data->endsOn,
        ]]);
    }

    public function setCatchAll(MailDomain $domain, ?string $target): void
    {
        $domain->forceFill(['catch_all_to' => $target === null ? null : strtolower(trim($target))])->save();
    }

    public function ensureForwarder(MailForwarder $forwarder): void
    {
        // The row is the configuration: Postfix's alias query reads it.
        if (! $forwarder->exists) {
            $forwarder->save();
        }
    }

    public function removeForwarder(MailForwarder $forwarder): void
    {
        $forwarder->delete();
    }

    public function setRelay(?RelaySettings $relay): void
    {
        $arguments = $relay === null
            ? ['--disable']
            : array_values(array_filter(["--host={$relay->host}", "--port={$relay->port}", $relay->username !== '' ? "--username={$relay->username}" : null]));
        $environment = $relay !== null && $relay->password !== '' ? ['DPANEL_RELAY_PASSWORD' => $relay->password] : [];
        $result = $this->gateway->execute($this->script(), $arguments, $environment, true);
        if (! ($result['success'] ?? false)) {
            throw new MailBackendException(trim((string) ($result['output'] ?? '')) ?: 'Postfix could not be updated.');
        }
    }

    public function relay(): array
    {
        $result = $this->gateway->execute($this->script(), ['--status'], [], true);
        $values = $this->values($result, ['MAIL_RELAY_HOST', 'MAIL_RELAY_PORT', 'MAIL_RELAY_USERNAME']);

        return [
            'host' => $values['MAIL_RELAY_HOST'],
            'port' => ctype_digit($values['MAIL_RELAY_PORT']) ? (int) $values['MAIL_RELAY_PORT'] : null,
            'username' => $values['MAIL_RELAY_USERNAME'],
        ];
    }

    public function setSendLimits(string $senders, string $limits): array
    {
        if ($this->windows()) {
            throw new UnsupportedMailFeature('Outbound mail limits need Rspamd, which this server does not run.');
        }
        $result = $this->gateway->execute($this->script('mail-send-limits.sh'), ['--apply'], [
            'DPANEL_MAIL_SENDERS' => $senders,
            'DPANEL_MAIL_LIMITS' => $limits,
        ], true);
        if (! ($result['success'] ?? false)) {
            throw new MailBackendException(trim((string) ($result['output'] ?? '')) ?: 'The mail limits could not be written.');
        }

        return $this->limitState($result);
    }

    public function sendLimitStatus(): array
    {
        if ($this->windows()) {
            return ['ready' => false, 'reason' => 'Outbound mail limits need Rspamd, which this server does not run.'];
        }
        $result = $this->gateway->execute($this->script('mail-send-limits.sh'), ['--status'], [], true);
        if (! ($result['success'] ?? false)) {
            return ['ready' => false, 'reason' => trim((string) ($result['output'] ?? '')) ?: 'The mail limit state could not be read.'];
        }

        return $this->limitState($result);
    }

    public function deleteQueued(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $result = $this->gateway->execute($this->script('mail-queue.sh'), array_map(fn (string $id) => "--delete={$id}", $ids), [], true);
        if (! ($result['success'] ?? false)) {
            throw new MailBackendException(trim((string) ($result['output'] ?? '')) ?: 'The messages could not be deleted.');
        }

        return (int) $this->values($result, ['MAIL_QUEUE_DELETED'])['MAIL_QUEUE_DELETED'];
    }

    public function retryQueue(): void
    {
        $result = $this->gateway->execute($this->script('mail-queue.sh'), ['--retry'], [], true);
        if (! ($result['success'] ?? false)) {
            throw new MailBackendException(trim((string) ($result['output'] ?? '')) ?: 'The queue could not be retried.');
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{ready: bool, reason: string}
     */
    private function limitState(array $result): array
    {
        $values = $this->values($result, ['MAIL_LIMITS_READY', 'MAIL_LIMITS_REASON']);

        return ['ready' => $values['MAIL_LIMITS_READY'] === '1', 'reason' => $values['MAIL_LIMITS_REASON']];
    }

    /**
     * The KEY=value lines a script printed.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function values(array $result, array $keys): array
    {
        $values = array_fill_keys($keys, '');
        foreach (preg_split('/\R/', (string) ($result['output'] ?? '')) as $line) {
            [$key, $value] = array_pad(explode('=', trim($line), 2), 2, '');
            if (array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function script(string $name = 'mail-smtp-relay.sh'): string
    {
        return ScriptPathResolver::resolveRepositoryRoot().'/scripts/'.$name;
    }

    private function windows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /** @param  array<string, mixed>  $extra */
    private function storage(string $action, Mailbox $mailbox, array $extra = []): void
    {
        $baseUrl = rtrim((string) config('serverpanel.execution_api_base_url', ''), '/');
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            throw new MailBackendException('Rust execution service is not configured.');
        }
        try {
            $response = Http::acceptJson()->asJson()->withToken($token)
                ->timeout((int) config('serverpanel.execution_api_timeout', 60))
                ->post($baseUrl.'/api/v1/mailbox-storage', [
                    'action' => $action,
                    'mail_home' => $mailbox->mail_home,
                    'site_owner' => $mailbox->site_owner,
                    ...$extra,
                ]);
        } catch (\Throwable $e) {
            throw new MailBackendException($e->getMessage(), 0, $e);
        }
        if (! $response->ok() || $response->json('success') !== true) {
            throw new MailBackendException((string) ($response->json('message') ?: $response->body()));
        }
    }
}
