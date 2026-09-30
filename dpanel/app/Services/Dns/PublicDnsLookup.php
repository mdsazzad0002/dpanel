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

    /** @return array<int, string> */
    public function ptr(string $ip): array
    {
        return filter_var($ip, FILTER_VALIDATE_IP) ? $this->one($ip, 'PTR') : [];
    }

    /**
     * Looks several records up in one drust call, so a page of checks does
     * not wait for them one by one.
     *
     * @param  array<int, array{0: string, 1: string}>  $queries  [name, type]
     */
    public function prefetch(array $queries): void
    {
        $missing = array_values(array_filter($queries, fn ($q) => ! isset($this->memo[$this->key($q[0], $q[1])])));
        foreach (array_chunk($missing, 20) as $chunk) {
            foreach ($this->viaDrust($chunk) ?? [] as $key => $values) {
                $this->memo[$key] = $values;
            }
        }
    }

    /** @return array<int, string> */
    private function one(string $name, string $type): array
    {
        $key = $this->key($name, $type);
        if (! isset($this->memo[$key])) {
            $this->memo[$key] = $this->viaDrust([[$name, $type]])[$key] ?? $this->viaPhp($name, $type);
        }

        return $this->memo[$key];
    }

    private function key(string $name, string $type): string
    {
        return strtoupper($type).' '.rtrim(strtolower(trim($name)), '.');
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $queries
     * @return array<string, array<int, string>>|null null when drust is unavailable
     */
    private function viaDrust(array $queries): ?array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '' || $queries === []) {
            return null;
        }

        try {
            $request = Http::acceptJson()->asJson()->timeout(20);
            $token = trim((string) config('serverpanel.execution_api_token', ''));
            if ($token !== '') {
                $request = $request->withToken($token);
            }
            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/dns/lookup', [
                'queries' => array_map(fn ($q) => ['name' => $q[0], 'type' => $q[1]], $queries),
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! $response->json('success')) {
            return null;
        }

        $answers = [];
        foreach ((array) $response->json('data.answers', []) as $answer) {
            // A lookup that errored (timeout) is left out, so it falls back to PHP.
            if (($answer['error'] ?? '') === '') {
                $answers[$this->key((string) $answer['name'], (string) $answer['type'])] = array_values((array) ($answer['values'] ?? []));
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
