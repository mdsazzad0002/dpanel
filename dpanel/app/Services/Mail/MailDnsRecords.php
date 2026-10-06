<?php

namespace App\Services\Mail;

use App\Models\MailDomain;
use App\Services\Dns\PublicDnsLookup;

/**
 * The DNS records a domain needs to receive and send mail through this server.
 *
 * Every domain uses the same mail host (the server's own mail hostname, or the
 * panel domain), so no per-domain "mail." A record is needed. SPF names the
 * server IP directly, which keeps it passing even when the MX host lives in
 * another zone.
 */
class MailDnsRecords
{
    /** Set by forDomain() when the domain sends from a non-default mail IP. */
    private ?string $assignedIp = null;

    private ?string $assignedHost = null;

    /**
     * These records as seen by one domain: a domain assigned to another mail
     * IP uses that IP in SPF and that IP's hostname as MX.
     */
    public function forDomain(string $domain): static
    {
        $mailIp = MailDomain::query()->where('domain', strtolower(trim($domain)))->with('mailIp')->first()?->mailIp;
        if (! $mailIp || $mailIp->is_default) {
            return $this;
        }
        $scoped = clone $this;
        $scoped->assignedIp = $mailIp->ip;
        $scoped->assignedHost = strtolower((string) $mailIp->hostname);

        return $scoped;
    }

    /**
     * The one hostname all domains point MX at: the first of Postfix's name,
     * the configured preference and the panel domain that resolves to this
     * server in public DNS. A name pointing elsewhere is never offered as MX,
     * since mail would go to that other machine.
     */
    public function mailHost(): string
    {
        if ($this->assignedHost !== null) {
            return $this->assignedHost;
        }
        $candidates = array_values(array_filter([
            strtolower(trim((string) @shell_exec('postconf -h myhostname 2>/dev/null'))),
            strtolower(trim((string) config('serverpanel.mail.hostname', ''))),
            strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST)),
        ], fn ($host) => MailDomainProvisioner::isValidFqdn($host)));

        $ip = $this->serverIp();
        if ($ip !== '') {
            $dns = app(PublicDnsLookup::class);
            $dns->prefetch(array_map(fn ($host) => [$host, 'A'], $candidates));
            foreach ($candidates as $host) {
                // An unanswered lookup is not proof the name is wrong: keep the
                // current name rather than letting MX drift to another one.
                if ($dns->failed($host, 'A')) {
                    return $candidates[0];
                }
                if (in_array($ip, $dns->a($host), true)) {
                    return $host;
                }
            }
        }

        // Nothing resolves here yet; the guide then shows the mismatch warning.
        return $candidates[0] ?? '';
    }

    /**
     * The public IP mail leaves from, or '' when it cannot be determined.
     * Never taken from DNS: a hostname may still point at another server.
     */
    public function serverIp(): string
    {
        if ($this->assignedIp !== null) {
            return $this->assignedIp;
        }
        $configured = trim((string) config('serverpanel.mail.server_ip', ''));
        if (filter_var($configured, FILTER_VALIDATE_IP)) {
            return $configured;
        }

        $local = trim((string) @shell_exec("ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if (\$i==\"src\") {print \$(i+1); exit}}'"));

        return filter_var($local, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $local : '';
    }

    public function mailHostMismatch(): string
    {
        $host = $this->mailHost();
        $ip = $this->serverIp();
        if ($host === '' || $ip === '') {
            return '';
        }
        $resolved = app(PublicDnsLookup::class)->a($host);

        return $resolved === [] || in_array($ip, $resolved, true) ? '' : implode(', ', $resolved);
    }

    public function spf(): string
    {
        $ip = $this->serverIp();
        if ($ip !== '') {
            return 'v=spf1 '.(str_contains($ip, ':') ? 'ip6:' : 'ip4:').$ip.' mx ~all';
        }
        $host = $this->mailHost();

        return 'v=spf1 mx'.($host !== '' ? ' a:'.$host : '').' ~all';
    }

    /**
     * Rows for the Mail DNS Guide and the zone export. Names are relative to
     * the domain ('@', 'mail', ...).
     *
     * @return array<int, array{type: string, name: string, value: string, priority: ?int, purpose: string}>
     */
    public function records(string $domain, string $selector, string $dkimPublicKey): array
    {
        $domain = strtolower(trim($domain));
        if ($this->assignedIp === null && ($scoped = $this->forDomain($domain)) !== $this) {
            return $scoped->records($domain, $selector, $dkimPublicKey);
        }
        $host = $this->mailHost() ?: 'mail.'.$domain;
        $records = [];

        // Only a mail host inside this domain needs an address record here.
        if ($host === $domain || str_ends_with($host, '.'.$domain)) {
            $name = $host === $domain ? '@' : substr($host, 0, -strlen('.'.$domain));
            $records[] = ['type' => 'A', 'name' => $name, 'value' => $this->serverIp(), 'priority' => null, 'purpose' => 'Mail server hostname'];
        }
        if ($host !== $domain && $this->resolvesOnlyByWildcard($domain)) {
            $records[] = ['type' => 'A', 'name' => '@', 'value' => $this->serverIp(), 'priority' => null, 'purpose' => 'Website address: the MX and SPF records below stop the *.'.substr($domain, strpos($domain, '.') + 1).' wildcard from answering for '.$domain];
        }

        return [
            ...$records,
            ['type' => 'MX', 'name' => '@', 'value' => $host, 'priority' => 10, 'purpose' => 'Receive email for '.$domain],
            ['type' => 'TXT', 'name' => '@', 'value' => $this->spf(), 'priority' => null, 'purpose' => 'Authorize this server to send email (SPF)'],
            ['type' => 'TXT', 'name' => $selector.'._domainkey', 'value' => $dkimPublicKey !== '' ? 'v=DKIM1; k=rsa; p='.$dkimPublicKey : '', 'priority' => null, 'purpose' => 'DKIM signature verification'],
            ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@'.$domain.'; adkim=r; aspf=r', 'priority' => null, 'purpose' => 'Monitor SPF/DKIM alignment'],
        ];
    }

    /**
     * True when a parent wildcard is what answers A for $domain (or nothing
     * does yet). Any record at a name hides the wildcard for every type, so
     * adding MX/TXT there leaves the website without an address.
     */
    private function resolvesOnlyByWildcard(string $domain): bool
    {
        if (substr_count($domain, '.') < 2) {
            return false;
        }
        $wildcard = '*.'.substr($domain, strpos($domain, '.') + 1);
        $dns = app(PublicDnsLookup::class);
        $dns->prefetch([[$wildcard, 'A'], [$domain, 'A']]);
        if ($dns->failed($wildcard, 'A') || $dns->failed($domain, 'A')) {
            return false;
        }
        $wildcardIps = $dns->a($wildcard);
        if ($wildcardIps === []) {
            return false;
        }
        $own = $dns->a($domain);
        sort($wildcardIps);
        sort($own);

        return $own === [] || $own === $wildcardIps;
    }
}
