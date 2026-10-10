<?php

namespace App\Services\Seo;

use App\Support\PublicHttpFetcher;

/**
 * Checks a website's sitemaps the way a search engine reads them, one step at
 * a time: discover() reads robots.txt and finds the sitemaps, inspect() opens
 * one sitemap file, and checkPages() tests listed pages. Only the site's own
 * hostnames (bare and www) are ever fetched.
 */
class SitemapVerifier
{
    public const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** Protocol limits (sitemaps.org). */
    public const MAX_URLS = 50000;

    public const MAX_BYTES = 52428800;

    /** What this tool reads per sitemap file; bigger files are reported, not parsed. */
    private const READ_BYTES = 10485760;

    /** Raw XML returned for the raw view. */
    private const RAW_BYTES = 524288;

    private const SAMPLE_SIZE = 10;

    /** Entries per file returned for the details view; counts still cover every entry. */
    private const ENTRY_LIMIT = 1000;

    private const FALLBACK_PATHS = ['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'];

    /** @var array<int, array{key: string, label: string, status: string, detail: string}> */
    private array $checks = [];

    /** @var array<int, string> */
    private array $hosts = [];

    /** @var array{url: string, status: int, content: string|null, error: string|null} */
    private array $robotsFile = ['url' => '', 'status' => 0, 'content' => null, 'error' => null];

    public function __construct(private readonly PublicHttpFetcher $http) {}

    /**
     * robots.txt checks and the sitemaps a crawler would find. Nothing is
     * parsed yet; each sitemap is opened on demand with inspect().
     *
     * @return array<string, mixed>
     */
    public function discover(string $domain, bool $https, ?string $sitemapPath = null): array
    {
        $base = $this->target($domain, $https);
        $robots = $this->robots($base);
        $sitemaps = $this->find($base, $robots, $sitemapPath);

        return [
            'checked_at' => now()->toIso8601String(),
            'base_url' => $base,
            'checks' => $this->checks,
            'sitemaps' => $sitemaps,
            'robots' => $this->robotsFile,
            'totals' => [
                'sitemaps' => count($sitemaps),
                'pass' => count(array_filter($this->checks, fn ($c) => $c['status'] === 'pass')),
                'warn' => count(array_filter($this->checks, fn ($c) => $c['status'] === 'warn')),
                'fail' => count(array_filter($this->checks, fn ($c) => $c['status'] === 'fail')),
            ],
        ];
    }

    /**
     * One sitemap file: its issues, its entries (pages or sub-sitemaps) and
     * the raw XML.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the URL is not on the site
     */
    public function inspect(string $domain, bool $https, string $sitemapUrl): array
    {
        $base = $this->target($domain, $https);
        if (! $this->ownHost($sitemapUrl) || ! preg_match('#^https?://#i', $sitemapUrl)) {
            throw new \InvalidArgumentException("Only sitemaps on {$this->hosts[0]} or {$this->hosts[1]} can be opened.");
        }

        return $this->sitemap($sitemapUrl, $base);
    }

    /**
     * Fetches up to SAMPLE_SIZE listed pages and judges whether each can be
     * indexed. Pages on other hosts are dropped.
     *
     * @param  array<int, string>  $urls
     * @return array{summary: array<string, string>, samples: array<int, array<string, mixed>>}
     */
    public function checkPages(string $domain, bool $https, array $urls): array
    {
        $this->target($domain, $https);
        $urls = array_slice(array_values(array_unique(array_filter($urls, fn ($url) => is_string($url) && $this->ownHost($url)))), 0, self::SAMPLE_SIZE);
        $samples = $this->samples($urls);

        return ['summary' => end($this->checks) ?: ['key' => 'pages', 'label' => 'Listed pages indexable', 'status' => 'info', 'detail' => 'No pages on this site to check.'], 'samples' => $samples];
    }

    private function target(string $domain, bool $https): string
    {
        $this->checks = [];
        $domain = strtolower(rtrim($domain, '.'));
        $this->hosts = [$domain, str_starts_with($domain, 'www.') ? substr($domain, 4) : "www.{$domain}"];

        return ($https ? 'https' : 'http')."://{$domain}";
    }

