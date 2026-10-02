<?php

namespace App\Services\Dns;

/**
 * Finds a domain's existing records the way Cloudflare does when a domain is
 * added: ask public resolvers for the apex and a list of common names, keep
 * CNAMEs as CNAMEs, and look up hosts the found records point at. Nothing is
 * saved here; the caller shows the list for review.
 */
class ZoneRecordScanner
{
    /** Hosts most sites, mail setups and control panels publish. */
    public const COMMON_HOSTS = [
        'www', 'mail', 'webmail', 'smtp', 'imap', 'pop', 'pop3', 'mx', 'email', 'ftp', 'sftp',
        'cpanel', 'whm', 'webdisk', 'cpcalendars', 'cpcontacts', 'autodiscover', 'autoconfig',
        'api', 'app', 'admin', 'panel', 'portal', 'dashboard', 'login', 'my', 'account', 'secure',
        'blog', 'shop', 'store', 'news', 'forum', 'wiki', 'docs', 'help', 'support', 'status',
        'dev', 'staging', 'stage', 'test', 'beta', 'demo', 'm', 'mobile',
        'cdn', 'static', 'assets', 'img', 'images', 'media', 'files', 'download', 'video',
        'vpn', 'remote', 'git', 'crm', 'erp', 'calendar', 'meet', 'chat', 'server', 'ns1', 'ns2',
    ];

    /** TXT/CNAME names used by mail and verification services. */
    public const SERVICE_NAMES = [
        '_dmarc', '_mta-sts', '_smtp._tls', '_domainconnect', '_github-challenge', '_acme-challenge',
        'default._domainkey', 'google._domainkey', 'selector1._domainkey', 'selector2._domainkey',
        'k1._domainkey', 'k2._domainkey', 's1._domainkey', 's2._domainkey', 'mail._domainkey',
        'dkim._domainkey', 'mandrill._domainkey', 'zoho._domainkey', 'protonmail._domainkey', 'em._domainkey',
    ];

    public const SRV_NAMES = [
        '_autodiscover._tcp', '_submission._tcp', '_submissions._tcp', '_imap._tcp', '_imaps._tcp',
        '_pop3._tcp', '_pop3s._tcp', '_caldav._tcp', '_caldavs._tcp', '_carddav._tcp', '_carddavs._tcp',
        '_sip._tls', '_sip._tcp', '_sip._udp', '_sipfederationtls._tcp', '_xmpp-client._tcp', '_xmpp-server._tcp',
    ];

    public function __construct(private readonly PublicDnsLookup $dns) {}

