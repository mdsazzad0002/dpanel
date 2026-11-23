<?php

namespace App\Services\Mail;

use App\Services\Dns\PublicDnsLookup;

/**
 * Checks the live DNS of a mail domain against the records the Mail DNS Guide
 * asks for, plus the server-level checks Gmail enforces (mail host address and
 * reverse DNS). Lookups go through public resolvers without a cache (drust),
 * so the result matches what Gmail sees.
 */
class MailDnsVerifier
{
    public function __construct(private readonly MailDnsRecords $records, private readonly PublicDnsLookup $dns)
    {
    }

    /**
     * @return array<int, array{key: string, label: string, status: 'pass'|'warn'|'fail', found: string, hint: string}>
     */
    public function verify(string $domain, string $selector, string $dkimPublicKey): array
    {
        $domain = strtolower(trim($domain));
        $records = $this->records->forDomain($domain);
        $host = $records->mailHost() ?: 'mail.'.$domain;
        $ip = $records->serverIp();
        $ipv6 = $records->serverIpv6();
        $this->dns->prefetch([
            [$domain, 'MX'], [$domain, 'TXT'], ["{$selector}._domainkey.{$domain}", 'TXT'],
            ["_dmarc.{$domain}", 'TXT'], [$host, 'A'], [$ip, 'PTR'],
            ...($ipv6 !== '' ? [[$host, 'AAAA'], [$ipv6, 'PTR']] : []),
        ]);

        return [
            $this->unanswered('MX @', 'MX', $domain, 'MX') ?? $this->checkMx($domain, $host),
            $this->unanswered('TXT @', 'SPF', $domain, 'TXT') ?? $this->checkSpf($domain, $ip, $ipv6),
            $this->unanswered("TXT {$selector}._domainkey", 'DKIM', "{$selector}._domainkey.{$domain}", 'TXT') ?? $this->checkDkim($domain, $selector, $dkimPublicKey),
            $this->unanswered('TXT _dmarc', 'DMARC', "_dmarc.{$domain}", 'TXT') ?? $this->checkDmarc($domain),
            ...array_values($this->serverChecks($domain)),
        ];
    }

    /**
     * The checks that are about the server itself, not one domain's zone: the
     * mail host's addresses and the reverse DNS of every address mail leaves
     * from. Mail Health shows these for the server as a whole.
     *
     * @return array<string, array{key: string, label: string, status: 'pass'|'warn'|'fail', found: string, hint: string}> keyed by check key
     */
    public function serverChecks(string $domain = ''): array
    {
        $records = $domain === '' ? $this->records : $this->records->forDomain($domain);
        $host = $records->mailHost() ?: ($domain !== '' ? 'mail.'.$domain : '');
        $ip = $records->serverIp();
        $ipv6 = $records->serverIpv6();
        $this->dns->prefetch([
            [$host, 'A'], [$ip, 'PTR'],
            ...($ipv6 !== '' ? [[$host, 'AAAA'], [$ipv6, 'PTR']] : []),
        ]);

        return [
            'host' => $this->unanswered('host', "Mail host {$host}", $host, 'A') ?? $this->checkMailHost($host, $ip),
            'ptr' => $this->unanswered('ptr', 'Reverse DNS (PTR)', $ip, 'PTR') ?? $this->checkPtr($host, $ip),
            // Only meaningful on a server that has IPv6; without it there is
            // nothing to publish and nothing Gmail can reject.
            ...($ipv6 === '' ? [] : [
                'host6' => $this->unanswered('host6', "Mail host {$host} (IPv6)", $host, 'AAAA') ?? $this->checkMailHostIpv6($host, $ipv6),
                'ptr6' => $this->unanswered('ptr6', 'Reverse DNS (PTR) for IPv6', $ipv6, 'PTR') ?? $this->checkPtrIpv6($host, $ipv6),
            ]),
        ];
    }

    /** A lookup that got no answer is reported as unknown, never as a wrong record. */
    private function unanswered(string $key, string $label, string $name, string $type): ?array
    {
        return $name !== '' && $this->dns->failed($name, $type)
            ? $this->result($key, $label, 'warn', '', 'DNS did not answer in time, so this was not checked. Press Verify DNS again.')
            : null;
    }

