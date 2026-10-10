<?php

namespace App\Support;

/**
 * GET requests to public addresses only. Each host is resolved once, checked
 * against private/reserved ranges and pinned with CURLOPT_RESOLVE, so a second
 * DNS answer cannot point the request at the local network. Redirects are
 * never followed here; callers decide whether the next hop is acceptable.
 */
class PublicHttpFetcher
{
    public function __construct(
        private string $userAgent = 'Mozilla/5.0 (compatible; dpanel-seo-tools)',
        private readonly int $timeout = 10,
    ) {}

    /** A copy that sends another User-Agent, e.g. to see what a crawler is served. */
    public function withUserAgent(string $userAgent): static
    {
        $copy = clone $this;
        $copy->userAgent = $userAgent;

        return $copy;
    }

    /**
     * @return array{url: string, status: int, type: string, body: string, headers: array<string, string>, location: string|null, truncated: bool, error: string|null, time_ms: int|null}
     */
    public function fetch(string $url, int $maxBytes): array
    {
        return $this->fetchMany([$url], $maxBytes)[$url];
    }

    /**
     * Fetches every URL concurrently. A body over $maxBytes is cut there and
     * flagged as truncated rather than failing the request.
     *
     * @param  array<int, string>  $urls
     * @return array<string, array{url: string, status: int, type: string, body: string, headers: array<string, string>, location: string|null, truncated: bool, error: string|null, time_ms: int|null}>
     */
    public function fetchMany(array $urls, int $maxBytes): array
    {
        $results = [];
        $handles = [];
        $state = [];
        $multi = curl_multi_init();
        foreach (array_unique($urls) as $url) {
            [$curl, $error] = $this->handle($url, $maxBytes, $state);
            if ($curl === null) {
                $results[$url] = $this->result($url, 0, '', '', [], null, false, $error, null);

                continue;
            }
            $handles[$url] = $curl;
            curl_multi_add_handle($multi, $curl);
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $url => $curl) {
            $id = spl_object_id($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $truncated = $state[$id]['truncated'];
            $error = $code === 0 && ! $truncated ? (curl_error($curl) ?: 'No response') : null;
            $location = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            $results[$url] = $this->result(
                $url,
                $code,
                strtolower(trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0])),
                $state[$id]['body'],
                $state[$id]['headers'],
                $code >= 300 && $code < 400 && is_string($location) && $location !== '' ? $location : null,
                $truncated,
                $error,
                $code > 0 ? (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000) : null,
            );
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);

        return $results;
    }

    /**
     * @param  array<int, array{body: string, headers: array<string, string>, truncated: bool}>  $state
     * @return array{0: \CurlHandle|null, 1: string|null}
     */
    private function handle(string $url, int $maxBytes, array &$state): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['port'])) {
            return [null, 'Only plain http(s) URLs on the default port are fetched.'];
        }
        $ip = $this->publicAddress(trim($host, '[]'));
        if ($ip === null) {
            return [null, "{$host} does not resolve to a public address."];
        }

        $curl = curl_init($url);
        $id = spl_object_id($curl);
        $state[$id] = ['body' => '', 'headers' => [], 'truncated' => false];
        $port = $scheme === 'https' ? 443 : 80;
        curl_setopt_array($curl, [
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$state, $id): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $state[$id]['headers'][strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$state, $id, $maxBytes): int {
                $room = $maxBytes - strlen($state[$id]['body']);
                if (strlen($chunk) > $room) {
                    $state[$id]['body'] .= substr($chunk, 0, max(0, $room));
                    $state[$id]['truncated'] = true;

                    return 0;
                }
                $state[$id]['body'] .= $chunk;

                return strlen($chunk);
            },
        ]);

        return [$curl, null];
    }

    private function publicAddress(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $addresses = array_values(array_filter(array_map(
                static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
                $records
            )));
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return $addresses[0] ?? null;
    }

    /** @param array<string, string> $headers */
    private function result(string $url, int $status, string $type, string $body, array $headers, ?string $location, bool $truncated, ?string $error, ?int $time_ms): array
    {
        return compact('url', 'status', 'type', 'body', 'headers', 'location', 'truncated', 'error', 'time_ms');
    }
}
