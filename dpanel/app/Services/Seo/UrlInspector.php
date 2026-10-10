<?php

namespace App\Services\Seo;

use App\Support\PublicHttpFetcher;

/**
 * Inspects one public URL the way search engines and link previews read it:
 * the redirect chain and response, whether Google, Bing and Yandex may crawl
 * and index it, its title and snippet, Open Graph / X cards and their image,
 * the favicons, and the web app manifest. Every linked file is fetched
 * through PublicHttpFetcher, so only public addresses are ever requested.
 */
class UrlInspector
{
    /** User agents the page can be fetched as, to spot bot-specific responses. */
    public const AGENTS = [
        'default' => ['label' => 'dpanel SEO tools', 'ua' => null],
        'googlebot' => ['label' => 'Googlebot (smartphone)', 'ua' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
        'bingbot' => ['label' => 'Bingbot', 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/131.0.0.0 Safari/537.36'],
        'yandex' => ['label' => 'YandexBot', 'ua' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
        'facebook' => ['label' => 'Facebook link preview', 'ua' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
        'twitter' => ['label' => 'X (Twitter) card', 'ua' => 'Twitterbot/1.0'],
    ];

    /** robots.txt token and the meta robots names each engine obeys. */
    private const ENGINES = [
        'google' => ['label' => 'Google', 'token' => 'googlebot', 'names' => ['robots', 'googlebot']],
        'bing' => ['label' => 'Bing', 'token' => 'bingbot', 'names' => ['robots', 'bingbot', 'msnbot']],
        'yandex' => ['label' => 'Yandex', 'token' => 'yandexbot', 'names' => ['robots', 'yandex', 'yandexbot']],
    ];

    private const SECTIONS = [
        'http' => 'Response',
        'indexing' => 'Indexing',
        'content' => 'Title, snippet & content',
        'social' => 'Open Graph & X card',
        'favicon' => 'Favicon',
        'pwa' => 'Web app (PWA)',
    ];

    private const PAGE_BYTES = 2097152;

    /** Linked files (robots.txt, icons, share images, manifest); Facebook accepts share images up to 8 MB. */
    private const FILE_BYTES = 8388608;

    /** Images up to this size are returned inline for the previews. */
    private const PREVIEW_BYTES = 1572864;

    private const MAX_HOPS = 5;

    /** Directives that carry a value after a colon, so they are not a user-agent prefix in X-Robots-Tag. */
    private const VALUE_DIRECTIVES = ['max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after'];

    /** @var array<string, array<int, array{key: string, label: string, status: string, detail: string}>> */
    private array $checks = [];

    private PublicHttpFetcher $client;

    public function __construct(private readonly PublicHttpFetcher $http) {}

    /** @return array<string, mixed> */
    public function inspect(string $url, string $agent = 'default'): array
    {
        $this->checks = array_fill_keys(array_keys(self::SECTIONS), []);
        $ua = self::AGENTS[$agent]['ua'] ?? null;
        $this->client = $ua === null ? $this->http : $this->http->withUserAgent($ua);

        $page = $this->fetchFollowing([$url], self::PAGE_BYTES)[$url];
        $response = $page['response'];
        $final = $page['url'];
        $report = [
            'checked_at' => now()->toIso8601String(),
            'requested_url' => $url,
            'final_url' => $final,
            'agent' => self::AGENTS[$agent]['label'] ?? self::AGENTS['default']['label'],
            'http' => [
                'status' => $response['status'],
                'time_ms' => $response['time_ms'] ?? null,
                'type' => $response['type'],
                'bytes' => strlen($response['body']),
                'truncated' => $response['truncated'],
                'headers' => $response['headers'],
                'chain' => $page['chain'],
            ],
            'engines' => [],
            'meta' => null,
            'icons' => [],
            'images' => [],
            'manifest' => null,
            'preview' => null,
            'robots' => null,
        ];

        $this->httpChecks($url, $page);
        $isHtml = $response['status'] === 200 && ($response['type'] === '' || str_contains($response['type'], 'html'));
        $origin = $this->origin($final);
        $html = $isHtml ? $this->utf8($response['body'], $response['headers']['content-type'] ?? '') : '';
        $doc = $this->parse($html);

        // Everything the page links to, fetched together.
        $icons = $isHtml ? $this->iconLinks($doc, $final) : [];
        $icons[] = ['rel' => 'favicon.ico', 'url' => "{$origin}/favicon.ico", 'sizes' => null, 'type' => null];
        $metas = $this->metas($doc);
        $ogImage = $this->absolute($final, $this->first($metas, 'og:image') ?? $this->first($metas, 'og:image:url') ?? $this->first($metas, 'og:image:secure_url'));
        $twitterImage = $this->absolute($final, $this->first($metas, 'twitter:image') ?? $this->first($metas, 'twitter:image:src'));
        $manifestUrl = $this->absolute($final, $this->linkHref($doc, 'manifest'));
        $files = $this->fetchFollowing(array_values(array_filter([
            "{$origin}/robots.txt", $ogImage, $twitterImage, $manifestUrl, ...array_column($icons, 'url'),
        ])), self::FILE_BYTES);

        $robots = $this->robots("{$origin}/robots.txt", $files["{$origin}/robots.txt"]);
        $report['robots'] = $robots['report'];
        $report['engines'] = $this->engines($final, $response, $doc, $metas, $robots, $isHtml);

        if (! $isHtml) {
            return $this->finish($report);
        }

        $report['meta'] = $this->content($doc, $metas, $html, $final);
        $report['images'] = $this->social($metas, $final, $ogImage ? $files[$ogImage] : null, $twitterImage && $twitterImage !== $ogImage ? $files[$twitterImage] : null, $report['meta']);
        $report['icons'] = $this->favicons($icons, $files, $robots['rules'], $final);
        $report['manifest'] = $this->pwa($manifestUrl, $manifestUrl ? $files[$manifestUrl] : null, $metas, $html, $final);
        $report['preview'] = $this->preview($final, $report);
        // Share images are sent once, inside the preview.
        foreach ($report['images'] as &$image) {
            $image['preview'] = null;
        }
        unset($image);

        return $this->finish($report);
    }

    /** @param array<string, mixed> $report */
    private function finish(array $report): array
    {
        $report['sections'] = [];
        foreach (self::SECTIONS as $key => $label) {
            if ($this->checks[$key] !== []) {
                $report['sections'][] = ['key' => $key, 'label' => $label, 'checks' => $this->checks[$key]];
            }
        }
        $all = array_merge(...array_values($this->checks));
        $report['totals'] = [
            'pass' => count(array_filter($all, fn ($c) => $c['status'] === 'pass')),
            'warn' => count(array_filter($all, fn ($c) => $c['status'] === 'warn')),
            'fail' => count(array_filter($all, fn ($c) => $c['status'] === 'fail')),
        ];

        return $report;
    }

    /** @param array{url: string, response: array<string, mixed>, chain: array<int, array<string, mixed>>} $page */
    private function httpChecks(string $requested, array $page): void
    {
        $response = $page['response'];
        $hops = count($page['chain']) - 1;
        if ($response['status'] === 0) {
            $this->check('http', 'status', 'HTTP status', 'fail', $response['error'] ?? 'No response.');
        } elseif ($response['location'] !== null) {
            $this->check('http', 'status', 'HTTP status', 'fail', $response['error'] ?? "Still redirecting after {$hops} hops.");
        } elseif ($response['status'] === 200) {
            $this->check('http', 'status', 'HTTP status', 'pass', '200 OK.');
        } else {
            $this->check('http', 'status', 'HTTP status', 'fail', "HTTP {$response['status']}. Search engines only index pages that answer 200.");
        }

        if ($hops > 0) {
            $codes = implode(' → ', array_map(fn ($hop) => $hop['status'] ?: 'error', $page['chain']));
            $permanent = collect(array_slice($page['chain'], 0, -1))->every(fn ($hop) => in_array($hop['status'], [301, 308], true));
            $status = $hops > 1 ? 'warn' : ($permanent ? 'pass' : 'warn');
            $detail = "{$codes}, ending at {$page['url']}.";
            if ($hops > 1) {
                $detail .= ' Redirect straight to the final URL; every hop slows crawling.';
            } elseif (! $permanent) {
                $detail .= ' A temporary redirect keeps the old URL in search results; use 301 or 308 if the move is permanent.';
            }
            $this->check('http', 'redirects', 'Redirects', $status, $detail);
        } else {
            $this->check('http', 'redirects', 'Redirects', 'pass', 'No redirects.');
        }

        $https = str_starts_with($page['url'], 'https://');
        $this->check('http', 'https', 'HTTPS', $https ? 'pass' : 'fail', $https
            ? 'Served over HTTPS.'
            : 'Served over plain HTTP. Browsers mark it "Not secure" and search engines prefer HTTPS pages.');
        if ($response['status'] === 0) {
            return;
        }

        $time = $response['time_ms'] ?? null;
        if ($time !== null) {
            $this->check('http', 'speed', 'Response time', $time < 800 ? 'pass' : 'warn', "{$time} ms for the HTML".($time < 800 ? '.' : '; aim for under 800 ms (Time To First Byte affects Core Web Vitals).'));
        }
        if ($response['status'] === 200) {
            $type = $response['type'] ?: 'none';
            $this->check('http', 'type', 'Content type', str_contains($type, 'html') ? 'pass' : 'warn', str_contains($type, 'html')
                ? ($response['headers']['content-type'] ?? $type)
                : "Served as {$type}, not an HTML page, so there is no title, snippet or preview to check.");
            $encoding = $response['headers']['content-encoding'] ?? '';
            $this->check('http', 'compression', 'Compression', $encoding !== '' ? 'pass' : 'warn', $encoding !== ''
                ? "Compressed with {$encoding}."
                : 'Not compressed. Enable gzip or Brotli to send the page faster.');
            if ($response['truncated']) {
                $this->check('http', 'size', 'Page size', 'warn', 'The HTML is over 2 MB; only the first 2 MB were checked. Google stops reading HTML after 2 MB as well.');
            }
        }
        if ($https) {
            $hsts = $response['headers']['strict-transport-security'] ?? null;
            $this->check('http', 'hsts', 'HSTS', $hsts ? 'pass' : 'info', $hsts
                ? "Strict-Transport-Security: {$hsts}"
                : 'No Strict-Transport-Security header; browsers may still try HTTP first.');
        }
    }

    /**
     * @param  array{url: string, response: array<string, mixed>, chain: array<int, mixed>}  $file
     * @return array{rules: RobotsRules|null, blocked_all: bool, report: array<string, mixed>}
     */
    private function robots(string $url, array $file): array
    {
        $status = $file['response']['status'];
        $content = $status === 200 ? mb_scrub(substr($file['response']['body'], 0, 524288), 'UTF-8') : null;
        $report = ['url' => $url, 'status' => $status, 'content' => $content];
        if ($status === 200) {
            return ['rules' => RobotsRules::parse($content), 'blocked_all' => false, 'report' => $report];
        }
        // Missing (4xx) means everything is allowed; unreachable (5xx, timeout) makes Google stop crawling.
        $blocked = $status === 0 || $status >= 500;

        return ['rules' => null, 'blocked_all' => $blocked, 'report' => $report];
    }

    /**
     * Crawl and index verdicts per search engine.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, array<int, string>>  $metas
     * @param  array{rules: RobotsRules|null, blocked_all: bool, report: array<string, mixed>}  $robots
     * @return array<int, array<string, mixed>>
     */
    private function engines(string $final, array $response, ?\DOMXPath $doc, array $metas, array $robots, bool $isHtml): array
    {
        $path = (string) (parse_url($final, PHP_URL_PATH) ?: '/');
        if (($query = parse_url($final, PHP_URL_QUERY)) !== null) {
            $path .= '?'.$query;
        }
        $headerDirectives = $this->xRobots($response['headers']['x-robots-tag'] ?? '');
        $canonicals = $this->canonicals($doc, $response['headers']['link'] ?? '', $final);
        $canonical = $canonicals[0] ?? null;
        $canonicalElsewhere = $canonical !== null && $this->comparable($canonical) !== $this->comparable($final);

        $robotsStatus = $robots['report']['status'];
        if ($robots['rules'] !== null) {
            $this->check('indexing', 'robots', 'robots.txt', 'pass', "{$robots['report']['url']} is reachable.");
        } elseif ($robots['blocked_all']) {
            $this->check('indexing', 'robots', 'robots.txt', 'fail', 'robots.txt could not be read ('.($robotsStatus ?: 'no response').'). Google pauses crawling the whole site while robots.txt fails with a server error.');
        } else {
            $this->check('indexing', 'robots', 'robots.txt', 'info', "No robots.txt (HTTP {$robotsStatus}); crawlers may fetch every page.");
        }

        $engines = [];
        foreach (self::ENGINES as $key => $engine) {
            $robotsCheck = $robots['rules']?->check($engine['token'], $path) ?? ['allowed' => ! $robots['blocked_all'], 'rule' => null, 'agent' => null];
            $crawl = $robotsCheck['allowed']
                ? ['status' => 'pass', 'detail' => $robotsCheck['rule'] ? "Allowed by \"{$robotsCheck['rule']}\" for User-agent: {$robotsCheck['agent']}." : 'Allowed by robots.txt.']
                : ['status' => 'fail', 'detail' => $robotsCheck['rule']
                    ? "Blocked by \"{$robotsCheck['rule']}\" for User-agent: {$robotsCheck['agent']}."
                    : 'robots.txt cannot be read, so the site is treated as blocked.'];

            $noindex = null;
            foreach ($engine['names'] as $name) {
                foreach ($metas[$name] ?? [] as $content) {
                    if (preg_match('/\b(noindex|none)\b/i', $content)) {
                        $noindex = "<meta name=\"{$name}\" content=\"{$content}\">";
                    }
                }
            }
            foreach ($headerDirectives as $agent => $directives) {
                if (($agent === '*' || in_array($agent, $engine['names'], true) || str_starts_with($engine['token'], $agent)) && array_intersect($directives, ['noindex', 'none'])) {
                    $noindex = 'X-Robots-Tag: '.($response['headers']['x-robots-tag'] ?? 'noindex');
                }
            }

            if ($response['status'] === 0 || $response['location'] !== null || $response['status'] !== 200) {
                $index = ['status' => 'fail', 'detail' => $response['status'] ? "HTTP {$response['status']}; only 200 pages are indexed." : 'The page did not answer.'];
            } elseif (! $isHtml) {
                $index = ['status' => 'info', 'detail' => 'Not an HTML page.'];
            } elseif ($noindex !== null) {
                $index = ['status' => 'fail', 'detail' => "{$noindex} keeps it out of the index."];
            } elseif ($canonicalElsewhere) {
                $index = ['status' => 'warn', 'detail' => "Canonical points to {$canonical}; that URL is indexed instead of this one."];
            } else {
                $index = ['status' => 'pass', 'detail' => 'Indexable: 200 OK, no noindex'.($canonical ? ', self-canonical.' : '.')];
            }
            if ($crawl['status'] === 'fail' && $index['status'] === 'pass') {
                $index = ['status' => 'warn', 'detail' => 'Crawling is blocked, so the page content is never read. The URL can still be listed without a snippet if other pages link to it.'];
            }

            $verdict = $crawl['status'] === 'fail' || $index['status'] === 'fail' ? 'fail' : ($index['status'] === 'pass' ? 'pass' : 'warn');
            $engines[] = [
                'key' => $key,
                'label' => $engine['label'],
                'verdict' => $verdict,
                'summary' => match ($verdict) {
                    'pass' => "URL can appear on {$engine['label']}",
                    'warn' => "URL may not appear on {$engine['label']} as is",
                    default => "URL is not on {$engine['label']}",
                },
                'crawl' => $crawl,
                'index' => $index,
                'noindex' => $noindex !== null,
            ];
        }

        if (! $isHtml) {
            return $engines;
        }
        if ($canonical === null) {
            $this->check('indexing', 'canonical', 'Canonical URL', 'warn', 'No <link rel="canonical">. Add one so duplicates (tracking parameters, www/non-www) are folded into this URL.');
        } elseif ($canonicalElsewhere) {
            $this->check('indexing', 'canonical', 'Canonical URL', 'warn', "Points to {$canonical}. Search engines index that URL instead.");
        } else {
            $this->check('indexing', 'canonical', 'Canonical URL', 'pass', "Self-referencing: {$canonical}");
        }
        if (count(array_unique($canonicals)) > 1) {
            $this->check('indexing', 'canonical-multiple', 'Several canonicals', 'fail', 'The page declares '.count(array_unique($canonicals)).' different canonical URLs; search engines then ignore all of them.');
        }

        $robotsMeta = implode(', ', array_unique([...($metas['robots'] ?? []), ...($metas['googlebot'] ?? [])]));
        $header = $response['headers']['x-robots-tag'] ?? '';
        $blocked = array_column(array_filter($engines, fn ($e) => $e['noindex']), 'label');
        $this->check('indexing', 'meta-robots', 'Robots directives', $blocked ? 'fail' : 'pass', trim(
            ($blocked ? 'noindex for '.implode(', ', $blocked).'. ' : '')
            .($robotsMeta !== '' ? "meta robots: {$robotsMeta}. " : 'No meta robots tag (index, follow by default). ')
            .($header !== '' ? "X-Robots-Tag: {$header}." : '')
        ));
        if (preg_match('/\bnofollow\b/i', $robotsMeta.' '.$header)) {
            $this->check('indexing', 'nofollow', 'Link following', 'warn', 'nofollow is set for the whole page; crawlers will not follow its links.');
        }

        return $engines;
    }

    /**
     * X-Robots-Tag directives per user agent ("*" when none is named).
     *
     * @return array<string, array<int, string>>
     */
    private function xRobots(string $header): array
    {
        $directives = [];
        $agent = '*';
        foreach (explode(',', strtolower($header)) as $part) {
            $part = trim($part);
            if (preg_match('/^([a-z][a-z0-9_.-]*)\s*:\s*(.*)$/', $part, $m) && ! in_array($m[1], self::VALUE_DIRECTIVES, true)) {
                $agent = $m[1];
                $part = trim($m[2]);
            }
            if ($part !== '') {
                $directives[$agent][] = $part;
            }
        }

        return $directives;
    }

    /** @return array<int, string> */
    private function canonicals(?\DOMXPath $doc, string $linkHeader, string $final): array
    {
        $urls = [];
        if (preg_match_all('/<([^>]+)>\s*;[^,]*rel="?canonical"?/i', $linkHeader, $matches)) {
            array_push($urls, ...$matches[1]);
        }
        foreach ($doc?->query('//link[@href]') ?: [] as $link) {
            if (in_array('canonical', $this->rels($link), true)) {
                $urls[] = $link->getAttribute('href');
            }
        }

        return array_values(array_filter(array_map(fn ($url) => $this->absolute($final, $url), $urls)));
    }

    /**
     * Title, description, headings, language, structured data and other on-page signals.
     *
     * @param  array<string, array<int, string>>  $metas
     * @return array<string, mixed>
     */
    private function content(\DOMXPath $doc, array $metas, string $html, string $final): array
    {
        $titles = array_map(fn ($node) => $this->text($node->textContent), iterator_to_array($doc->query('//head/title') ?: []));
        if ($titles === []) {
            $titles = array_map(fn ($node) => $this->text($node->textContent), iterator_to_array($doc->query('//title[not(ancestor::svg)]') ?: []));
        }
        $title = $titles[0] ?? '';
        $length = mb_strlen($title);
        if ($title === '') {
            $this->check('content', 'title', 'Title', 'fail', 'No <title>. Search engines then make one up from the page.');
        } elseif ($length < 15) {
            $this->check('content', 'title', 'Title', 'warn', "{$length} characters: \"{$title}\". Too short to describe the page; aim for 30–60.");
        } elseif ($length > 60) {
            $this->check('content', 'title', 'Title', 'warn', "{$length} characters. Google cuts titles at about 600 px (~60 characters), so the end will show as \"…\".");
        } else {
            $this->check('content', 'title', 'Title', 'pass', "{$length} characters: \"{$title}\"");
        }
        if (count($titles) > 1) {
            $this->check('content', 'title-multiple', 'Several titles', 'warn', count($titles).' <title> elements; only the first is used.');
        }

        $description = $this->text($this->first($metas, 'description') ?? '');
        $length = mb_strlen($description);
        if ($description === '') {
            $this->check('content', 'description', 'Meta description', 'warn', 'No meta description. Search engines pick a snippet from the page text, which you do not control.');
        } elseif ($length < 70) {
            $this->check('content', 'description', 'Meta description', 'warn', "{$length} characters. Short; aim for 120–160 so the snippet fills two lines.");
        } elseif ($length > 160) {
            $this->check('content', 'description', 'Meta description', 'warn', "{$length} characters. Google shows about 155–160 on desktop and less on mobile; the rest is cut.");
        } else {
            $this->check('content', 'description', 'Meta description', 'pass', "{$length} characters.");
        }

        $h1 = array_values(array_filter(array_map(fn ($node) => $this->text($node->textContent), iterator_to_array($doc->query('//h1') ?: []))));
        $this->check('content', 'h1', 'H1 heading', count($h1) === 1 ? 'pass' : 'warn', match (true) {
            $h1 === [] => 'No <h1>. Give the page one main heading.',
            count($h1) > 1 => count($h1).' <h1> headings. One main heading is clearer.',
            default => "\"{$h1[0]}\"",
        });
        $headings = [];
        foreach ($doc->query('//h1|//h2|//h3') ?: [] as $node) {
            if (count($headings) < 50 && ($text = $this->text($node->textContent)) !== '') {
                $headings[] = ['level' => (int) substr($node->nodeName, 1), 'text' => mb_substr($text, 0, 200)];
            }
        }

        $lang = trim((string) ($doc->query('//html/@lang')->item(0)?->nodeValue ?? ''));
        $this->check('content', 'lang', 'Language', $lang !== '' ? 'pass' : 'warn', $lang !== '' ? "<html lang=\"{$lang}\">" : 'No lang attribute on <html>. It helps search engines and screen readers pick the language.');

        $viewport = $this->first($metas, 'viewport');
        $this->check('content', 'viewport', 'Mobile viewport', $viewport && str_contains($viewport, 'width=device-width') ? 'pass' : 'fail', $viewport
            ? "<meta name=\"viewport\" content=\"{$viewport}\">"
            : 'No <meta name="viewport">. Google indexes the mobile version; without it the page renders as a zoomed-out desktop page.');

        $images = $doc->query('//img');
        $missingAlt = $doc->query('//img[not(@alt)]')->length;
        if ($images->length > 0) {
            $this->check('content', 'img-alt', 'Image alt text', $missingAlt ? 'warn' : 'pass', $missingAlt
                ? "{$missingAlt} of {$images->length} images have no alt attribute. Image search and screen readers rely on it."
                : "All {$images->length} images have an alt attribute.");
        }

        // Visible words, without scripts and styles.
        $body = $doc->query('//body')->item(0);
        $words = 0;
        $snippet = '';
        if ($body !== null) {
            // A detached copy, so the structured data scripts stay in the page.
            $clone = $body->cloneNode(true);
            foreach (iterator_to_array($doc->query('.//script|.//style|.//noscript|.//template|.//svg', $clone) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
            $words = count(preg_split('/\s+/u', $this->text($clone->textContent), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            foreach ($doc->query('.//p', $clone) ?: [] as $p) {
                if (mb_strlen($text = $this->text($p->textContent)) >= 60) {
                    $snippet = $text;
                    break;
                }
            }
        }
        $this->check('content', 'words', 'Text content', $words >= 200 ? 'pass' : 'warn', "About {$words} words of visible text".($words >= 200 ? '.' : '. Thin pages rank poorly; pages built by JavaScript may show little here.'));

        $host = strtolower((string) parse_url($final, PHP_URL_HOST));
        $links = ['internal' => 0, 'external' => 0, 'nofollow' => 0];
        foreach ($doc->query('//a[@href]') ?: [] as $a) {
            $href = $this->absolute($final, $a->getAttribute('href'));
            if ($href === null) {
                continue;
            }
            $linkHost = strtolower((string) parse_url($href, PHP_URL_HOST));
            $links[preg_replace('/^www\./', '', $linkHost) === preg_replace('/^www\./', '', $host) ? 'internal' : 'external']++;
            if (preg_match('/\b(nofollow|ugc|sponsored)\b/i', $a->getAttribute('rel'))) {
                $links['nofollow']++;
            }
        }
        $this->check('content', 'links', 'Links', $links['internal'] ? 'pass' : 'warn', "{$links['internal']} internal, {$links['external']} external".($links['nofollow'] ? ", {$links['nofollow']} nofollow/ugc/sponsored" : '').'.'.($links['internal'] ? '' : ' Internal links help crawlers find the rest of the site.'));

        $hreflang = [];
        foreach ($doc->query('//link[@hreflang][@href]') ?: [] as $link) {
            if (in_array('alternate', $this->rels($link), true)) {
                $hreflang[] = ['lang' => $link->getAttribute('hreflang'), 'url' => $this->absolute($final, $link->getAttribute('href'))];
            }
        }
        if ($hreflang !== []) {
            $self = collect($hreflang)->contains(fn ($h) => $h['url'] && $this->comparable($h['url']) === $this->comparable($final));
            $this->check('content', 'hreflang', 'hreflang', $self ? 'pass' : 'warn', count($hreflang).' language versions ('.implode(', ', array_slice(array_column($hreflang, 'lang'), 0, 12)).').'
                .($self ? '' : ' The list does not include this URL itself; each version must list itself too.'));
        }

        [$types, $errors] = $this->structuredData($doc);
        if ($errors) {
            $this->check('content', 'schema', 'Structured data', 'fail', "{$errors} JSON-LD ".($errors === 1 ? 'block is' : 'blocks are').' not valid JSON and are ignored.'.($types ? ' Valid types: '.implode(', ', $types).'.' : ''));
        } elseif ($types) {
            $this->check('content', 'schema', 'Structured data', 'pass', 'Found: '.implode(', ', $types).'. Test eligibility for rich results with Google\'s Rich Results Test.');
        } else {
            $this->check('content', 'schema', 'Structured data', 'info', 'No JSON-LD or microdata. Schema.org markup (Organization, Article, Product, BreadcrumbList…) can earn rich results.');
        }

        return [
            'title' => $title,
            'description' => $description,
            'snippet' => mb_substr($snippet, 0, 300),
            'lang' => $lang,
            'canonical' => $this->canonicals($doc, '', $final)[0] ?? null,
            'robots' => $this->first($metas, 'robots'),
            'h1' => $h1,
            'headings' => $headings,
            'hreflang' => $hreflang,
            'schema_types' => $types,
            'words' => $words,
            'links' => $links,
            'og' => $this->prefixed($metas, 'og:'),
            'twitter' => $this->prefixed($metas, 'twitter:'),
        ];
    }

    /** @return array{0: array<int, string>, 1: int} schema.org types found, invalid JSON-LD blocks */
    private function structuredData(\DOMXPath $doc): array
    {
        $types = [];
        $errors = 0;
        $collect = function ($node) use (&$collect, &$types): void {
            if (! is_array($node)) {
                return;
            }
            foreach ((array) ($node['@type'] ?? []) as $type) {
                if (is_string($type)) {
                    $types[] = $type;
                }
            }
            foreach ($node['@graph'] ?? (array_is_list($node) ? $node : []) as $child) {
                $collect($child);
            }
        };
        foreach ($doc->query('//script') ?: [] as $script) {
            if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $data = json_decode(trim($script->textContent), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors++;

                continue;
            }
            $collect($data);
        }
        foreach ($doc->query('//*[@itemtype]') ?: [] as $node) {
            $types[] = preg_replace('#^https?://schema\.org/#i', '', $node->getAttribute('itemtype'));
        }

        return [array_values(array_unique($types)), $errors];
    }

    /**
     * Open Graph and X card tags, and their share image.
     *
     * @param  array<string, array<int, string>>  $metas
     * @param  array<string, mixed>|null  $og
     * @param  array<string, mixed>|null  $twitter  only when it differs from og:image
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function social(array $metas, string $final, ?array $og, ?array $twitter, array $meta): array
    {
        $missing = array_filter(['og:title', 'og:description', 'og:url', 'og:type'], fn ($tag) => $this->first($metas, $tag) === null);
        $this->check('social', 'og-tags', 'Open Graph tags', $missing ? (count($missing) > 2 ? 'fail' : 'warn') : 'pass', $missing
            ? 'Missing '.implode(', ', $missing).'. Facebook, LinkedIn, WhatsApp and others then guess from the page.'
            : 'og:title, og:description, og:url and og:type are set.');
        $ogUrl = $this->absolute($final, $this->first($metas, 'og:url'));
        if ($ogUrl !== null && $meta['canonical'] !== null && $this->comparable($ogUrl) !== $this->comparable($meta['canonical'])) {
            $this->check('social', 'og-url', 'og:url', 'warn', "og:url ({$ogUrl}) differs from the canonical URL; shares are then counted on a different URL.");
        }

        $images = [];
        if ($og === null) {
            $this->check('social', 'og-image', 'Share image (og:image)', 'fail', 'No og:image. Links shared on social media and chat apps show without a picture.');
        } else {
            $images['og'] = $this->image($og);
            $this->judgeShareImage('og-image', 'Share image (og:image)', $images['og']);
            if ($this->first($metas, 'og:image:alt') === null) {
                $this->check('social', 'og-image-alt', 'og:image:alt', 'info', 'Add og:image:alt to describe the image for screen readers.');
            }
        }

        $card = $this->first($metas, 'twitter:card');
        if ($card === null) {
            $this->check('social', 'twitter-card', 'X (Twitter) card', 'warn', 'No twitter:card. X shows a small "summary" card; set summary_large_image for a full-width image.');
        } elseif (! in_array($card, ['summary', 'summary_large_image', 'app', 'player'], true)) {
            $this->check('social', 'twitter-card', 'X (Twitter) card', 'fail', "twitter:card \"{$card}\" is not a valid card type.");
        } else {
            $this->check('social', 'twitter-card', 'X (Twitter) card', 'pass', "twitter:card is {$card}.");
        }
        if ($twitter !== null) {
            $images['twitter'] = $this->image($twitter);
            $this->judgeShareImage('twitter-image', 'X image (twitter:image)', $images['twitter']);
        }

        return $images;
    }

    /** @param array<string, mixed> $image */
    private function judgeShareImage(string $key, string $label, array $image): void
    {
        if ($image['error'] !== null) {
            $this->check('social', $key, $label, 'fail', "{$image['url']}: {$image['error']}");

            return;
        }
        [$w, $h] = [$image['width'], $image['height']];
        $size = $this->size($image['bytes']);
        $problems = [];
        $status = 'pass';
        if ($image['truncated'] || $image['bytes'] > 8388608) {
            [$status, $problems[]] = ['fail', 'over 8 MB, the Facebook limit'];
        } elseif ($image['bytes'] > 5242880) {
            [$status, $problems[]] = ['warn', 'over 5 MB, the X limit'];
        }
        if ($w && $h) {
            if ($w < 200 || $h < 200) {
                [$status, $problems[]] = ['fail', 'smaller than 200×200, so Facebook ignores it'];
            } elseif ($w < 1200 || $h < 630) {
                $status = $status === 'fail' ? 'fail' : 'warn';
                $problems[] = 'below the recommended 1200×630; large cards look blurry';
            }
            if (abs($w / $h - 1.91) > 0.15 && $w >= 200) {
                $problems[] = 'not 1.91:1, so large cards crop it';
                $status = $status === 'pass' ? 'info' : $status;
            }
        } elseif (! $image['vector']) {
            $status = 'warn';
            $problems[] = 'its dimensions could not be read';
        }
        if ($image['vector']) {
            [$status, $problems[]] = ['fail', 'SVG is not supported for share images; use JPG or PNG'];
        }
        $dims = $w && $h ? "{$w}×{$h}, " : '';
        $this->check('social', $key, $label, $status, "{$dims}{$size}, {$image['mime']}".($problems ? ': '.implode('; ', $problems).'.' : '.'));
    }

    /**
     * Every declared icon, /favicon.ico, and what Google, Bing and Yandex would show.
     *
     * @param  array<int, array{rel: string, url: string, sizes: string|null, type: string|null}>  $icons
     * @param  array<string, array{url: string, response: array<string, mixed>, chain: array<int, mixed>}>  $files
     * @return array<int, array<string, mixed>>
     */
    private function favicons(array $icons, array $files, ?RobotsRules $robots, string $final): array
    {
        $result = [];
        foreach ($icons as $icon) {
            $result[] = ['rel' => $icon['rel'], 'declared_sizes' => $icon['sizes']] + $this->image($files[$icon['url']]);
        }
        $declared = array_values(array_filter($result, fn ($i) => in_array($i['rel'], ['icon', 'shortcut icon'], true)));
        $working = fn (array $list) => array_values(array_filter($list, fn ($i) => $i['error'] === null));

        if ($declared === []) {
            $this->check('favicon', 'declared', 'Favicon link', 'warn', 'No <link rel="icon">. Browsers and search engines fall back to /favicon.ico.');
        } else {
            $broken = count($declared) - count($working($declared));
            $this->check('favicon', 'declared', 'Favicon link', $broken ? 'fail' : 'pass', count($declared).' declared'.($broken ? ", {$broken} broken: ".implode(', ', array_map(fn ($i) => "{$i['url']} ({$i['error']})", array_filter($declared, fn ($i) => $i['error'] !== null))) : '.'));
        }

        // Google: rel icon / apple-touch-icon, square, at least 8×8 and ideally over 48×48, crawlable.
        $candidates = $working(array_filter($result, fn ($i) => $i['rel'] !== 'mask-icon'));
        usort($candidates, fn ($a, $b) => $this->googleScore($b) <=> $this->googleScore($a));
        $google = $candidates[0] ?? null;
        if ($google === null) {
            $this->check('favicon', 'google', 'Google search favicon', 'fail', 'No working favicon; Google shows a generic globe icon next to the site.');
        } else {
            $square = $google['vector'] || ($google['width'] && $google['width'] === $google['height']);
            $big = $google['vector'] || $google['width'] >= 48;
            $dims = $google['vector'] ? 'SVG' : "{$google['width']}×{$google['height']}";
            $status = ! $square ? 'fail' : ($big ? 'pass' : 'warn');
            $detail = "Uses {$google['url']} ({$dims}). ".match ($status) {
                'fail' => 'It must be square (1:1).',
                'warn' => 'Under 48×48; Google recommends a larger square icon (a multiple of 48 px, e.g. 48, 96 or 192).',
                default => 'Square and large enough.',
            };
            if ($robots !== null && ! $robots->check('googlebot-image', (string) parse_url($google['url'], PHP_URL_PATH))['allowed'] && $this->sameHost($google['url'], $final)) {
                $status = 'fail';
                $detail .= ' robots.txt blocks it for Googlebot-Image.';
            }
            if (trim((string) parse_url($final, PHP_URL_PATH), '/') !== '') {
                $detail .= ' Google takes the favicon from the home page.';
            }
            $this->check('favicon', 'google', 'Google search favicon', $status, $detail);
        }

        $ico = collect($result)->firstWhere('rel', 'favicon.ico');
        $this->check('favicon', 'ico', '/favicon.ico', $ico['error'] === null ? 'pass' : 'warn', $ico['error'] === null
            ? ($ico['sizes'] ? 'Contains '.implode(', ', array_map(fn ($s) => "{$s}×{$s}", $ico['sizes'])).'.' : "{$ico['mime']}, {$ico['width']}×{$ico['height']}.").' Bing, Yandex and older browsers read this file.'
            : "Not available ({$ico['error']}). Bing, Yandex and RSS readers request /favicon.ico directly.");
        if ($ico['error'] === null && $ico['sizes'] && ! array_intersect($ico['sizes'], [16, 32])) {
            $this->check('favicon', 'ico-sizes', 'favicon.ico sizes', 'warn', 'Yandex and browser tabs expect a 16×16 or 32×32 image inside favicon.ico.');
        }

        $apple = $working(array_filter($result, fn ($i) => str_starts_with($i['rel'], 'apple-touch-icon')));
        $this->check('favicon', 'apple', 'Apple touch icon', $apple ? 'pass' : 'info', $apple
            ? 'Present ('.implode(', ', array_map(fn ($i) => $i['width'] ? "{$i['width']}×{$i['height']}" : 'SVG', $apple)).'). 180×180 is the recommended size.'
            : 'No <link rel="apple-touch-icon">. iOS uses it for home screen bookmarks; 180×180 PNG recommended.');

        foreach ($result as &$icon) {
            $icon['google'] = $google !== null && $icon['url'] === $google['url'];
        }

        return $result;
    }

    /** @param array<string, mixed> $icon */
    private function googleScore(array $icon): int
    {
        $square = $icon['vector'] || ($icon['width'] && $icon['width'] === $icon['height']);
        $size = $icon['vector'] ? 512 : (int) $icon['width'];
        $declared = in_array($icon['rel'], ['icon', 'shortcut icon'], true) ? 2000 : ($icon['rel'] === 'favicon.ico' ? 0 : 1000);

        return ($square ? 10000 : 0) + $declared + min($size, 512) + ($size >= 48 ? 500 : 0);
    }

    /**
     * The web app manifest and installability signals.
     *
     * @param  array{url: string, response: array<string, mixed>, chain: array<int, mixed>}|null  $file
     * @param  array<string, array<int, string>>  $metas
     * @return array<string, mixed>|null
     */
    private function pwa(?string $manifestUrl, ?array $file, array $metas, string $html, string $final): ?array
    {
        $theme = $this->first($metas, 'theme-color');
        $this->check('pwa', 'theme-color', 'Theme color', $theme ? 'pass' : 'info', $theme
            ? "<meta name=\"theme-color\" content=\"{$theme}\">"
            : 'No <meta name="theme-color">; mobile browsers use their default toolbar color.');

        if ($manifestUrl === null || $file === null) {
            $this->check('pwa', 'manifest', 'Web app manifest', 'info', 'No <link rel="manifest">. Only needed if the site should be installable as an app.');

            return null;
        }
        $response = $file['response'];
        if ($response['status'] !== 200) {
            $hint = $response['status'] === 403 && str_ends_with(strtolower((string) parse_url($manifestUrl, PHP_URL_PATH)), '.json')
                ? ' The server refuses it; many servers block .json files. Allow this file, or rename it to manifest.webmanifest.'
                : '';
            $this->check('pwa', 'manifest', 'Web app manifest', 'fail', "{$manifestUrl}: ".($response['status'] ? "HTTP {$response['status']}." : ($response['error'] ?? 'No response.')).$hint);

            return ['url' => $manifestUrl, 'status' => $response['status'], 'data' => null, 'icons' => []];
        }
        $data = json_decode($response['body'], true);
        if (! is_array($data)) {
            $this->check('pwa', 'manifest', 'Web app manifest', 'fail', "{$manifestUrl} is not valid JSON.");

            return ['url' => $manifestUrl, 'status' => 200, 'data' => null, 'icons' => []];
        }
        $this->check('pwa', 'manifest', 'Web app manifest', 'pass', "{$manifestUrl} is valid JSON".(str_contains($response['type'], 'json') ? '.' : " (served as {$response['type']}; application/manifest+json is expected)."));

        $name = is_string($data['name'] ?? null) ? $data['name'] : null;
        $short = is_string($data['short_name'] ?? null) ? $data['short_name'] : null;
        $this->check('pwa', 'name', 'App name', $name || $short ? 'pass' : 'fail', $name || $short
            ? trim(($name ? "name: \"{$name}\"" : '').($short ? " short_name: \"{$short}\"" : '')).($short && mb_strlen($short) > 12 ? '. short_name over 12 characters may be cut under the home screen icon.' : '')
            : 'Set name or short_name.');

        $display = $data['display'] ?? 'browser';
        $standalone = in_array($display, ['standalone', 'fullscreen', 'minimal-ui'], true)
            || array_intersect((array) ($data['display_override'] ?? []), ['standalone', 'fullscreen', 'minimal-ui', 'window-controls-overlay']);
        $this->check('pwa', 'display', 'Display mode', $standalone ? 'pass' : 'warn', $standalone
            ? "display: {$display}."
            : "display: {$display}. Use standalone (or fullscreen / minimal-ui) so the app opens in its own window.");

        $start = $this->absolute($manifestUrl, is_string($data['start_url'] ?? null) ? $data['start_url'] : null);
        $this->check('pwa', 'start-url', 'start_url', $start ? ($this->sameHost($start, $final) ? 'pass' : 'fail') : 'warn', $start
            ? ($this->sameHost($start, $final) ? $start : "{$start} is on another origin; it must be on the site's own origin.")
            : 'No start_url; browsers open the page the app was installed from.');

        // Icons: 192 and 512 are required by Chrome; maskable avoids white borders on Android.
        $icons = [];
        foreach (is_array($data['icons'] ?? null) ? $data['icons'] : [] as $icon) {
            if (is_array($icon) && is_string($icon['src'] ?? null) && ($src = $this->absolute($manifestUrl, $icon['src']))) {
                $icons[] = ['url' => $src, 'sizes' => (string) ($icon['sizes'] ?? ''), 'type' => $icon['type'] ?? null, 'purpose' => (string) ($icon['purpose'] ?? 'any')];
            }
        }
        $has = fn (int $px) => collect($icons)->contains(fn ($i) => preg_match("/\\b{$px}x{$px}\\b/i", $i['sizes']) || str_contains($i['sizes'], 'any'));
        $missing = array_filter([192, 512], fn ($px) => ! $has($px));
        $this->check('pwa', 'icons', 'App icons', $icons === [] ? 'fail' : ($missing ? 'warn' : 'pass'), $icons === []
            ? 'The manifest lists no icons; the app cannot be installed.'
            : count($icons).' icons. '.($missing ? 'Missing '.implode(' and ', array_map(fn ($px) => "{$px}×{$px}", $missing)).', which Chrome needs for install and splash screens.' : '192×192 and 512×512 present.'));
        $maskable = collect($icons)->contains(fn ($i) => str_contains($i['purpose'], 'maskable'));
        if ($icons !== []) {
            $this->check('pwa', 'maskable', 'Maskable icon', $maskable ? 'pass' : 'info', $maskable ? 'A maskable icon is provided.' : 'No icon with purpose "maskable"; Android shows the icon inside a white circle.');
        }

        // Check and show the two icons the install prompt and splash screen use.
        $pick = [];
        foreach ([512, 192] as $px) {
            $match = collect($icons)->first(fn ($i) => preg_match("/\\b{$px}x{$px}\\b/i", $i['sizes']) && ! str_contains($i['purpose'], 'monochrome'));
            if ($match && ! in_array($match['url'], array_column($pick, 'url'), true)) {
                $pick[] = $match;
            }
        }
        if ($pick === [] && $icons !== []) {
            $pick[] = $icons[0];
        }
        $fetched = $pick ? $this->fetchFollowing(array_column($pick, 'url'), self::FILE_BYTES) : [];
        $checked = [];
        foreach ($pick as $icon) {
            $image = $this->image($fetched[$icon['url']]);
            $checked[] = $icon + $image;
            if ($image['error'] !== null) {
                $this->check('pwa', 'icon-'.$icon['sizes'], "Icon {$icon['sizes']}", 'fail', "{$icon['url']}: {$image['error']}");
            } elseif (! $image['vector'] && $icon['sizes'] !== '' && ! str_contains($icon['sizes'], "{$image['width']}x{$image['height']}")) {
                $this->check('pwa', 'icon-'.$icon['sizes'], "Icon {$icon['sizes']}", 'warn', "Declared {$icon['sizes']} but the file is {$image['width']}×{$image['height']}.");
            }
        }

        $colors = array_filter(['theme_color' => $data['theme_color'] ?? null, 'background_color' => $data['background_color'] ?? null]);
        $this->check('pwa', 'colors', 'Manifest colors', count($colors) === 2 ? 'pass' : 'info', count($colors) === 2
            ? "theme_color {$colors['theme_color']}, background_color {$colors['background_color']}."
            : 'Set theme_color and background_color for the splash screen and title bar.');

        $https = str_starts_with($final, 'https://');
        $worker = preg_match('/serviceWorker\s*\.\s*register\s*\(/', $html) === 1;
        $this->check('pwa', 'service-worker', 'Service worker', $worker ? 'pass' : 'info', $worker
            ? 'navigator.serviceWorker.register() found in the page.'
            : 'No service worker registration in the page HTML (it may be in a script file). Needed for offline support.');
        if (! $https) {
            $this->check('pwa', 'pwa-https', 'Secure origin', 'fail', 'Web apps can only be installed from HTTPS pages.');
        }

        return [
            'url' => $manifestUrl,
            'status' => 200,
            'data' => array_intersect_key($data, array_flip(['name', 'short_name', 'description', 'start_url', 'scope', 'id', 'display', 'display_override', 'orientation', 'theme_color', 'background_color', 'lang', 'categories'])),
            'icons' => $checked,
            'icon_count' => count($icons),
        ];
    }

    /**
     * What the search result and share cards would show.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function preview(string $final, array $report): array
    {
        $meta = $report['meta'];
        $og = $meta['og'];
        $twitter = $meta['twitter'];
        $host = (string) parse_url($final, PHP_URL_HOST);
        $segments = array_values(array_filter(explode('/', (string) parse_url($final, PHP_URL_PATH)), 'strlen'));
        $google = collect($report['icons'])->firstWhere('google', true);
        $ico = collect($report['icons'])->first(fn ($i) => $i['rel'] === 'favicon.ico' && $i['error'] === null);
        $ogImage = $report['images']['og'] ?? null;
        $twitterImage = $report['images']['twitter'] ?? null;
        $title = $meta['title'] ?: ($og['og:title'] ?? $host);
        $description = $meta['description'] ?: $meta['snippet'];
        $appIcon = collect($report['manifest']['icons'] ?? [])->first(fn ($i) => $i['error'] === null);

        return [
            'url' => $final,
            'host' => $host,
            'breadcrumb' => implode(' › ', array_map(fn ($s) => mb_substr(rawurldecode($s), 0, 40), array_slice($segments, 0, 3))),
            'site_name' => $og['og:site_name'] ?? $report['manifest']['data']['name'] ?? preg_replace('/^www\./', '', $host),
            'title' => $title,
            'description' => $description,
            'description_generated' => $meta['description'] === '',
            'favicon' => $google['preview'] ?? null,
            'favicon_ico' => $ico['preview'] ?? ($google['preview'] ?? null),
            'social' => [
                'title' => $og['og:title'] ?? $title,
                'description' => $og['og:description'] ?? $description,
                'image' => $ogImage['preview'] ?? null,
                'image_url' => $ogImage['url'] ?? null,
                'image_ratio' => $ogImage && $ogImage['width'] && $ogImage['height'] ? $ogImage['width'] / $ogImage['height'] : null,
                'twitter_card' => $twitter['twitter:card'] ?? 'summary',
                'twitter_title' => $twitter['twitter:title'] ?? $og['og:title'] ?? $title,
                'twitter_description' => $twitter['twitter:description'] ?? $og['og:description'] ?? $description,
                // Null when X uses og:image.
                'twitter_image' => $twitterImage['preview'] ?? null,
            ],
            'theme_color' => $report['manifest']['data']['theme_color'] ?? null,
            'app' => ($report['manifest']['data'] ?? null) ? [
                'name' => $report['manifest']['data']['name'] ?? $report['manifest']['data']['short_name'] ?? $host,
                'short_name' => $report['manifest']['data']['short_name'] ?? $report['manifest']['data']['name'] ?? $host,
                'background_color' => $report['manifest']['data']['background_color'] ?? '#ffffff',
                'theme_color' => $report['manifest']['data']['theme_color'] ?? null,
                'icon' => $appIcon['preview'] ?? null,
            ] : null,
        ];
    }

    /**
     * An image file's status, type, dimensions and an inline copy for previews.
     *
     * @param  array{url: string, response: array<string, mixed>, chain: array<int, mixed>}  $file
     * @return array{url: string, error: string|null, mime: string, width: int|null, height: int|null, sizes: array<int, int>, vector: bool, bytes: int, truncated: bool, redirects: int, preview: string|null}
     */
    private function image(array $file): array
    {
        $response = $file['response'];
        $body = $response['body'];
        $result = ['url' => $file['url'], 'error' => null, 'mime' => $response['type'], 'width' => null, 'height' => null, 'sizes' => [], 'vector' => false, 'bytes' => strlen($body), 'truncated' => $response['truncated'], 'redirects' => count($file['chain']) - 1, 'preview' => null];
        if ($response['status'] !== 200 || $response['location'] !== null) {
            $result['error'] = $response['status'] ? "HTTP {$response['status']}" : ($response['error'] ?? 'No response');

            return $result;
        }
        if ($response['truncated']) {
            return $result;
        }

        $head = ltrim(substr($body, 0, 1024));
        if (str_contains($response['type'], 'svg') || preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg\b/is', $head)) {
            $result['vector'] = true;
            $result['mime'] = 'image/svg+xml';
            if (preg_match('/<svg\b[^>]*>/is', $body, $tag)) {
                if (preg_match('/\bviewBox=["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $tag[0], $box)) {
                    [$result['width'], $result['height']] = [(int) round((float) $box[1]), (int) round((float) $box[2])];
                } elseif (preg_match('/\bwidth=["\']([\d.]+)/i', $tag[0], $w) && preg_match('/\bheight=["\']([\d.]+)/i', $tag[0], $h)) {
                    [$result['width'], $result['height']] = [(int) $w[1], (int) $h[1]];
                }
            }
        } elseif (substr($body, 0, 4) === "\0\0\1\0") {
            // ICO: a directory of images; width/height 0 means 256.
            $count = unpack('v', substr($body, 4, 2))[1] ?? 0;
            for ($i = 0; $i < min($count, 32) && strlen($body) >= 6 + ($i + 1) * 16; $i++) {
                $size = ord($body[6 + $i * 16]) ?: 256;
                $result['sizes'][] = $size;
            }
            $result['sizes'] = array_values(array_unique($result['sizes']));
            sort($result['sizes']);
            $result['mime'] = 'image/x-icon';
            $result['width'] = $result['height'] = $result['sizes'] ? max($result['sizes']) : null;
        } elseif ($info = @getimagesizefromstring($body)) {
            [$result['width'], $result['height']] = [$info[0], $info[1]];
            $result['mime'] = $info['mime'];
        } else {
            $result['error'] = 'Not an image (served as '.($response['type'] ?: 'no content type').')';

            return $result;
        }

        if ($result['bytes'] <= self::PREVIEW_BYTES) {
            $result['preview'] = 'data:'.$result['mime'].';base64,'.base64_encode($body);
        }

        return $result;
    }

    /**
     * Fetches URLs and follows their redirects (up to MAX_HOPS), all in parallel.
     *
     * @param  array<int, string>  $urls
     * @return array<string, array{url: string, response: array<string, mixed>, chain: array<int, array{url: string, status: int, location: string|null, time_ms: int|null}>}>
     */
    private function fetchFollowing(array $urls, int $maxBytes): array
    {
        $state = [];
        foreach (array_unique($urls) as $url) {
            $state[$url] = ['url' => $url, 'response' => null, 'chain' => []];
        }
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $pending = array_filter($state, fn ($s) => $s['response'] === null);
            if ($pending === []) {
                break;
            }
            $responses = $this->client->fetchMany(array_values(array_unique(array_column($pending, 'url'))), $maxBytes);
            foreach ($pending as $original => $item) {
                $response = $responses[$item['url']];
                $state[$original]['chain'][] = ['url' => $item['url'], 'status' => $response['status'], 'location' => $response['location'], 'time_ms' => $response['time_ms'] ?? null];
                $next = $response['location'] !== null ? $this->absolute($item['url'], $response['location']) : null;
                if ($next === null || $hop === self::MAX_HOPS) {
                    $state[$original]['response'] = $response;
                } elseif (in_array($next, array_column($state[$original]['chain'], 'url'), true)) {
                    $state[$original]['response'] = ['error' => "Redirect loop back to {$next}."] + $response;
                } else {
                    $state[$original]['url'] = $next;
                }
            }
        }

        return $state;
    }

    private function parse(string $html): ?\DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /** Converts a page declared in another charset to UTF-8. */
    private function utf8(string $html, string $contentType): string
    {
        $charset = preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m) ? $m[1]
            : (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m) ? $m[1] : 'UTF-8');
        if (strcasecmp($charset, 'utf-8') !== 0 && in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            $html = mb_convert_encoding($html, 'UTF-8', $charset);
        }

        return mb_scrub($html, 'UTF-8');
    }

    /**
     * Every <meta> by lowercased name or property.
     *
     * @return array<string, array<int, string>>
     */
    private function metas(?\DOMXPath $doc): array
    {
        $metas = [];
        foreach ($doc?->query('//meta') ?: [] as $meta) {
            $name = strtolower(trim($meta->getAttribute('name') ?: $meta->getAttribute('property')));
            if ($name !== '' && $meta->hasAttribute('content')) {
                $metas[$name][] = trim($meta->getAttribute('content'));
            }
        }

        return $metas;
    }

    /** @param array<string, array<int, string>> $metas */
    private function first(array $metas, string $name): ?string
    {
        $value = $metas[$name][0] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @param  array<string, array<int, string>>  $metas
     * @return array<string, string>
     */
    private function prefixed(array $metas, string $prefix): array
    {
        $found = [];
        foreach ($metas as $name => $values) {
            if (str_starts_with($name, $prefix) && $values[0] !== '') {
                $found[$name] = $values[0];
            }
        }

        return $found;
    }

    /** @return array<int, array{rel: string, url: string, sizes: string|null, type: string|null}> */
    private function iconLinks(?\DOMXPath $doc, string $final): array
    {
        $icons = [];
        foreach ($doc?->query('//link[@href]') ?: [] as $link) {
            $rels = $this->rels($link);
            $rel = match (true) {
                in_array('apple-touch-icon', $rels, true) => 'apple-touch-icon',
                in_array('apple-touch-icon-precomposed', $rels, true) => 'apple-touch-icon-precomposed',
                in_array('mask-icon', $rels, true) => 'mask-icon',
                in_array('icon', $rels, true) => in_array('shortcut', $rels, true) ? 'shortcut icon' : 'icon',
                default => null,
            };
            $url = $rel ? $this->absolute($final, $link->getAttribute('href')) : null;
            if ($url !== null && ! in_array($url, array_column($icons, 'url'), true) && count($icons) < 12) {
                $icons[] = ['rel' => $rel, 'url' => $url, 'sizes' => $link->getAttribute('sizes') ?: null, 'type' => $link->getAttribute('type') ?: null];
            }
        }

        return $icons;
    }

    private function linkHref(?\DOMXPath $doc, string $rel): ?string
    {
        foreach ($doc?->query('//link[@href]') ?: [] as $link) {
            if (in_array($rel, $this->rels($link), true)) {
                return $link->getAttribute('href');
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function rels(\DOMElement $link): array
    {
        return preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Resolves a link against the page URL; null for anything but http(s). */
    private function absolute(string $base, ?string $ref): ?string
    {
        $ref = trim((string) $ref);
        if ($ref === '' || str_starts_with($ref, '#')) {
            return null;
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $ref, $scheme)) {
            return in_array(strtolower($scheme[1]), ['http', 'https'], true) ? preg_replace('/#.*$/', '', $ref) : null;
        }
        $base = parse_url($base);
        $origin = $base['scheme'].'://'.$base['host'];
        if (str_starts_with($ref, '//')) {
            return $base['scheme'].':'.preg_replace('/#.*$/', '', $ref);
        }
        $ref = preg_replace('/#.*$/', '', $ref);
        [$path, $query] = array_pad(explode('?', $ref, 2), 2, null);
        if ($path === '') {
            $path = $base['path'] ?? '/';
            $query ??= $base['query'] ?? null;
        } elseif ($path[0] !== '/') {
            $path = preg_replace('#/[^/]*$#', '/', $base['path'] ?? '/').$path;
        }
        $segments = [];
        $parts = explode('/', $path);
        $directory = in_array(end($parts), ['.', '..'], true);
        foreach ($parts as $segment) {
            if ($segment === '..') {
                if (count($segments) > 1) {
                    array_pop($segments);
                }
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $path = implode('/', $segments).($directory ? '/' : '');

        return $origin.'/'.ltrim($path, '/').($query !== null ? "?{$query}" : '');
    }

    private function origin(string $url): string
    {
        return parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
    }

    private function sameHost(string $a, string $b): bool
    {
        return strtolower((string) parse_url($a, PHP_URL_HOST)) === strtolower((string) parse_url($b, PHP_URL_HOST));
    }

    /** URLs compared the way canonicals are: case-insensitive host, trailing slash ignored. */
    private function comparable(string $url): string
    {
        $parts = parse_url($url) ?: [];

        return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '')).rtrim($parts['path'] ?? '', '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function text(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    private function check(string $section, string $key, string $label, string $status, string $detail): void
    {
        $this->checks[$section][] = compact('key', 'label', 'status', 'detail');
    }
}