    private function checkMx(string $domain, string $host): array
    {
        $targets = $this->dns->mx($domain);
        $found = implode(', ', $targets);

        return match (true) {
            $targets === [] => $this->result('MX @', 'MX', 'fail', '', "Add an MX record for {$domain} pointing to {$host}."),
            in_array($host, $targets, true) => $this->result('MX @', 'MX', 'pass', $found, ''),
            default => $this->result('MX @', 'MX', 'fail', $found, "MX points elsewhere; point it to {$host}."),
        };
    }

    private function checkSpf(string $domain, string $ip, string $ipv6 = ''): array
    {
        $spf = array_values(array_filter($this->txt($domain), fn ($v) => str_starts_with(strtolower($v), 'v=spf1')));

        if ($spf === []) {
            return $this->result('TXT @', 'SPF', 'fail', '', 'Add the SPF TXT record shown above.');
        }
        if (count($spf) > 1) {
            return $this->result('TXT @', 'SPF', 'fail', implode(' | ', $spf), 'There must be exactly one SPF record; merge them into one.');
        }
        if ($ip !== '' && ! str_contains($spf[0], $ip) && ! preg_match('/\s(mx|a)(\s|$)/', $spf[0])) {
            return $this->result('TXT @', 'SPF', 'fail', $spf[0], "SPF does not allow this server ({$ip}); use the value shown above.");
        }

        if ($ip === '' || ! str_contains($spf[0], $ip)) {
            return $this->result('TXT @', 'SPF', 'warn', $spf[0], "SPF relies on mx/a; adding ip4:{$ip} is more reliable.");
        }
        // Postfix prefers IPv6 when the receiver has an AAAA record, so an
        // SPF that lists only the IPv4 address fails on those messages.
        if ($ipv6 !== '' && ! str_contains(strtolower($spf[0]), strtolower($ipv6)) && ! preg_match('/\s(mx|a)(\s|$)/', $spf[0])) {
            return $this->result('TXT @', 'SPF', 'warn', $spf[0], "SPF does not list this server's IPv6 address; add ip6:{$ipv6} so mail sent over IPv6 also passes.");
        }

        return $this->result('TXT @', 'SPF', 'pass', $spf[0], '');
    }

    private function checkDkim(string $domain, string $selector, string $publicKey): array
    {
        $key = "TXT {$selector}._domainkey";
        if ($publicKey === '') {
            return $this->result($key, 'DKIM', 'fail', '', 'Generate a DKIM key on this page first.');
        }
        $records = $this->txt("{$selector}._domainkey.{$domain}");
        $published = array_map(fn ($v) => preg_replace('/\s+/', '', $v), $records);
        $expected = preg_replace('/\s+/', '', $publicKey);

        foreach ($published as $value) {
            if (str_contains($value, 'p='.$expected)) {
                return $this->result($key, 'DKIM', 'pass', 'key matches', '');
            }
        }

        return $records === []
            ? $this->result($key, 'DKIM', 'fail', '', "Add the DKIM TXT record at {$selector}._domainkey.{$domain}.")
            : $this->result($key, 'DKIM', 'fail', 'a different key', 'The published key does not match this server; copy the value shown above again.');
    }

    private function checkDmarc(string $domain): array
    {
        $dmarc = array_values(array_filter($this->txt("_dmarc.{$domain}"), fn ($v) => str_starts_with(strtoupper($v), 'V=DMARC1')));

        return $dmarc === []
            ? $this->result('TXT _dmarc', 'DMARC', 'warn', '', 'Add the DMARC record; Gmail requires it for bulk senders.')
            : $this->result('TXT _dmarc', 'DMARC', 'pass', $dmarc[0], '');
    }

    private function checkMailHost(string $host, string $ip): array
    {
        $addresses = $this->dns->a($host);
        $found = implode(', ', $addresses);

        return match (true) {
            $addresses === [] => $this->result('host', "Mail host {$host}", 'fail', '', "Add an A record for {$host} pointing to {$ip} (DNS only, not proxied)."),
            $ip === '' || in_array($ip, $addresses, true) => $this->result('host', "Mail host {$host}", 'pass', $found, ''),
            default => $this->result('host', "Mail host {$host}", 'fail', $found, "It points to another server; set its A record to {$ip} and turn the Cloudflare proxy off."),
        };
    }