    /** @return array{sitemaps: array<int, string>, reachable: bool} */
    private function robots(string $base): array
    {
        $response = $this->http->fetch("{$base}/robots.txt", 524288);
        $this->robotsFile = [
            'url' => "{$base}/robots.txt",
            'status' => $response['status'],
            'content' => $response['status'] === 200 ? mb_scrub($response['body'], 'UTF-8') : null,
            'error' => $response['error'],
        ];
        if ($response['status'] !== 200) {
            $this->check('robots', 'robots.txt', 'warn', $response['error'] ?? "{$base}/robots.txt answered HTTP {$response['status']}. Crawlers then assume everything is allowed, but they cannot find your sitemap from it.");

            return ['sitemaps' => [], 'reachable' => false];
        }
        $this->check('robots', 'robots.txt', 'pass', 'robots.txt is reachable.');

        $sitemaps = [];
        $groupAgents = [];
        $inRules = false;
        $blocksAll = false;
        foreach (preg_split('/\R/', $response['body']) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'sitemap') {
                $sitemaps[] = $value;
            } elseif ($field === 'user-agent') {
                // Consecutive user-agent lines share one group of rules.
                $groupAgents = $inRules ? [strtolower($value)] : [...$groupAgents, strtolower($value)];
                $inRules = false;
            } elseif (in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;
                if ($field === 'disallow' && $value === '/' && in_array('*', $groupAgents, true)) {
                    $blocksAll = true;
                }
            }
        }

        $this->check('robots-sitemap', 'Sitemap listed in robots.txt', $sitemaps === [] ? 'warn' : 'pass', $sitemaps === []
            ? 'Add a "Sitemap: '.$base.'/sitemap.xml" line so every crawler finds it without Search Console.'
            : count($sitemaps).' sitemap '.(count($sitemaps) === 1 ? 'line' : 'lines').' found.');
        $this->check('robots-block', 'Crawling allowed', $blocksAll ? 'fail' : 'pass', $blocksAll
            ? '"User-agent: *" has "Disallow: /": search engines will not crawl any page in the sitemap.'
            : 'robots.txt does not block the whole site.');

