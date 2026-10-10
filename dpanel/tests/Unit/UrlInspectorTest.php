<?php

namespace Tests\Unit;

use App\Services\Seo\UrlInspector;
use App\Support\PublicHttpFetcher;
use Tests\TestCase;

class UrlInspectorTest extends TestCase
{
    /** User agents the fake was asked to use. */
    private \ArrayObject $agents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agents = new \ArrayObject;
    }

    /** @param array<string, array<string, mixed>> $responses url => partial response */
    private function inspector(array $responses): UrlInspector
    {
        $fetcher = new class($responses, $this->agents) extends PublicHttpFetcher
        {
            public function __construct(private array $responses, private \ArrayObject $agents, private string $ua = 'default') {}

            public function withUserAgent(string $userAgent): static
            {
                $copy = clone $this;
                $copy->ua = $userAgent;

                return $copy;
            }

            public function fetchMany(array $urls, int $maxBytes): array
            {
                $results = [];
                foreach ($urls as $url) {
                    $this->agents[] = $this->ua;
                    $results[$url] = ($this->responses[$url] ?? []) + [
                        'url' => $url, 'status' => 404, 'type' => 'text/html', 'body' => '', 'headers' => [],
                        'location' => null, 'truncated' => false, 'error' => null, 'time_ms' => 120,
                    ];
                }

                return $results;
            }
        };

        return new UrlInspector($fetcher);
    }

    private function png(int $width, int $height): array
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return ['status' => 200, 'type' => 'image/png', 'body' => ob_get_clean()];
    }

    private function ico(array $sizes): array
    {
        $body = pack('vvv', 0, 1, count($sizes));
        foreach ($sizes as $size) {
            $body .= chr($size % 256).chr($size % 256).str_repeat("\0", 14);
        }

        return ['status' => 200, 'type' => 'image/x-icon', 'body' => $body];
    }

    private function page(string $head, string $body = '<h1>Shoes</h1><p>'): array
    {
        $html = "<!doctype html><html lang=\"en\"><head>{$head}</head><body>{$body}".str_repeat('word ', 250).'</p><a href="/about">About</a></body></html>';

        return ['status' => 200, 'type' => 'text/html', 'body' => $html, 'headers' => ['content-type' => 'text/html; charset=utf-8', 'content-encoding' => 'br', 'strict-transport-security' => 'max-age=63072000']];
    }

    private function goodHead(): string
    {
        return '<title>Running shoes for every distance | Shop</title>'
            .'<meta name="description" content="'.str_repeat('Lightweight running shoes with free returns. ', 3).'">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<link rel="canonical" href="https://shop.test/shoes">'
            .'<meta property="og:title" content="Running shoes"><meta property="og:description" content="Free returns">'
            .'<meta property="og:url" content="https://shop.test/shoes"><meta property="og:type" content="website">'
            .'<meta property="og:image" content="/img/og.png"><meta property="og:image:alt" content="Shoes">'
            .'<meta name="twitter:card" content="summary_large_image">'
            .'<link rel="icon" href="/icon-192.png" sizes="192x192"><link rel="apple-touch-icon" href="/apple.png">'
            .'<link rel="manifest" href="/site.webmanifest"><meta name="theme-color" content="#0f766e">'
            .'<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization"},{"@type":"BreadcrumbList"}]}</script>'
            .'<script>navigator.serviceWorker.register("/sw.js")</script>';
    }

    /** @return array<string, array<string, mixed>> */
    private function goodSite(): array
    {
        return [
            'http://shop.test/shoes' => ['status' => 301, 'location' => 'https://shop.test/shoes'],
            'https://shop.test/shoes' => $this->page($this->goodHead()),
            'https://shop.test/robots.txt' => ['status' => 200, 'type' => 'text/plain', 'body' => "User-agent: *\nDisallow: /cart\n\nUser-agent: Yandex\nDisallow: /shoes"],
            'https://shop.test/img/og.png' => $this->png(1200, 630),
            'https://shop.test/icon-192.png' => $this->png(192, 192),
            'https://shop.test/apple.png' => $this->png(180, 180),
            'https://shop.test/favicon.ico' => $this->ico([16, 32, 48]),
            'https://shop.test/site.webmanifest' => ['status' => 200, 'type' => 'application/manifest+json', 'body' => json_encode([
                'name' => 'Shoe Shop', 'short_name' => 'Shoes', 'start_url' => '/?source=pwa', 'display' => 'standalone',
                'theme_color' => '#0f766e', 'background_color' => '#ffffff',
                'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192'], ['src' => 'icons/512.png', 'sizes' => '512x512', 'purpose' => 'any maskable']],
            ])],
            'https://shop.test/icons/512.png' => $this->png(512, 512),
        ];
    }

    /** @return array<string, array<string, mixed>> key => check */
    private function checks(array $report): array
    {
        $checks = [];
        foreach ($report['sections'] as $section) {
            foreach ($section['checks'] as $check) {
                $checks[$check['key']] = $check;
            }
        }

        return $checks;
    }

    public function test_a_well_built_page_passes_and_yandex_is_blocked_by_robots(): void
    {
        $report = $this->inspector($this->goodSite())->inspect('http://shop.test/shoes');
        $checks = $this->checks($report);

        $this->assertSame('https://shop.test/shoes', $report['final_url']);
        $this->assertSame([301, 200], array_column($report['http']['chain'], 'status'));
        $this->assertSame('pass', $checks['redirects']['status']);
        $this->assertSame(0, $report['totals']['fail'], json_encode(array_filter($checks, fn ($c) => $c['status'] === 'fail')));

        $engines = array_column($report['engines'], null, 'key');
        $this->assertSame('pass', $engines['google']['verdict']);
        $this->assertSame('pass', $engines['bing']['verdict']);
        $this->assertSame('fail', $engines['yandex']['verdict']);
        $this->assertStringContainsString('Disallow: /shoes', $engines['yandex']['crawl']['detail']);

        foreach (['title', 'description', 'h1', 'canonical', 'viewport', 'og-tags', 'og-image', 'twitter-card', 'google', 'ico', 'apple', 'manifest', 'icons', 'maskable', 'service-worker', 'schema'] as $key) {
            $this->assertSame('pass', $checks[$key]['status'], "{$key}: {$checks[$key]['detail']}");
        }
        $this->assertSame(['Organization', 'BreadcrumbList'], $report['meta']['schema_types']);
        $this->assertSame([16, 32, 48], collect($report['icons'])->firstWhere('rel', 'favicon.ico')['sizes']);
        $this->assertSame('https://shop.test/icon-192.png', collect($report['icons'])->firstWhere('google', true)['url']);

        $preview = $report['preview'];
        $this->assertSame('Running shoes for every distance | Shop', $preview['title']);
        $this->assertSame('Shoe Shop', $preview['site_name']);
        $this->assertStringStartsWith('data:image/png;base64,', $preview['favicon']);
        $this->assertStringStartsWith('data:image/png;base64,', $preview['social']['image']);
        $this->assertSame('Shoes', $preview['app']['short_name']);
        $this->assertStringStartsWith('data:image/png;base64,', $preview['app']['icon']);
    }

    public function test_problems_are_reported(): void
    {
        $head = '<title>Hi</title><meta name="robots" content="noindex, nofollow">'
            .'<link rel="canonical" href="https://shop.test/other"><link rel="canonical" href="https://shop.test/third">'
            .'<link rel="icon" href="/wide.png"><meta property="og:image" content="https://cdn.test/small.png">'
            .'<meta name="twitter:card" content="large"><link rel="manifest" href="/m.json">'
            .'<script type="application/ld+json">{broken</script>';
        $report = $this->inspector([
            'https://shop.test/' => ['status' => 302, 'location' => 'https://shop.test/a'],
            'https://shop.test/a' => ['status' => 302, 'location' => 'https://shop.test/b'],
            'https://shop.test/b' => $this->page($head, '<img src="x.png"><p>') + ['headers' => ['x-robots-tag' => 'bingbot: noindex']],
            'https://shop.test/robots.txt' => ['status' => 404],
            'https://shop.test/wide.png' => $this->png(64, 32),
            'https://cdn.test/small.png' => $this->png(100, 100),
            'https://shop.test/m.json' => ['status' => 200, 'type' => 'application/json', 'body' => '{"display":"browser"}'],
        ])->inspect('https://shop.test/');
        $checks = $this->checks($report);

        $this->assertSame('warn', $checks['redirects']['status']);
        $this->assertStringContainsString('302 → 302 → 200', $checks['redirects']['detail']);
        $this->assertSame('info', $checks['robots']['status']);
        $this->assertSame('warn', $checks['title']['status']);
        $this->assertSame('fail', $checks['canonical-multiple']['status']);
        $this->assertSame('fail', $checks['meta-robots']['status']);
        $this->assertSame('warn', $checks['nofollow']['status']);
        $this->assertSame('fail', $checks['viewport']['status']);
        $this->assertSame('warn', $checks['img-alt']['status']);
        $this->assertSame('fail', $checks['schema']['status']);
        $this->assertSame('fail', $checks['og-image']['status']);
        $this->assertStringContainsString('200×200', $checks['og-image']['detail']);
        $this->assertSame('fail', $checks['twitter-card']['status']);
        $this->assertSame('fail', $checks['google']['status']);
        $this->assertSame('warn', $checks['ico']['status']);
        $this->assertSame('fail', $checks['name']['status']);
        $this->assertSame('warn', $checks['display']['status']);
        $this->assertSame('fail', $checks['icons']['status']);
        $this->assertSame('fail', array_column($report['engines'], null, 'key')['google']['verdict']);
        $this->assertNull($report['preview']['app']['icon']);
    }

    public function test_bing_only_noindex_header_and_robots_server_error(): void
    {
        $site = $this->goodSite();
        $site['https://shop.test/shoes']['headers']['x-robots-tag'] = 'max-snippet: 50, bingbot: noindex';
        $site['https://shop.test/robots.txt'] = ['status' => 503];
        $report = $this->inspector($site)->inspect('https://shop.test/shoes');
        $engines = array_column($report['engines'], null, 'key');

        $this->assertSame('fail', $this->checks($report)['robots']['status']);
        $this->assertSame('fail', $engines['google']['crawl']['status']);
        $this->assertTrue($engines['bing']['noindex']);
        $this->assertFalse($engines['google']['noindex']);
    }

    public function test_non_html_and_unreachable_pages_stop_early(): void
    {
        $report = $this->inspector([
            'https://shop.test/file.pdf' => ['status' => 200, 'type' => 'application/pdf', 'body' => '%PDF'],
        ])->inspect('https://shop.test/file.pdf');
        $this->assertNull($report['meta']);
        $this->assertSame('warn', $this->checks($report)['type']['status']);

        $report = $this->inspector([
            'https://shop.test/' => ['status' => 0, 'error' => 'Could not resolve host'],
        ])->inspect('https://shop.test/');
        $this->assertSame('fail', $this->checks($report)['status']['status']);
        $this->assertSame('fail', $report['engines'][0]['verdict']);
    }

    public function test_redirect_loops_stop(): void
    {
        $report = $this->inspector([
            'https://shop.test/' => ['status' => 301, 'location' => 'https://shop.test/a'],
            'https://shop.test/a' => ['status' => 301, 'location' => 'https://shop.test/'],
        ])->inspect('https://shop.test/');

        $this->assertStringContainsString('Redirect loop', $this->checks($report)['status']['detail']);
    }

    public function test_relative_links_resolve_against_the_page(): void
    {
        $site = [
            'https://shop.test/blog/post/' => $this->page('<link rel="icon" href="../../img/./fav.svg"><meta property="og:image" content="//cdn.test/og.png">'),
            'https://shop.test/img/fav.svg' => ['status' => 200, 'type' => 'image/svg+xml', 'body' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"></svg>'],
            'https://cdn.test/og.png' => $this->png(1200, 630),
        ];
        $report = $this->inspector($site)->inspect('https://shop.test/blog/post/', 'googlebot');
        $google = collect($report['icons'])->firstWhere('google', true);

        $this->assertSame('https://shop.test/img/fav.svg', $google['url']);
        $this->assertTrue($google['vector']);
        $this->assertSame('pass', $this->checks($report)['og-image']['status']);
        $this->assertSame('Googlebot (smartphone)', $report['agent']);
        $this->assertStringContainsString('Googlebot/2.1', $this->agents[0]);
    }
}
