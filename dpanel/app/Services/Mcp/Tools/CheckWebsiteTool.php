<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpFileAccess;
use App\Services\Mcp\McpToolException;

/**
 * End-to-end probe of a site as a visitor sees it, plus the same request sent
 * straight to this server, so a broken site can be pinned on DNS, the proxy
 * in front, TLS, or the origin. The raw results are also read into a list of
 * issues, so the model starts from conclusions instead of curl output.
 */
class CheckWebsiteTool implements McpTool
{
    /** Response headers worth reporting; the rest is noise for diagnosis. */
    private const HEADERS = ['server', 'content-type', 'location', 'cache-control', 'x-powered-by', 'strict-transport-security', 'content-security-policy', 'x-frame-options', 'x-content-type-options', 'cf-ray', 'x-cache', 'retry-after'];

    public function __construct(private readonly McpFileAccess $files) {}

    public function name(): string
    {
        return 'check_website';
    }

    public function title(): string
    {
        return 'Check website';
    }

    public function description(): string
    {
        return 'Live end-to-end check of a hosted website: where its DNS points versus this server\'s IPs, whether http redirects to https, the public HTTPS status with a timing breakdown (DNS, connect, TLS, time to first byte), the certificate (issuer, expiry, names it covers), key response headers, and the same request sent straight to this server to tell origin problems from DNS/proxy problems. Returns a list of detected issues. Use it when a site is down, slow, shows a certificate warning, or after a fix to verify it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'The website domain, e.g. example.com.'],
                'path' => ['type' => 'string', 'default' => '/', 'description' => 'Path to request, e.g. /wp-login.php. Defaults to the home page.'],
                'server_id' => ServerTarget::SCHEMA,
            ],
            'required' => ['domain'],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        if ($domain === '') {
            throw new McpToolException('domain is required.');
        }

        $site = Website::query()
            ->visibleTo($token->user)
            ->whereIn('domain', [$domain, preg_replace('/^www\./', '', $domain)])
            ->first();

        if (! $site) {
            throw new McpToolException("No website found for {$domain}. Call list_websites to search.");
        }

        // Probe the name asked for (www. too), but only once it is a site here.
        $host = preg_match('/^[a-z0-9.-]+$/', $domain) === 1 ? $domain : $site->domain;
        $path = '/'.ltrim(trim((string) ($arguments['path'] ?? '/')), '/');
        if (preg_match('/[\s\x00-\x1F\x7F]/', $path) === 1 || strlen($path) > 512) {
            throw new McpToolException('path must be a URL path without spaces.');
        }

        $h = escapeshellarg($host);
        $https = escapeshellarg("https://{$host}{$path}");
        $timing = "'%{http_code}|%{time_namelookup}|%{time_connect}|%{time_appconnect}|%{time_starttransfer}|%{time_total}|%{size_download}|%{ssl_verify_result}|%{num_redirects}|%{url_effective}'";
        $server = $this->files->server($arguments['server_id'] ?? null);

        $raw = $this->files->run($server, implode("\n", [
            'echo @@dns; getent ahosts '.$h." 2>/dev/null | awk '{print \$1}' | sort -u",
            "echo @@server_ips; hostname -I 2>/dev/null | tr ' ' '\\n' | grep -v '^\$'",
            "echo @@http; curl -s -o /dev/null -m 15 -w '%{http_code}|%{redirect_url}' ".escapeshellarg("http://{$host}{$path}").' 2>/dev/null; echo',
            "echo @@https; curl -sk -L --max-redirs 5 -o /dev/null -m 20 -w {$timing} {$https} 2>/dev/null; echo",
            "echo @@origin; curl -sk -o /dev/null -m 20 --resolve {$h}:443:127.0.0.1 -w {$timing} {$https} 2>/dev/null; echo",
            "echo @@headers; curl -skI -m 15 {$https} 2>/dev/null | tr -d '\\r' | head -n 40",
            "echo @@cert; echo | timeout 10 openssl s_client -servername {$h} -connect {$h}:443 2>/dev/null | openssl x509 -noout -issuer -enddate -ext subjectAltName 2>/dev/null",
            'true',
        ]), $token);

        $sections = $this->sections($raw);
        $dns = array_values(array_filter($sections['dns'] ?? []));
        $serverIps = array_values(array_filter($sections['server_ips'] ?? []));
        [$httpStatus, $httpRedirect] = array_pad(explode('|', (string) ($sections['http'][0] ?? ''), 2), 2, '');
        $public = $this->timing($sections['https'][0] ?? '');
        $origin = $this->timing($sections['origin'][0] ?? '');
        $headers = $this->headers($sections['headers'] ?? []);
        $certificate = $this->certificate($sections['cert'] ?? []);

        $result = [
            'url' => "https://{$host}{$path}",
            'dns' => ['resolves_to' => $dns, 'server_ips' => $serverIps, 'points_here' => $dns !== [] && array_intersect($dns, $serverIps) !== []],
            'http' => ['status' => (int) $httpStatus ?: null, 'redirects_to' => $httpRedirect ?: null],
            'https' => $public,
            'origin_direct' => $origin,
            'headers' => $headers,
            'certificate' => $certificate,
        ];

