<?php

namespace App\Services\Dns;

use Illuminate\Support\Facades\Http;

/**
 * DNS answers as the rest of the internet sees them (like `dig @1.1.1.1`).
 *
 * gethostbyname()/gethostbyaddr() read /etc/hosts first, so a server listing
 * its own hostname there looked correctly set up while Gmail saw another
 * address. drust asks Cloudflare/Google directly without a cache; when drust
 * is unreachable, dns_get_record() (the system resolver, but no hosts file)
 * is the fallback.
 */
class PublicDnsLookup
{
    /** @var array<string, array<int, string>> answers already fetched in this request */
    private array $memo = [];

    /** @var array<string, true> lookups drust could not answer (timeout, SERVFAIL) */
    private array $failed = [];

    /** Once drust is unreachable, every lookup uses PHP so answers never mix sources. */
    private bool $drustDown = false;

    /** @return array<int, string> */
    public function a(string $name): array
    {
        return $this->one($name, 'A');
    }

    /** @return array<int, string> MX targets, lowercase, without the trailing dot */
    public function mx(string $name): array
    {
        return array_map(fn ($v) => (string) (explode(' ', $v, 2)[1] ?? $v), $this->one($name, 'MX'));
    }

    /** @return array<int, string> each record's strings joined */
    public function txt(string $name): array
    {
        return $this->one($name, 'TXT');
    }

    /**
     * Any supported type, in drust's answer format: CNAME/NS hostnames, MX
     * "priority host", SRV "priority weight port target", CAA `flags tag "value"`.
     *
     * @return array<int, string>
     */
    public function records(string $name, string $type): array
    {
        return $this->one($name, strtoupper($type));
    }

    /** @return array<int, string> */
    public function ptr(string $ip): array
    {
        return filter_var($ip, FILTER_VALIDATE_IP) ? $this->one($ip, 'PTR') : [];
    }

    /**
     * True when the lookup did not get an answer, as opposed to an answer of
     * "no such record". Callers must not treat that as a wrong record.
     */
    public function failed(string $name, string $type): bool
    {
        return isset($this->failed[$this->key($name, $type)]);
    }

    /**
     * Looks several records up in one drust call, so a page of checks does
     * not wait for them one by one.
     *
     * @param  array<int, array{0: string, 1: string}>  $queries  [name, type]
     */
    public function prefetch(array $queries): void
    {
        $missing = [];
        foreach ($queries as $query) {
            if ($query[0] !== '' && ! isset($this->memo[$this->key($query[0], $query[1])])) {
                $missing[$this->key($query[0], $query[1])] = $query;
            }
        }
        // drust answers 20 per call; the calls go out together.
        $this->fetch(array_chunk(array_values($missing), 20));
    }

    /** @return array<int, string> */
    private function one(string $name, string $type): array
    {
        $key = $this->key($name, $type);
        if (! isset($this->memo[$key])) {
            $this->fetch([[[$name, $type]]]);
        }

        return $this->memo[$key] ?? [];
    }

    /** @param  array<int, array<int, array{0: string, 1: string}>>  $chunks */
    private function fetch(array $chunks): void
    {
        $results = $this->drustDown ? array_fill(0, count($chunks), null) : $this->viaDrust($chunks);
        foreach ($chunks as $index => $queries) {
            $answers = $results[$index] ?? null;
            if ($answers === null) {
                $this->drustDown = true;
                foreach ($queries as [$name, $type]) {
                    $this->memo[$this->key($name, $type)] = $this->viaPhp($name, $type);
                }

                continue;
            }
            foreach ($queries as [$name, $type]) {
                $key = $this->key($name, $type);
                if (array_key_exists($key, $answers) && $answers[$key] === null) {
                    // An older drust that does not know this type yet.
                    $this->memo[$key] = $this->viaPhp($name, $type);
                } elseif (array_key_exists($key, $answers)) {
                    $this->memo[$key] = $answers[$key];
                } else {
                    $this->memo[$key] = [];
                    $this->failed[$key] = true;
                }
            }
        }
    }