    /**
     * @return array{records: list<array{name:string,type:string,content:string,priority:int|null,ttl:int}>, nameservers: list<string>, wildcard: bool}
     */
    public function scan(string $domain, int $ttl = 3600): array
    {
        $domain = rtrim(strtolower(trim($domain)), '.');
        $records = [];
        $add = function (string $name, string $type, string $content, ?int $priority = null) use (&$records, $ttl): void {
            $records["{$type} {$name} {$content} {$priority}"] = compact('name', 'type', 'content', 'priority', 'ttl');
        };

        // A random label answers only when the zone has a wildcard; any name
        // whose answer matches it came from the wildcard, not a real record.
        $probe = 'dpanel-scan-'.bin2hex(random_bytes(4)).'.'.$domain;
        $hosts = array_merge([$domain], array_map(fn ($h) => "{$h}.{$domain}", self::COMMON_HOSTS));
        $services = array_map(fn ($h) => "{$h}.{$domain}", self::SERVICE_NAMES);
        $srv = array_map(fn ($h) => "{$h}.{$domain}", self::SRV_NAMES);

        $this->dns->prefetch(array_merge(
            [[$domain, 'NS'], [$domain, 'MX'], [$domain, 'TXT'], [$domain, 'CAA'], [$probe, 'CNAME'], [$probe, 'A'], [$probe, 'AAAA']],
            array_map(fn ($name) => [$name, 'CNAME'], array_merge($hosts, $services)),
            array_map(fn ($name) => [$name, 'TXT'], $services),
            array_map(fn ($name) => [$name, 'SRV'], $srv),
        ));
        $wildcard = [
            'CNAME' => $this->dns->records($probe, 'CNAME'),
            'A' => $this->dns->records($probe, 'A'),
            'AAAA' => $this->dns->records($probe, 'AAAA'),
        ];
        $hasWildcard = $wildcard['CNAME'] !== [] || $wildcard['A'] !== [] || $wildcard['AAAA'] !== [];
        // "*" cannot be queried by name; the probe's answer is the wildcard's.
        if ($wildcard['CNAME'] !== []) {
            $add("*.{$domain}", 'CNAME', $wildcard['CNAME'][0]);
        } else {
            foreach (['A', 'AAAA'] as $type) {
                foreach ($wildcard[$type] as $value) {
                    $add("*.{$domain}", $type, $value);
                }
            }
        }
        $fromWildcard = fn (string $type, array $values): bool => $hasWildcard && $values !== []
            && isset($wildcard[$type]) && $this->same($values, $wildcard[$type]);

        foreach ($this->dns->records($domain, 'MX') as $mx) {
            [$priority, $target] = array_pad(explode(' ', $mx, 2), 2, '');
            $add($domain, 'MX', $target, (int) $priority);
        }
        foreach ($this->dns->records($domain, 'TXT') as $txt) {
            $add($domain, 'TXT', $txt);
        }
        foreach ($this->dns->records($domain, 'CAA') as $caa) {
            $add($domain, 'CAA', $caa);
        }
        foreach ($services as $name) {
            foreach ($this->dns->records($name, 'CNAME') as $target) {
                $add($name, 'CNAME', $target);
            }
            foreach ($this->dns->records($name, 'TXT') as $txt) {
                $add($name, 'TXT', $txt);
            }
        }
        foreach ($srv as $name) {
            foreach ($this->dns->records($name, 'SRV') as $value) {
                [$priority, $rest] = array_pad(explode(' ', $value, 2), 2, '');
                $add($name, 'SRV', $rest, (int) $priority);
            }
        }

        // Hosts the found records point at may not be on the common list.
        $referenced = [];
        foreach ($records as $record) {
            $target = $record['type'] === 'SRV' ? (string) last(explode(' ', $record['content'])) : $record['content'];
            if (in_array($record['type'], ['MX', 'CNAME', 'SRV'], true) && str_ends_with($target, ".{$domain}")) {
                $referenced[] = $target;
            }
        }
        $hosts = array_values(array_unique(array_merge($hosts, $referenced)));
        $this->dns->prefetch(array_map(fn ($name) => [$name, 'CNAME'], $hosts));

        $addressHosts = [];
        foreach ($hosts as $name) {
            $cname = $this->dns->records($name, 'CNAME');
            if ($name === $domain || $cname === []) {
                $addressHosts[] = $name;
            } elseif (! $fromWildcard('CNAME', $cname)) {
                $add($name, 'CNAME', $cname[0]);
            }
        }
        $this->dns->prefetch(array_merge(
            array_map(fn ($name) => [$name, 'A'], $addressHosts),
            array_map(fn ($name) => [$name, 'AAAA'], $addressHosts),
        ));
        foreach ($addressHosts as $name) {
            foreach (['A', 'AAAA'] as $type) {
                $values = $this->dns->records($name, $type);
                if ($name !== $domain && $fromWildcard($type, $values)) {
                    continue;
                }
                foreach ($values as $value) {
                    $add($name, $type, $value);
                }
            }
        }

        return [
            'records' => array_values($records),
            // Shown, not imported: they name the previous DNS host.
            'nameservers' => $this->dns->records($domain, 'NS'),
            'wildcard' => $hasWildcard,
        ];
    }

    private function same(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }
}