        return [...$result, 'issues' => $this->issues($result, $site)];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function sections(string $raw): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^@@(\w+)$/', $line, $m) === 1) {
                $current = $m[1];
                $sections[$current] = [];
            } elseif ($current !== null && trim($line) !== '') {
                $sections[$current][] = trim($line);
            }
        }

        return $sections;
    }

    private function timing(string $line): ?array
    {
        $parts = explode('|', $line);
        if (count($parts) < 10 || (int) $parts[0] === 0) {
            return null;
        }

        $ms = fn (string $seconds) => (int) round((float) $seconds * 1000);

        return [
            'status' => (int) $parts[0],
            'dns_ms' => $ms($parts[1]),
            'connect_ms' => $ms($parts[2]),
            'tls_ms' => $ms($parts[3]),
            'ttfb_ms' => $ms($parts[4]),
            'total_ms' => $ms($parts[5]),
            'bytes' => (int) $parts[6],
            'certificate_valid' => $parts[7] === '0',
            'redirects' => (int) $parts[8],
            'final_url' => $parts[9],
        ];
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function headers(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
                $headers = ['status' => (int) $m[1]];
            } elseif (preg_match('/^([\w-]+):\s*(.*)$/', $line, $m) === 1 && in_array(strtolower($m[1]), self::HEADERS, true)) {
                $headers[strtolower($m[1])] = mb_substr($m[2], 0, 300);
            }
        }

        return $headers;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function certificate(array $lines): ?array
    {
        $text = implode("\n", $lines);
        if (! preg_match('/notAfter=(.+)/', $text, $end)) {
            return null;
        }

        preg_match('/issuer=\s*(.+)/', $text, $issuer);
        preg_match_all('/DNS:([^,\s]+)/', $text, $names);
        $expires = strtotime(trim($end[1]));

        return [
            'issuer' => isset($issuer[1]) ? trim($issuer[1]) : null,
            'expires_at' => $expires ? date(DATE_ATOM, $expires) : trim($end[1]),
            'days_left' => $expires ? (int) floor(($expires - time()) / 86400) : null,
            'names' => array_slice($names[1], 0, 20),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function issues(array $r, Website $site): array
    {
        $issues = [];
        $host = parse_url($r['url'], PHP_URL_HOST);
        $public = $r['https'];
        $origin = $r['origin_direct'];

        if ($r['dns']['resolves_to'] === []) {
            $issues[] = "DNS: {$host} does not resolve. Check the zone (list_dns_records) and the domain's nameservers.";
        } elseif (! $r['dns']['points_here']) {
            $proxied = isset($r['headers']['cf-ray']) ? ' Responses carry cf-ray, so Cloudflare (or another proxy) is in front; that is expected if proxying is intended.' : '';
            $issues[] = 'DNS: '.$host.' resolves to '.implode(', ', $r['dns']['resolves_to']).', not to this server ('.implode(', ', $r['dns']['server_ips']).').'.$proxied;
        }

        if (! $public) {
            $issues[] = 'HTTPS: the public request got no response (timeout, refused or TLS failure).';
        } elseif ($public['status'] >= 500) {
            $issues[] = "HTTPS: the site answers {$public['status']}; look at find_website_errors for the cause.";
        } elseif ($public['status'] >= 400) {
            $issues[] = "HTTPS: the site answers {$public['status']}.";
        }

        if (! $origin) {
            $issues[] = 'Origin: this server does not answer for the site on 127.0.0.1:443; check edge-gateway with get_service_status.';
        } elseif ($public && $origin['status'] < 400 && $public['status'] >= 500) {
            $issues[] = "The origin answers {$origin['status']} directly but the public URL answers {$public['status']}: the problem is between visitors and this server (DNS or a proxy in front), not the site itself.";
        }

        if ($public && $public['ttfb_ms'] > 1500) {
            $issues[] = "Slow: time to first byte is {$public['ttfb_ms']} ms. Look for slow queries, a cold cache or an overloaded PHP-FPM pool.";
        }

        if (($r['http']['status'] ?? 0) === 200 && $site->enable_ssl) {
            $issues[] = 'http:// serves the page instead of redirecting to https://.';
        }

        $cert = $r['certificate'];
        if (! $cert && $site->enable_ssl) {
            $issues[] = 'TLS: no certificate could be read on port 443.';
        } elseif ($cert) {
            if (($cert['days_left'] ?? 99) < 0) {
                $issues[] = 'TLS: the certificate expired on '.$cert['expires_at'].'.';
            } elseif (($cert['days_left'] ?? 99) < 14) {
                $issues[] = "TLS: the certificate expires in {$cert['days_left']} days; check auto-renewal (list_ssl_certificates).";
            }
            if ($cert['names'] !== [] && ! $this->covers($cert['names'], (string) $host)) {
                $issues[] = "TLS: the certificate does not cover {$host} (it covers ".implode(', ', array_slice($cert['names'], 0, 5)).').';
            }
        }
        if ($public && ! $public['certificate_valid'] && $cert && ($cert['days_left'] ?? 0) >= 0) {
            $issues[] = 'TLS: the certificate chain does not verify (self-signed, wrong name or missing intermediate).';
        }

        return $issues;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function covers(array $names, string $host): bool
    {
        foreach ($names as $name) {
            if ($name === $host || (str_starts_with($name, '*.') && substr_count($host, '.') === substr_count($name, '.') && str_ends_with($host, substr($name, 1)))) {
                return true;
            }
        }

        return false;
    }
}
