<?php

namespace App\Services\Seo;

use App\Support\PublicHttpFetcher;

/**
 * The URL Inspection Preview, served through the panel. Every URL on the
 * inspected site in a proxied page or stylesheet is rewritten to a proxy URL,
 * so the viewer's browser never contacts the site itself: its styles,
 * scripts, images, fonts and links all come from this server. URLs on other
 * hosts (CDNs, other sites) are left to load directly.
 *
 * A proxy URL keeps the original path after a signed origin token —
 * /seo/preview-proxy/{token}/build/app.js — so URLs that scripts resolve on
 * their own (relative module imports, script-built paths) land on the proxy
 * too. Only origins the panel signed are fetched, so it is not an open proxy.
 */
class PreviewProxy
{
    private const BYTES = 8 * 1024 * 1024;

    private const TTL_MINUTES = 60;

    /** Elements and the attributes on them that hold one URL. */
    private const URL_ATTRIBUTES = [
        'src' => ['img', 'script', 'iframe', 'frame', 'source', 'audio', 'video', 'track', 'embed', 'input'],
        'href' => ['link', 'a', 'area'],
        'poster' => ['video'],
        'data' => ['object'],
        'action' => ['form'],
        'background' => ['body', 'table', 'td', 'th'],
    ];

    public function __construct(private readonly PublicHttpFetcher $http) {}

