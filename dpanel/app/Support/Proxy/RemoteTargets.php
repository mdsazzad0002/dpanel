<?php

namespace App\Support\Proxy;

use InvalidArgumentException;

/**
 * Backends of a remote port share (reverse proxy / load balancer), in the
 * form drust reads: "scheme://host[:port]" base URLs, no path.
 *
 *   RemoteTargets::normalize(['192.168.0.106', 'https://app.lan:8443'])
 *   // ['http://192.168.0.106', 'https://app.lan:8443']
 */
final class RemoteTargets
{
    public const MAX = 16;

    public const POLICIES = ['round_robin', 'ip_hash', 'failover'];

    /**
     * @param  iterable<int, mixed>|string  $input  list, or text separated by commas/whitespace
     * @return array<int, string>
     *
     * @throws InvalidArgumentException with a message fit for the form
     */
    public static function normalize(iterable|string $input): array
    {
        $items = is_string($input) ? (preg_split('/[\s,]+/', $input) ?: []) : $input;
        $targets = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $target = self::one($item);
            if (! in_array($target, $targets, true)) {
                $targets[] = $target;
            }
        }

        if ($targets === []) {
            throw new InvalidArgumentException('Add at least one backend, for example http://192.168.0.106:80.');
        }
        if (count($targets) > self::MAX) {
            throw new InvalidArgumentException('Use at most '.self::MAX.' backends per share.');
        }

        return $targets;
    }

    public static function one(string $value): string
    {
        $raw = str_contains($value, '://') ? $value : 'http://'.$value;
        $parts = parse_url($raw);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException("\"{$value}\" is not a backend URL. Use http://host:port or https://host:port.");
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException("\"{$value}\" has an invalid port.");
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (! $isIp && ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
            throw new InvalidArgumentException("\"{$value}\" has an invalid host name.");
        }
        if ($reason = self::blocked($host, $isIp)) {
            throw new InvalidArgumentException("\"{$value}\" cannot be used: {$reason}");
        }

        $hostPart = str_contains($host, ':') ? "[{$host}]" : $host;

        return $port === null ? "{$scheme}://{$hostPart}" : "{$scheme}://{$hostPart}:{$port}";
    }

    /** Why a host may not be a backend, or null when it may. */
    private static function blocked(string $host, bool $isIp): ?string
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return 'this server is reached with a local port share instead.';
        }
        if (! $isIp) {
            return null;
        }

        $ip = $host;
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is judged as the IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ip, $match)) {
            $ip = $match[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            $in = static fn (string $cidr): bool => (($long ^ ip2long(strtok($cidr, '/'))) >> (32 - (int) substr($cidr, strpos($cidr, '/') + 1))) === 0;
            if ($in('127.0.0.0/8')) {
                return 'this server is reached with a local port share instead.';
            }
            if ($in('0.0.0.0/8') || $in('224.0.0.0/4') || $in('240.0.0.0/4')) {
                return 'not a unicast address.';
            }
            if ($in('169.254.0.0/16')) {
                return 'link-local addresses (cloud metadata) are not allowed.';
            }

            return null;
        }

        $packed = inet_pton($ip);
        if ($ip === '::1' || $ip === '::' || $packed === inet_pton('::1')) {
            return 'this server is reached with a local port share instead.';
        }
        $first = ord($packed[0]);
        if ($first === 0xFF) {
            return 'not a unicast address.';
        }
        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return 'link-local addresses are not allowed.';
        }

        return null;
    }
}