    /**
     * The AAAA record is optional, but a wrong one is worse than none: other
     * servers try IPv6 first and the mail sits in their queue.
     */
    private function checkMailHostIpv6(string $host, string $ipv6): array
    {
        $key = 'host6';
        $label = "Mail host {$host} (IPv6)";
        $addresses = $this->dns->aaaa($host);
        $found = implode(', ', $addresses);
        $packed = inet_pton($ipv6);

        if ($addresses === []) {
            return $this->result($key, $label, 'warn', '', "Optional: add an AAAA record for {$host} pointing to {$ipv6} (DNS only, not proxied) so other servers can reach it over IPv6.");
        }
        foreach ($addresses as $address) {
            if (@inet_pton(trim($address)) === $packed) {
                return $this->result($key, $label, 'pass', $found, '');
            }
        }

        return $this->result($key, $label, 'fail', $found, "The AAAA record points to another server; set it to {$ipv6} or remove it, otherwise mail sent over IPv6 never arrives.");
    }

    /**
     * Postfix sends over IPv6 whenever the receiver has an AAAA record, and
     * Gmail (550-5.7.25) rejects that mail unless the IPv6 address has a
     * forward-confirmed PTR. Until it does, the panel keeps sending on IPv4.
     */
    private function checkPtrIpv6(string $host, string $ipv6): array
    {
        $key = 'ptr6';
        $label = 'Reverse DNS (PTR) for IPv6';
        $name = strtolower(rtrim((string) ($this->dns->ptr($ipv6)[0] ?? ''), '.'));
        if ($name === '') {
            return $this->result($key, $label, 'warn', '', "No PTR for {$ipv6}. Mail goes out over IPv4 only until your server provider sets it to {$host}.");
        }
        $packed = inet_pton($ipv6);
        foreach ($this->dns->aaaa($name) as $address) {
            if (@inet_pton(trim($address)) === $packed) {
                return $name === $host
                    ? $this->result($key, $label, 'pass', $name, '')
                    : $this->result($key, $label, 'warn', $name, "Works, but matching the mail host ({$host}) is better; ask your provider to change it.");
            }
        }

        return $this->result($key, $label, 'warn', $name, "{$name} has no AAAA record pointing back to {$ipv6}, so IPv6 sending stays off. Ask your provider to set the PTR to {$host} and publish its AAAA record.");
    }

    /** Gmail rejects mail from an IP whose PTR name does not resolve back to it. */
    private function checkPtr(string $host, string $ip): array
    {
        if ($ip === '') {
            return $this->result('ptr', 'Reverse DNS (PTR)', 'warn', '', 'Server IP unknown; set dPanel_MAIL_SERVER_IP.');
        }
        $name = (string) ($this->dns->ptr($ip)[0] ?? '');
        if ($name === '') {
            return $this->result('ptr', 'Reverse DNS (PTR)', 'fail', '', "No PTR for {$ip}. Ask your server provider to set it to {$host}.");
        }
        $forward = $this->dns->a($name);
        if (! in_array($ip, $forward, true)) {
            return $this->result('ptr', 'Reverse DNS (PTR)', 'fail', $name, "{$name} does not resolve back to {$ip}; Gmail rejects this. Ask your server provider to set the PTR to {$host}.");
        }

        return $name === $host
            ? $this->result('ptr', 'Reverse DNS (PTR)', 'pass', $name, '')
            : $this->result('ptr', 'Reverse DNS (PTR)', 'warn', $name, "Works, but matching the mail host ({$host}) is better; ask your provider to change it.");
    }

    /** @return array<int, string> */
    private function txt(string $name): array
    {
        return $this->dns->txt($name);
    }

    private function result(string $key, string $label, string $status, string $found, string $hint): array
    {
        return compact('key', 'label', 'status', 'found', 'hint');
    }
}