    /** The proxy URL that serves $url. $localHost is the internal website fetched from this server. */
    public function url(string $url, string $agent, ?string $localHost): string
    {
        $parts = parse_url($url);
        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);
        $payload = rtrim(strtr(base64_encode((string) json_encode([$origin, $agent, $localHost, now()->addMinutes(self::TTL_MINUTES)->timestamp])), '+/', '-_'), '=');
        $rest = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        // Appended after url(), which would drop a trailing slash the page's own relative URLs rely on.
        return url('/seo/preview-proxy/'.$payload.'.'.$this->sign($payload)).$rest;
    }

    /**
     * What a proxy URL points at: its token, and the path and query after it.
     *
     * @return array{url: string, agent: string, local: string|null}|null null when the token is forged or expired
     */
    public function target(string $token, string $rest): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if (! hash_equals($this->sign($payload), $signature)) {
            return null;
        }
        [$origin, $agent, $localHost, $expires] = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true) + [null, null, null, 0];
        if ($expires < now()->timestamp || ! isset(UrlInspector::AGENTS[$agent])) {
            return null;
        }

        return ['url' => $origin.(str_starts_with($rest, '/') ? $rest : '/'.$rest), 'agent' => $agent, 'local' => $localHost];
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'preview-proxy|'.$payload, (string) config('app.key')), 0, 32);
    }

    /**
     * A redirect is passed on as a proxy URL rather than followed, so the
     * frame's address is the page's own and script-resolved paths match it.
     *
     * @return array{status: int, type: string, body: string, location: string|null, error: string|null}
     */
    public function fetch(string $url, string $agent, ?string $localHost): array
    {
        $ua = UrlInspector::AGENTS[$agent]['ua'] ?? null;
        $client = $ua === null ? $this->http : $this->http->withUserAgent($ua);
        if ($localHost) {
            $client = $client->withLocalHosts([$localHost]);
        }

        $response = $client->fetch($url, self::BYTES);
        $next = $response['location'] !== null ? UrlInspector::absolute($url, $response['location']) : null;
        $type = $response['type'];
        $body = $response['body'];
        if (str_contains($type, 'html')) {
            $body = $this->html(UrlInspector::utf8($body, $response['headers']['content-type'] ?? ''), $url, $agent, $localHost);
            $type = 'text/html; charset=UTF-8';
        } elseif ($type === 'text/css') {
            $body = $this->css(UrlInspector::utf8($body, $response['headers']['content-type'] ?? ''), $url, $agent, $localHost);
            $type = 'text/css; charset=UTF-8';
        }

        return ['status' => $response['status'], 'type' => $type, 'body' => $body, 'location' => $next ? $this->url($next, $agent, $localHost) : null, 'error' => $response['error']];
    }

    public function html(string $html, string $base, string $agent, ?string $localHost): string
    {
        if (trim($html) === '') {
            return $html;
        }
        $site = parse_url($base, PHP_URL_HOST);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        // The page's own <base> moves the base; the proxy then replaces it.
        foreach ($xpath->query('//base') as $node) {
            $base = UrlInspector::absolute($base, $node->getAttribute('href')) ?? $base;
            $node->parentNode->removeChild($node);
        }
        // A copied CSP would block the proxy, and rewritten CSS no longer matches its hash.
        foreach ($xpath->query('//meta[translate(@http-equiv, "CONTENT-SECURITY-POLICY", "content-security-policy")="content-security-policy"]') as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('//*[@integrity]') as $node) {
            $node->removeAttribute('integrity');
        }

        $proxy = fn (?string $ref): ?string => $this->rewrite($base, $site, $ref, $agent, $localHost);
        foreach (self::URL_ATTRIBUTES as $attribute => $tags) {
            foreach ($xpath->query('//'.implode("[@{$attribute}] | //", $tags)."[@{$attribute}]") as $node) {
                if ($url = $proxy($node->getAttribute($attribute))) {
                    $node->setAttribute($attribute, $url);
                }
            }
        }
        foreach ($xpath->query('//img[@srcset] | //source[@srcset] | //link[@imagesrcset]') as $node) {
            $attribute = $node->hasAttribute('srcset') ? 'srcset' : 'imagesrcset';
            $node->setAttribute($attribute, $this->srcset($node->getAttribute($attribute), $proxy));
        }
        foreach ($xpath->query('//*[@style]') as $node) {
            $node->setAttribute('style', $this->css($node->getAttribute('style'), $base, $agent, $localHost));
        }
        foreach ($xpath->query('//style') as $node) {
            $node->textContent = $this->css($node->textContent, $base, $agent, $localHost);
        }
        // Links open in a new tab, still through the proxy.
        $head = $xpath->query('//head')->item(0);
        if ($head) {
            $target = $document->createElement('base');
            $target->setAttribute('target', '_blank');
            $head->insertBefore($target, $head->firstChild);
            $referrer = $document->createElement('meta');
            $referrer->setAttribute('name', 'referrer');
            $referrer->setAttribute('content', 'no-referrer');
            $head->insertBefore($referrer, $head->firstChild);
        }

        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);
            }
        }

        return (string) $document->saveHTML();
    }

    public function css(string $css, string $base, string $agent, ?string $localHost): string
    {
        $site = parse_url($base, PHP_URL_HOST);
        $proxy = fn (string $ref): ?string => $this->rewrite($base, $site, $ref, $agent, $localHost);

        $css = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function (array $m) use ($proxy): string {
            $url = $proxy($m[2]);

            return $url ? 'url("'.$url.'")' : $m[0];
        }, $css) ?? $css;

        return preg_replace_callback('/@import\s+([\'"])(.*?)\1/i', function (array $m) use ($proxy): string {
            $url = $proxy($m[2]);

            return $url ? '@import "'.$url.'"' : $m[0];
        }, $css) ?? $css;
    }

    /** A proxy URL for a URL on the inspected site; any other host as a plain absolute URL. */
    private function rewrite(string $base, string $site, ?string $ref, string $agent, ?string $localHost): ?string
    {
        $absolute = UrlInspector::absolute($base, $ref);
        if ($absolute === null) {
            return null;
        }

        return strcasecmp((string) parse_url($absolute, PHP_URL_HOST), $site) === 0 ? $this->url($absolute, $agent, $localHost) : $absolute;
    }

    private function srcset(string $srcset, \Closure $proxy): string
    {
        return implode(', ', array_map(function (string $candidate) use ($proxy): string {
            $parts = preg_split('/\s+/', trim($candidate), 2);
            $url = $proxy($parts[0]);

            return trim(($url ?? $parts[0]).' '.($parts[1] ?? ''));
        }, array_filter(preg_split('/,\s+/', trim($srcset)))));
    }
}
