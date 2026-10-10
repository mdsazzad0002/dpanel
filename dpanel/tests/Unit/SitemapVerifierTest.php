<?php

namespace Tests\Unit;

use App\Services\Seo\SitemapVerifier;
use App\Support\PublicHttpFetcher;
use Tests\TestCase;

class SitemapVerifierTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $responses url => partial response */
    private function verifier(array $responses): SitemapVerifier
    {
        $fetcher = new class($responses) extends PublicHttpFetcher
        {
            public function __construct(private array $responses) {}

            public function fetchMany(array $urls, int $maxBytes): array
            {
                $results = [];
                foreach ($urls as $url) {
                    $results[$url] = ($this->responses[$url] ?? []) + [
                        'url' => $url, 'status' => 404, 'type' => 'text/html', 'body' => '', 'headers' => [],
                        'location' => null, 'truncated' => false, 'error' => null,
                    ];
                }

                return $results;
            }
        };

        return new SitemapVerifier($fetcher);
    }

    private function xml(string $body): array
    {
        return ['status' => 200, 'type' => 'application/xml', 'body' => $body];
    }

    private function site(): array
    {
        $ns = SitemapVerifier::NAMESPACE;

        return [
            'https://shop.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow: /cart\nSitemap: https://shop.test/index.xml\nSitemap: https://elsewhere.test/s.xml"],
            'https://shop.test/index.xml' => $this->xml("<sitemapindex xmlns=\"{$ns}\"><sitemap><loc>https://shop.test/a.xml.gz</loc></sitemap></sitemapindex>"),
            'https://shop.test/a.xml.gz' => ['status' => 200, 'type' => 'application/gzip', 'body' => gzencode(
                "<urlset xmlns=\"{$ns}\"><url><loc>https://shop.test/</loc><lastmod>2026-01-02</lastmod></url>"
                ."<url><loc>https://www.shop.test/hidden</loc><lastmod>yesterday</lastmod></url>"
                ."<url><loc>https://other.test/x</loc></url><url><loc>/relative</loc></url></urlset>"
            )],
            'https://shop.test/' => ['status' => 200, 'body' => '<link rel="canonical" href="/">'],
            'https://www.shop.test/hidden' => ['status' => 200, 'body' => '<meta name="robots" content="noindex, follow">'],
        ];
    }

    public function test_discovery_lists_sitemaps_without_opening_them(): void
    {
        $report = $this->verifier($this->site())->discover('shop.test', true);

        $checks = array_column($report['checks'], 'status', 'key');
        $this->assertSame('pass', $checks['robots-block']);
        $this->assertSame('warn', $checks['robots-foreign']);
        $this->assertSame('pass', $checks['discovery']);
        $this->assertSame([['url' => 'https://shop.test/index.xml', 'source' => 'robots']], $report['sitemaps']);
    }

    public function test_index_and_child_are_inspected_on_demand(): void
    {
        $verifier = $this->verifier($this->site());

        $index = $verifier->inspect('shop.test', true, 'https://shop.test/index.xml');
        $this->assertSame(['index', 1, 'pass'], [$index['type'], $index['children'], $index['status']]);
        $this->assertSame('https://shop.test/a.xml.gz', $index['entries'][0]['loc']);
        $this->assertStringContainsString('<sitemapindex', $index['raw']);

        $child = $verifier->inspect('shop.test', true, 'https://shop.test/a.xml.gz');
        $this->assertSame(['urlset', 4, 'fail'], [$child['type'], $child['urls'], $child['status']]);
        // The raw view shows the unpacked XML, not gzip bytes.
        $this->assertStringStartsWith('<urlset', $child['raw']);
        $messages = implode(' ', array_column($child['issues'], 'message'));
        $this->assertStringContainsString('relative <loc>', $messages);
        $this->assertStringContainsString('another host', $messages);
        $this->assertStringContainsString('www/non-www', $messages);
        $this->assertStringContainsString('W3C date', $messages);
        $this->assertSame(['fail', 'warn', 'warn', 'warn'], array_column($child['issues'], 'level'));
        $this->assertSame([[], ['www', 'bad_lastmod'], ['foreign'], ['invalid']], array_column($child['entries'], 'flags'));
        $this->assertSame(['https://shop.test/', 'https://www.shop.test/hidden'], $child['sample']);
    }

    public function test_only_the_sites_own_sitemaps_can_be_opened(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->verifier([])->inspect('shop.test', true, 'https://elsewhere.test/s.xml');
    }

    public function test_sample_pages_are_judged(): void
    {
        $result = $this->verifier($this->site())->checkPages('shop.test', true, ['https://shop.test/', 'https://www.shop.test/hidden', 'https://other.test/x']);

        $this->assertSame(['https://shop.test/' => 'pass', 'https://www.shop.test/hidden' => 'fail'], array_column($result['samples'], 'status', 'url'));
        $this->assertSame('fail', $result['summary']['status']);
    }

    public function test_blocked_robots_and_missing_sitemap_fail(): void
    {
        $report = $this->verifier([
            'http://blog.test/robots.txt' => ['status' => 200, 'body' => "User-agent: Googlebot\nUser-agent: *\nDisallow: /"],
        ])->discover('blog.test', false);

        $checks = array_column($report['checks'], 'status', 'key');
        $this->assertSame('fail', $checks['robots-block']);
        $this->assertSame('fail', $checks['discovery']);
        $this->assertSame([], $report['sitemaps']);
    }

    public function test_invalid_xml_and_redirects_are_reported(): void
    {
        $verifier = $this->verifier([
            'https://shop.test/a.xml' => $this->xml('<urlset><url><loc>broken'),
            'https://shop.test/b.xml' => ['status' => 301, 'location' => 'https://shop.test/c.xml'],
        ]);

        $broken = $verifier->inspect('shop.test', true, 'https://shop.test/a.xml');
        $this->assertStringContainsString('Not valid XML', $broken['issues'][0]['message']);
        $this->assertSame('<urlset><url><loc>broken', $broken['raw']);
        $this->assertStringContainsString('Redirects to', $verifier->inspect('shop.test', true, 'https://shop.test/b.xml')['issues'][0]['message']);
    }
}