        return ['sitemaps' => $sitemaps, 'reachable' => true];
    }

    /**
     * The sitemaps to offer, without parsing them: a given path, else the
     * robots.txt entries, else the first common path that answers.
     *
     * @param  array{sitemaps: array<int, string>, reachable: bool}  $robots
     * @return array<int, array{url: string, source: string}>
     */
    private function find(string $base, array $robots, ?string $sitemapPath): array
    {
        $found = [];
        if ($sitemapPath !== null && $sitemapPath !== '') {
            $found[] = ['url' => $base.'/'.ltrim($sitemapPath, '/'), 'source' => 'custom'];
        } else {
            foreach (array_unique($robots['sitemaps']) as $url) {
                if ($this->ownHost($url)) {
                    $found[] = ['url' => $url, 'source' => 'robots'];
                } else {
                    $this->check('robots-foreign', 'Sitemap on another host', 'warn', "robots.txt lists {$url}, which is not on this website, so it cannot be opened here.");
                }
            }
        }
        if ($found === [] && ($sitemapPath ?? '') === '') {
            foreach (self::FALLBACK_PATHS as $path) {
                $probe = $this->http->fetch($base.$path, 65536);
                if ($probe['status'] === 200 && str_contains($probe['body'], '<')) {
                    $found[] = ['url' => $base.$path, 'source' => 'fallback'];
                    break;
                }
            }
        }

        if ($found === []) {
            $this->check('discovery', 'Sitemap found', 'fail', 'No sitemap in robots.txt and none at '.implode(', ', self::FALLBACK_PATHS).'.');
        } else {
            $this->check('discovery', 'Sitemap found', 'pass', count($found).' '.(count($found) === 1 ? 'sitemap' : 'sitemaps').' found. Open one to inspect it.');
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function sitemap(string $url, string $base): array
    {
        $result = ['url' => $url, 'type' => null, 'status' => 'pass', 'http_status' => 0, 'bytes' => 0, 'urls' => 0, 'children' => 0, 'issues' => [], 'entries' => [], 'entries_truncated' => false, 'sample' => [], 'raw' => '', 'raw_truncated' => false];
        $issue = function (string $level, string $message) use (&$result): void {
            $result['issues'][] = ['level' => $level, 'message' => $message];
            if ($level === 'fail' || $result['status'] === 'pass') {
                $result['status'] = $level;
            }
        };

        // What the raw view shows; scrubbed so a broken file still encodes as JSON.
        $raw = function (string $text) use (&$result): void {
            $result['raw'] = mb_scrub(substr($text, 0, self::RAW_BYTES), 'UTF-8');
            $result['raw_truncated'] = strlen($text) > self::RAW_BYTES;
        };

        $response = $this->http->fetch($url, self::READ_BYTES);
        $result['http_status'] = $response['status'];
        if (! str_starts_with($response['body'], "\x1f\x8b")) {
            $raw($response['body']);
        }
        if ($response['error'] !== null && ! $response['truncated']) {
            $issue('fail', $response['error']);

            return $result;
        }
        if ($response['location'] !== null) {
            $issue('fail', "Redirects to {$response['location']}. List the final URL instead; crawlers may not follow sitemap redirects.");

            return $result;
        }
        if ($response['status'] !== 200) {
            $issue('fail', "HTTP {$response['status']}.");

            return $result;
        }
        if ($response['truncated']) {
            $issue('warn', 'Larger than '.intdiv(self::READ_BYTES, 1048576).' MB, so it was not parsed here. The protocol allows up to 50 MB uncompressed.');

            return $result;
        }

        $body = $response['body'];
        if (str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.gz') || str_starts_with($body, "\x1f\x8b")) {
            $decoded = @gzdecode($body, self::MAX_BYTES + 1);
            if ($decoded === false) {
                $issue('fail', 'Not a valid gzip file.');

                return $result;
            }
            $body = $decoded;
            $raw($body);
        } elseif (! str_contains($response['type'], 'xml')) {
            $issue('warn', 'Served as "'.($response['type'] ?: 'no content type').'"; use application/xml or text/xml.');
        }
        $result['bytes'] = strlen($body);
        if ($result['bytes'] > self::MAX_BYTES) {
            $issue('fail', 'Over the 50 MB uncompressed limit; split it with a sitemap index.');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            $first = $errors[0] ?? null;
            $issue('fail', 'Not valid XML'.($first ? " (line {$first->line}: ".trim($first->message).')' : '').'.');

            return $result;
        }

        $root = $xml->getName();
        $namespaces = $xml->getNamespaces();
        if (! in_array($root, ['urlset', 'sitemapindex'], true)) {
            $issue('fail', "Root element is <{$root}>; a sitemap starts with <urlset> or <sitemapindex>.");

            return $result;
        }
        $result['type'] = $root === 'urlset' ? 'urlset' : 'index';
        if (($namespaces[''] ?? null) !== self::NAMESPACE) {
            $issue('warn', 'Missing or wrong xmlns; it should be '.self::NAMESPACE.'.');
        }

        $entries = $xml->children(self::NAMESPACE);
        if (count($entries) === 0) {
            $entries = $xml->children();
        }
        // Hosts are compared with the sitemap file's own host, as crawlers do.
        $fileHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $bareHost = preg_replace('/^www\./', '', $fileHost);
        $baseScheme = parse_url($base, PHP_URL_SCHEME);
        $tomorrow = time() + 86400;
        $counts = array_fill_keys(['invalid', 'foreign', 'www', 'scheme', 'duplicate', 'bad_lastmod', 'future'], 0);
        $locs = [];
        $seenLocs = [];
        foreach ($entries as $entry) {
            $fields = count($entry->children(self::NAMESPACE)) ? $entry->children(self::NAMESPACE) : $entry->children();
            $loc = trim((string) $fields->loc);
            $lastmod = trim((string) $fields->lastmod);
            $flags = [];
            if ($loc === '' || ! filter_var($loc, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $loc)) {
                $flags[] = 'invalid';
            } else {
                $host = strtolower((string) parse_url($loc, PHP_URL_HOST));
                if ($host !== $fileHost) {
                    $flags[] = preg_replace('/^www\./', '', $host) === $bareHost ? 'www' : 'foreign';
                }
                if (strtolower((string) parse_url($loc, PHP_URL_SCHEME)) !== $baseScheme) {
                    $flags[] = 'scheme';
                }
                if (isset($seenLocs[$loc])) {
                    $flags[] = 'duplicate';
                }
                $seenLocs[$loc] = true;
                $locs[] = $loc;
            }
            if ($lastmod !== '') {
                $time = preg_match('/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2}))?)?)?$/', $lastmod) ? strtotime($lastmod) : false;
                if ($time === false) {
                    $flags[] = 'bad_lastmod';
                } elseif ($time > $tomorrow) {
                    $flags[] = 'future';
                }
            }
            foreach ($flags as $flag) {
                $counts[$flag]++;
            }
            if (count($result['entries']) < self::ENTRY_LIMIT) {
                $result['entries'][] = [
                    'loc' => $loc,
                    'lastmod' => $lastmod ?: null,
                    'changefreq' => trim((string) $fields->changefreq) ?: null,
                    'priority' => trim((string) $fields->priority) ?: null,
                    'flags' => $flags,
                ];
            }
        }

        $count = count($locs) + $counts['invalid'];
        $own = array_values(array_filter(array_unique($locs), fn ($loc) => $this->ownHost($loc)));
        if ($result['type'] === 'index') {
            $result['children'] = $count;
        } else {
            $result['urls'] = $count;
            // Spread the sample over the file rather than its first entries.
            $step = max(1, intdiv(count($own), self::SAMPLE_SIZE));
            for ($i = 0; $i < count($own) && count($result['sample']) < self::SAMPLE_SIZE; $i += $step) {
                $result['sample'][] = $own[$i];
            }
        }

        $n = fn (string $key, string $one, string $many): string => $counts[$key].' '.($counts[$key] === 1 ? $one : $many);
        if ($count === 0) {
            $issue('warn', 'Contains no entries.');
        }
        if ($count > self::MAX_URLS) {
            $issue('fail', "Lists {$count} entries; the limit is ".number_format(self::MAX_URLS).' per file.');
        }
        if ($counts['invalid']) {
            $issue('fail', $n('invalid', 'entry has', 'entries have').' a missing or relative <loc>; every URL must be absolute.');
        }
        if ($counts['foreign']) {
            $issue('warn', $n('foreign', 'URL is', 'URLs are')." on another host than {$fileHost}. Crawlers skip them unless that host lists this sitemap in its robots.txt, or both sites are verified in Search Console.");
        }
        if ($counts['www']) {
            $issue('warn', $n('www', 'URL uses', 'URLs use')." the other www/non-www form of {$bareHost}. The protocol treats them as different hosts; list one form only, the one your site redirects to.");
        }
        if ($counts['scheme']) {
            $issue('warn', $n('scheme', 'URL uses', 'URLs use').' a different scheme (http/https) than the site.');
        }
        if ($counts['duplicate']) {
            $issue('warn', $n('duplicate', 'duplicate URL', 'duplicate URLs').'.');
        }
        if ($counts['bad_lastmod']) {
            $issue('warn', $n('bad_lastmod', '<lastmod> value is', '<lastmod> values are').' not in W3C date format (e.g. 2026-10-10 or 2026-10-10T08:00:00+00:00).');
        }
        if ($counts['future']) {
            $issue('warn', $n('future', '<lastmod> date is', '<lastmod> dates are').' in the future.');
        }
        $result['entries_truncated'] = $count > self::ENTRY_LIMIT;

        return $result;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, array{url: string, http_status: int, status: string, note: string}>
     */
    private function samples(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $samples = [];
        foreach ($this->http->fetchMany($urls, 262144) as $url => $response) {
            [$status, $note] = $this->judgePage($url, $response);
            $samples[] = ['url' => $url, 'http_status' => $response['status'], 'status' => $status, 'note' => $note];
        }

        $bad = count(array_filter($samples, fn ($s) => $s['status'] === 'fail'));
        $warn = count(array_filter($samples, fn ($s) => $s['status'] === 'warn'));
        $this->check('pages', 'Listed pages indexable', $bad ? 'fail' : ($warn ? 'warn' : 'pass'), $bad || $warn
            ? 'Of '.count($samples)." sampled pages, {$bad} cannot be indexed and {$warn} need attention."
            : 'All '.count($samples).' sampled pages answer 200 and allow indexing.');

        return $samples;
    }

    /**
     * @param  array{status: int, body: string, headers: array<string, string>, location: string|null, error: string|null, truncated: bool}  $response
     * @return array{0: string, 1: string}
     */
    private function judgePage(string $url, array $response): array
    {
        if ($response['status'] === 0) {
            return ['fail', $response['error'] ?? 'No response.'];
        }
        if ($response['location'] !== null) {
            return ['warn', "Redirects to {$response['location']}; list the final URL in the sitemap."];
        }
        if ($response['status'] !== 200) {
            return ['fail', "HTTP {$response['status']}."];
        }
        if (preg_match('/\bnoindex\b/i', $response['headers']['x-robots-tag'] ?? '')) {
            return ['fail', 'X-Robots-Tag header says noindex.'];
        }
        $html = $response['body'];
        if (preg_match('/<meta[^>]+name=["\']?(robots|googlebot)["\']?[^>]*>/i', $html, $meta)
            && preg_match('/content=["\'][^"\']*\bnoindex\b/i', $meta[0])) {
            return ['fail', 'The page has <meta name="robots" content="noindex">.'];
        }
        if (preg_match('/<link[^>]+rel=["\']?canonical["\']?[^>]*>/i', $html, $link)
            && preg_match('/href=["\']([^"\']+)["\']/i', $link[0], $href)) {
            $canonical = html_entity_decode(trim($href[1]));
            if (str_starts_with($canonical, '/')) {
                $canonical = preg_replace('#^(https?://[^/]+).*$#i', '$1', $url).$canonical;
            }
            if (rtrim($canonical, '/') !== rtrim($url, '/')) {
                return ['warn', "Canonical points to {$canonical}; list that URL instead."];
            }
        }

        return ['pass', 'OK'];
    }

    private function ownHost(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $this->hosts, true);
    }

    private function check(string $key, string $label, string $status, string $detail): void
    {
        $this->checks[] = compact('key', 'label', 'status', 'detail');
    }
}
