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

    /**
     * @return array<int, array{host: string, source: string, addresses: array<int, string>, usable: bool}>
     */
    public function candidates(): array
    {
        $ip = $this->records->serverIp();
        $panel = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
        $ptr = $ip !== '' ? (string) ($this->dns->ptr($ip)[0] ?? '') : '';

        $sources = [
            [$ptr, 'Reverse DNS (PTR) of '.($ip ?: 'server IP')],
            [strtolower(trim((string) config('serverpanel.mail.hostname', ''))), 'SERVERPANEL_MAIL_HOSTNAME'],
            [$this->current(), 'Current Postfix hostname'],
            [$panel !== '' ? 'mail.'.$panel : '', 'mail. + panel domain'],
            [$panel, 'Panel domain'],
        ];

        $seen = [];
        $candidates = [];
        foreach ($sources as [$host, $source]) {
            if (! MailDomainProvisioner::isValidFqdn($host) || isset($seen[$host])) {
                continue;
            }
            $seen[$host] = true;
            $addresses = $this->dns->a($host);
            $candidates[] = [
                'host' => $host,
                'source' => $source,
                'addresses' => $addresses,
                'usable' => $ip !== '' && in_array($ip, $addresses, true),
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
        $ip = $this->records->serverIp();
        if ($ip === '' || ! in_array($ip, $this->dns->a($host), true)) {
            return ['ok' => false, 'changed' => false, 'host' => $host, 'message' => "{$host} does not resolve to this server ({$ip}). Add an A record (DNS only) first."];
        }

        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/ensure-mail-hostname.sh';
        $result = $this->gateway->execute($script, [$host, '--set'], [], true);
        if (! $result['success']) {
            return ['ok' => false, 'changed' => false, 'host' => $host, 'message' => 'Could not set the mail hostname: '.(trim($result['output']) ?: 'unknown error.')];
        }
        $changed = str_contains($result['output'], 'MAIL_HOSTNAME_CHANGED=1');

        return ['ok' => true, 'changed' => $changed, 'host' => $host, 'message' => $changed ? "Mail hostname set to {$host}." : "Mail hostname is already {$host}."];
    }

    /** @return array{ok: bool, changed: bool, host: string, message: string} */
    public function applyBest(): array
    {
        $best = $this->best();

        return $best === ''
            ? ['ok' => false, 'changed' => false, 'host' => '', 'message' => 'No candidate hostname resolves to this server. Add an A record such as mail.<panel domain> pointing here.']
            : $this->apply($best);
    }
}