    private function key(string $name, string $type): string
    {
        return strtoupper($type).' '.rtrim(strtolower(trim($name)), '.');
    }

    /**
     * @param  array<int, array<int, array{0: string, 1: string}>>  $chunks
     * @return array<int, array<string, array<int, string>|null>|null> per chunk: answered lookups, or null when drust did not answer
     */
    private function viaDrust(array $chunks): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '' || $chunks === []) {
            return array_fill(0, count($chunks), null);
        }
        $url = rtrim($baseUrl, '/').'/api/v1/dns/lookup';
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        $request = function ($http, array $queries) use ($url, $token) {
            $http = $http->acceptJson()->asJson()->timeout(15);

            return ($token !== '' ? $http->withToken($token) : $http)->post($url, [
                'queries' => array_map(fn ($q) => ['name' => $q[0], 'type' => $q[1]], $queries),
            ]);
        };

        try {
            $responses = count($chunks) === 1
                ? [$request(Http::getFacadeRoot(), $chunks[0])]
                : Http::pool(fn ($pool) => array_map(fn ($queries) => $request($pool, $queries), $chunks));
        } catch (\Throwable) {
            return array_fill(0, count($chunks), null);
        }

        return array_map(fn ($response) => $this->drustAnswers($response), array_values($responses));
    }

    /** @return array<string, array<int, string>|null>|null */
    private function drustAnswers(mixed $response): ?array
    {
        if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful() || ! $response->json('success')) {
            return null;
        }

        // A lookup that errored is left out, and fetch() records it as failed;
        // one drust could not do is null, and fetch() asks PHP instead.
        $answers = [];
        foreach ((array) $response->json('data.answers', []) as $answer) {
            $key = $this->key((string) ($answer['name'] ?? ''), (string) ($answer['type'] ?? ''));
            if (str_starts_with((string) ($answer['error'] ?? ''), 'Unsupported record type')) {
                $answers[$key] = null;
            } elseif (($answer['error'] ?? '') === '') {
                $answers[$key] = array_values((array) ($answer['values'] ?? []));
            }
        }

        return $answers;
    }

    /** @return array<int, string> */
    private function viaPhp(string $name, string $type): array
    {
        $host = fn ($v) => rtrim(strtolower((string) $v), '.');

        return match (strtoupper($type)) {
            'A' => array_map(fn ($r) => (string) $r['ip'], @dns_get_record($name, DNS_A) ?: []),
            'MX' => array_map(fn ($r) => $r['pri'].' '.$host($r['target']), @dns_get_record($name, DNS_MX) ?: []),
            'TXT' => array_map(fn ($r) => isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? ''), @dns_get_record($name, DNS_TXT) ?: []),
            'PTR' => array_map(fn ($r) => $host($r['target']), @dns_get_record($this->reverseName($name), DNS_PTR) ?: []),
            'AAAA' => array_map(fn ($r) => (string) $r['ipv6'], @dns_get_record($name, DNS_AAAA) ?: []),
            // dns_get_record() also returns the records behind an alias; keep the name's own.
            'CNAME' => array_values(array_map(fn ($r) => $host($r['target']), array_filter(@dns_get_record($name, DNS_CNAME) ?: [], fn ($r) => ($r['type'] ?? '') === 'CNAME' && $host($r['host']) === $host($name)))),
            'NS' => array_map(fn ($r) => $host($r['target']), @dns_get_record($name, DNS_NS) ?: []),
            'SRV' => array_map(fn ($r) => "{$r['pri']} {$r['weight']} {$r['port']} ".$host($r['target']), @dns_get_record($name, DNS_SRV) ?: []),
            'CAA' => array_map(fn ($r) => "{$r['flags']} {$r['tag']} \"{$r['value']}\"", @dns_get_record($name, DNS_CAA) ?: []),
            default => [],
        };
    }

    private function reverseName(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        }
        $hex = bin2hex((string) inet_pton($ip));

        return implode('.', array_reverse(str_split($hex))).'.ip6.arpa';
    }
}
