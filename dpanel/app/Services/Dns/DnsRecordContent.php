<?php

namespace App\Services\Dns;

use InvalidArgumentException;

/**
 * Checks a record's content for its type and returns it in the form PowerDNS
 * serves from the gmysql backend: host names without a trailing dot, TXT and
 * CAA values quoted, and MX/SRV priority kept in its own column.
 */
class DnsRecordContent
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'];

    /** Types that point at a host name and may not share a name with a CNAME. */
    private const HOST_TYPES = ['CNAME', 'NS', 'PTR'];

    /**
     * @return array{content:string, priority:int}
     *
     * @throws InvalidArgumentException with a message fit to show the user
     */
    public function normalize(string $type, string $content, ?int $priority, string $zoneDomain): array
    {
        $type = strtoupper(trim($type));
        $content = trim($content);
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("{$type} records are not supported.");
        }
        if ($content === '') {
            throw new InvalidArgumentException('Content is required.');
        }

        return match ($type) {
            'A' => ['content' => $this->ip($content, FILTER_FLAG_IPV4, 'an IPv4 address'), 'priority' => 0],
            'AAAA' => ['content' => strtolower($this->ip($content, FILTER_FLAG_IPV6, 'an IPv6 address')), 'priority' => 0],
            'CNAME', 'NS', 'PTR' => ['content' => $this->host($content, $zoneDomain), 'priority' => 0],
            'MX' => $this->mx($content, $priority, $zoneDomain),
            'TXT' => ['content' => $this->txt($content), 'priority' => 0],
            'SRV' => $this->srv($content, $priority, $zoneDomain),
            'CAA' => ['content' => $this->caa($content), 'priority' => 0],
        };
    }

    /**
     * Checks the owner name of a record: labels of letters, digits, hyphens
     * and underscores (for _dmarc, _domainkey, SRV), with an optional leading
     * "*" wildcard label.
     */
    public function assertValidName(string $fqdn): void
    {
        if (strlen($fqdn) > 253) {
            throw new InvalidArgumentException('Name is longer than 253 characters.');
        }
        $labels = explode('.', $fqdn);
        foreach ($labels as $index => $label) {
            if ($index === 0 && $label === '*') {
                continue;
            }
            if (preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/i', $label) !== 1) {
                throw new InvalidArgumentException("Name \"{$fqdn}\" has an invalid label \"{$label}\".");
            }
        }
    }

    /** Reverse of normalize() for TXT: the value as one unquoted string. */
    public function unquoteTxt(string $content): string
    {
        if (! str_starts_with(trim($content), '"')) {
            return $content;
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $content, $matches);

        return stripcslashes(implode('', $matches[1]));
    }

    public static function isHostType(string $type): bool
    {
        return in_array(strtoupper($type), self::HOST_TYPES, true);
    }

    private function ip(string $content, int $flag, string $label): string
    {
        if (filter_var($content, FILTER_VALIDATE_IP, $flag) === false) {
            throw new InvalidArgumentException("Content must be {$label}.");
        }

        return $content;
    }

    /** "@" means the zone apex, as in the record name field. */
    private function host(string $content, string $zoneDomain): string
    {
        $host = strtolower(rtrim(trim($content), '.'));
        if ($host === '@') {
            return $zoneDomain;
        }
        if ($host === '' || strlen($host) > 253
            || preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            throw new InvalidArgumentException("\"{$content}\" is not a valid host name.");
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Use a host name here, not an IP address. Point the host name at the IP with an A record.');
        }

        return $host;
    }

    /** @return array{content:string, priority:int} */
    private function mx(string $content, ?int $priority, string $zoneDomain): array
    {
        // Accept "10 mail.example.com" as pasted from other providers.
        if (preg_match('/^(\d{1,5})\s+(\S+)$/', $content, $matches) === 1) {
            $priority = (int) $matches[1];
            $content = $matches[2];
        }

        return ['content' => $this->host($content, $zoneDomain), 'priority' => $this->port($priority ?? 10, 'Priority')];
    }

    /**
     * PowerDNS keeps SRV priority in its own column and "weight port target"
     * in content. "priority weight port target" is accepted too.
     *
     * @return array{content:string, priority:int}
     */
    private function srv(string $content, ?int $priority, string $zoneDomain): array
    {
        $parts = preg_split('/\s+/', $content) ?: [];
        if (count($parts) === 4) {
            $priority = (int) array_shift($parts);
        }
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new InvalidArgumentException('SRV content must be "weight port target", for example "5 5060 sip.example.com".');
        }
        $weight = $this->port((int) $parts[0], 'Weight');
        $port = $this->port((int) $parts[1], 'Port');
        // A target of "." means the service is not offered at this name.
        $target = trim($parts[2]) === '.' ? '.' : $this->host($parts[2], $zoneDomain);

        return ['content' => "{$weight} {$port} {$target}", 'priority' => $this->port($priority ?? 0, 'Priority')];
    }

    /** Splits long values into 255-byte strings, the most one TXT string holds. */
    private function txt(string $content): string
    {
        if (str_starts_with($content, '"')) {
            if (preg_match('/^(?:"(?:[^"\\\\]|\\\\.){0,255}"\s*)+$/', $content) !== 1) {
                throw new InvalidArgumentException('Quoted TXT content must be one or more "strings" of up to 255 characters each.');
            }

            return $content;
        }

        return implode(' ', array_map(
            fn (string $chunk): string => '"'.addcslashes($chunk, '\\"').'"',
            str_split($content, 255),
        ));
    }

    /** "0 issue \"letsencrypt.org\"" — the value is quoted if it is not already. */
    private function caa(string $content): string
    {
        if (preg_match('/^(\d{1,3})\s+([a-z0-9]+)\s+(.+)$/i', $content, $matches) !== 1 || (int) $matches[1] > 255) {
            throw new InvalidArgumentException('CAA content must be "flags tag value", for example 0 issue "letsencrypt.org".');
        }
        $tag = strtolower($matches[2]);
        if (! in_array($tag, ['issue', 'issuewild', 'iodef', 'issuemail', 'issuevmc'], true)) {
            throw new InvalidArgumentException("CAA tag must be issue, issuewild, iodef, issuemail or issuevmc, not \"{$matches[2]}\".");
        }
        $value = trim($matches[3]);
        $value = str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2
            ? substr($value, 1, -1)
            : $value;

        return ((int) $matches[1]).' '.$tag.' "'.addcslashes($value, '\\"').'"';
    }

    private function port(int $value, string $label): int
    {
        if ($value < 0 || $value > 65535) {
            throw new InvalidArgumentException("{$label} must be between 0 and 65535.");
        }

        return $value;
    }
}
