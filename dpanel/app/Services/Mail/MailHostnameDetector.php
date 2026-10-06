<?php

namespace App\Services\Mail;

use App\Services\Dns\PublicDnsLookup;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;

/**
 * Picks the mail hostname Postfix announces: the first candidate that
 * resolves to this server. Gmail rejects mail when the sending IP's PTR name
 * does not resolve back to it, so the PTR name wins whenever it is usable, and
 * a name pointing at another server is never chosen.
 */
class MailHostnameDetector
{
    public function __construct(
        private readonly MailDnsRecords $records,
        private readonly ScriptExecutionGateway $gateway,
        private readonly PublicDnsLookup $dns,
    ) {
    }

    public function current(): string
    {
        return strtolower(trim((string) @shell_exec('postconf -h myhostname 2>/dev/null')));
    }

    /** The machine's own name; the hostname script keeps it equal to the mail hostname. */
    public function systemHostname(): string
    {
        return strtolower(trim((string) (@shell_exec('hostname -f 2>/dev/null') ?: gethostname())));
    }

    /**
     * The server IP and its reverse DNS. One IP has one PTR, shared by every
     * mail domain, so it should name the mail hostname.
     *
     * @return array{ip: string, ptr: string}
     */
    public function reverseDns(): array
    {
        $ip = $this->records->serverIp();

        return ['ip' => $ip, 'ptr' => $ip !== '' ? strtolower(rtrim((string) ($this->dns->ptr($ip)[0] ?? ''), '.')) : ''];
    }

    /**
     * @return array<int, array{host: string, source: string, addresses: array<int, string>, usable: bool}>
     */
    public function candidates(): array
    {
        $ip = $this->records->serverIp();
        $panel = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
        $ptr = $ip !== '' ? (string) ($this->dns->ptr($ip)[0] ?? '') : '';

        // The current name ranks above the configured one, so a host an admin
        // picked here is not switched back by the next update; only a working
        // PTR name replaces it (that is what Gmail checks).
        $sources = [
            [$ptr, 'Reverse DNS (PTR) of '.($ip ?: 'server IP')],
            [$this->current(), 'Current Postfix hostname'],
            [strtolower(trim((string) config('serverpanel.mail.hostname', ''))), 'SERVERPANEL_MAIL_HOSTNAME'],
            [$panel !== '' ? 'mail.'.$panel : '', 'mail. + panel domain'],
            [$panel, 'Panel domain'],
        ];

        $seen = [];
        $candidates = [];
        $sources = array_values(array_filter($sources, function ($source) use (&$seen) {
            $host = $source[0];
            if (! MailDomainProvisioner::isValidFqdn($host) || isset($seen[$host])) {
                return false;
            }

            return $seen[$host] = true;
        }));
        $this->dns->prefetch(array_map(fn ($source) => [$source[0], 'A'], $sources));
        foreach ($sources as [$host, $source]) {
            $addresses = $this->dns->a($host);
            $candidates[] = [
                'host' => $host,
                'source' => $source,
                'addresses' => $addresses,
                'usable' => $ip !== '' && in_array($ip, $addresses, true),
                'lookup_failed' => $this->dns->failed($host, 'A'),
            ];
        }

        return $candidates;
    }

    public function best(): string
    {
        foreach ($this->candidates() as $candidate) {
            if ($candidate['usable']) {
                return $candidate['host'];
            }
        }

        return '';
    }

    /**
     * Sets Postfix's hostname. Only a name that resolves to this server is
     * accepted, so MX and HELO never point mail at another machine.
     *
     * @return array{ok: bool, changed: bool, host: string, message: string}
     */
    public function apply(string $host): array
    {
        $host = strtolower(trim($host));
        if (! MailDomainProvisioner::isValidFqdn($host)) {
            return ['ok' => false, 'changed' => false, 'host' => $host, 'message' => "'{$host}' is not a valid hostname."];
        }
        if (! $this->resolvesHere($host)) {
            return ['ok' => false, 'changed' => false, 'host' => $host, 'message' => "{$host} does not resolve to this server ({$this->records->serverIp()}). Add an A record (DNS only) first."];
        }

        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/ensure-mail-hostname.sh';
        $result = $this->gateway->execute($script, [$host, '--set'], [], true);
        if (! $result['success']) {
            return ['ok' => false, 'changed' => false, 'host' => $host, 'message' => 'Could not set the mail hostname: '.(trim($result['output']) ?: 'unknown error.')];
        }
        $changed = str_contains($result['output'], 'MAIL_HOSTNAME_CHANGED=1');

        return ['ok' => true, 'changed' => $changed, 'host' => $host, 'message' => $changed ? "Mail hostname set to {$host}." : "Mail hostname is already {$host}."];
    }

    public function resolvesHere(string $host): bool
    {
        $ip = $this->records->serverIp();

        return $ip !== '' && in_array($ip, $this->dns->a($host), true);
    }

    /** @return array{ok: bool, changed: bool, host: string, message: string} */
    public function applyBest(): array
    {
        if (collect($this->candidates())->contains('lookup_failed', true)) {
            return ['ok' => false, 'changed' => false, 'host' => $this->current(), 'message' => 'DNS did not answer for every candidate; the mail hostname was left unchanged. Try again.'];
        }
        $best = $this->best();

        return $best === ''
            ? ['ok' => false, 'changed' => false, 'host' => '', 'message' => 'No candidate hostname resolves to this server. Add an A record such as mail.<panel domain> pointing here.']
            : $this->apply($best);
    }
}
