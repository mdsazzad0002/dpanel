<?php

namespace App\Services\Mail;

/**
 * Checks the live DNS of a mail domain against the records the Mail DNS Guide
 * asks for, plus the server-level checks Gmail enforces (mail host address and
 * reverse DNS). Lookups use the server's resolver, so a record added a minute
 * ago can still show as missing until caches expire.
 */
class MailDnsVerifier
{
    /** @var \Closure(string, int): array<int, array<string, mixed>> */
    private \Closure $lookup;

    /** @var \Closure(string): string */
    private \Closure $reverse;

    public function __construct(private readonly MailDnsRecords $records, ?\Closure $lookup = null, ?\Closure $reverse = null)
    {
        $this->lookup = $lookup ?? fn (string $name, int $type): array => @dns_get_record($name, $type) ?: [];
        $this->reverse = $reverse ?? fn (string $ip): string => (string) @gethostbyaddr($ip);
    }

    /**
     * @return array<int, array{key: string, label: string, status: 'pass'|'warn'|'fail', found: string, hint: string}>
     */
    public function verify(string $domain, string $selector, string $dkimPublicKey): array
    {
        $domain = strtolower(trim($domain));
        $host = $this->records->mailHost() ?: 'mail.'.$domain;
        $ip = $this->records->serverIp();

        return [
            $this->checkMx($domain, $host),
            $this->checkSpf($domain, $ip),
            $this->checkDkim($domain, $selector, $dkimPublicKey),
            $this->checkDmarc($domain),
            $this->checkMailHost($host, $ip),
            $this->checkPtr($host, $ip),
        ];
    }

    private function checkMx(string $domain, string $host): array
    {
        $targets = array_map(fn ($r) => rtrim(strtolower((string) ($r['target'] ?? '')), '.'), ($this->lookup)($domain, DNS_MX));
        $found = implode(', ', $targets);

        return match (true) {
            $targets === [] => $this->result('MX @', 'MX', 'fail', '', "Add an MX record for {$domain} pointing to {$host}."),
            in_array($host, $targets, true) => $this->result('MX @', 'MX', 'pass', $found, ''),
            default => $this->result('MX @', 'MX', 'fail', $found, "MX points elsewhere; point it to {$host}."),
        };
    }

    private function checkSpf(string $domain, string $ip): array
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

        return $ip !== '' && str_contains($spf[0], $ip)
            ? $this->result('TXT @', 'SPF', 'pass', $spf[0], '')
            : $this->result('TXT @', 'SPF', 'warn', $spf[0], "SPF relies on mx/a; adding ip4:{$ip} is more reliable.");
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
        $addresses = array_map(fn ($r) => (string) ($r['ip'] ?? $r['ipv6'] ?? ''), ($this->lookup)($host, DNS_A));
        $found = implode(', ', $addresses);

        return match (true) {
            $addresses === [] => $this->result('host', "Mail host {$host}", 'fail', '', "Add an A record for {$host} pointing to {$ip} (DNS only, not proxied)."),
            $ip === '' || in_array($ip, $addresses, true) => $this->result('host', "Mail host {$host}", 'pass', $found, ''),
            default => $this->result('host', "Mail host {$host}", 'fail', $found, "It points to another server; set its A record to {$ip} and turn the Cloudflare proxy off."),
        };
    }

    /** Gmail rejects mail from an IP whose PTR name does not resolve back to it. */
    private function checkPtr(string $host, string $ip): array
    {
        if ($ip === '') {
            return $this->result('ptr', 'Reverse DNS (PTR)', 'warn', '', 'Server IP unknown; set SERVERPANEL_MAIL_SERVER_IP.');
        }
        $name = rtrim(strtolower(($this->reverse)($ip)), '.');
        if ($name === '' || $name === $ip) {
            return $this->result('ptr', 'Reverse DNS (PTR)', 'fail', '', "No PTR for {$ip}. Ask your server provider to set it to {$host}.");
        }
        $forward = array_map(fn ($r) => (string) ($r['ip'] ?? ''), ($this->lookup)($name, DNS_A));
        if (! in_array($ip, $forward, true)) {
            return $this->result('ptr', 'Reverse DNS (PTR)', 'fail', $name, "{$name} does not resolve back to {$ip}; Gmail rejects this. Ask your server provider to set the PTR to {$host}.");
        }

        return $name === $host
            ? $this->result('ptr', 'Reverse DNS (PTR)', 'pass', $name, '')
            : $this->result('ptr', 'Reverse DNS (PTR)', 'warn', $name, "Works, but matching the mail host ({$host}) is better; ask your provider to change it.");
    }

    /** @return array<int, string> TXT values with their 255-byte chunks joined */
    private function txt(string $name): array
    {
        return array_map(
            fn ($r) => isset($r['entries']) ? implode('', (array) $r['entries']) : (string) ($r['txt'] ?? ''),
            ($this->lookup)($name, DNS_TXT)
        );
    }

    private function result(string $key, string $label, string $status, string $found, string $hint): array
    {
        return compact('key', 'label', 'status', 'found', 'hint');
    }
}
