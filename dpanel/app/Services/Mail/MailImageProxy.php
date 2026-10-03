<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Cache;

/**
 * Fetches remote images referenced by HTML mail on the panel's behalf.
 *
 * Many senders (claude.ai among them) answer with
 * Cross-Origin-Resource-Policy: same-origin, so the browser refuses to show
 * their images inside the webmail. Serving them from our own origin fixes
 * that and also keeps the reader's IP and read time from the sender.
 */
class MailImageProxy
{
    private const MAX_BYTES = 5242880;

    private const MAX_CACHED_BYTES = 1048576;

    private const MAX_REDIRECTS = 3;

    private const CACHE_TTL = 86400;

    /**
     * Point remote image references in sanitized mail HTML at the proxy.
     */
    public function rewrite(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // srcset lists several URLs; src alone is enough to show the image.
        $html = preg_replace('/\ssrcset\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;

        $html = preg_replace_callback(
            '/(\s(?:src|background)\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i',
            fn (array $m): string => $m[1].$m[2].e($this->proxyUrl(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'))).$m[2],
            $html
        ) ?? $html;

        return preg_replace_callback(
            '/url\(\s*(&quot;|&#0?39;|["\']|)(https?:\/\/(?:(?!&quot;|&#0?39;)[^"\')\s])+)\1\s*\)/i',
            // Single quotes so it fits inside a style="" attribute; the proxy URL is
            // fully percent-encoded, and <style> text would not decode entities.
            fn (array $m): string => "url('".$this->proxyUrl(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))."')",
            $html
        ) ?? $html;
    }

    public function proxyUrl(string $url): string
    {
        return route('mailbox.image', ['url' => $url, 'sig' => $this->signature($url)]);
    }

    public function validSignature(string $url, string $signature): bool
    {
        return hash_equals($this->signature($url), $signature);
    }

    /**
     * @return array{body: string, type: string}|null
     */
    public function fetch(string $url): ?array
    {
        $cacheKey = 'mail-image:'.sha1($url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->request($url);
            if ($response === null) {
                return null;
            }

            if ($response['location'] !== null) {
                $url = $response['location'];

                continue;
            }

            if ($response['status'] !== 200 || ! str_starts_with($response['type'], 'image/')) {
                return null;
            }

            $image = ['body' => $response['body'], 'type' => $response['type']];
            if (strlen($image['body']) <= self::MAX_CACHED_BYTES) {
                Cache::put($cacheKey, $image, self::CACHE_TTL);
            }

            return $image;
        }

        return null;
    }

    /**
     * One request to a public address, pinned to the IP that was checked so
     * a second DNS answer cannot point it at the local network.
     *
     * @return array{status: int, type: string, body: string, location: string|null}|null
     */
    private function request(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user'])) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = $this->publicAddress(trim($host, '[]'));
        if ($ip === null) {
            return null;
        }

        $body = '';
        $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; dpanel-webmail-image-proxy)',
            CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/*;q=0.8'],
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = strtolower(trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
        $location = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        curl_close($curl);

        if ($tooLarge || $status === 0) {
            return null;
        }

        $redirect = $status >= 300 && $status < 400 && is_string($location) && $location !== '';

        return ['status' => $status, 'type' => $type, 'body' => $body, 'location' => $redirect ? $location : null];
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

    private function signature(string $url): string
    {
        return hash_hmac('sha256', 'mail-image|'.$url, (string) config('app.key'));
    }
}
