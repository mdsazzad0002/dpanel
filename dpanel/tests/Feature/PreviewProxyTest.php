<?php

namespace Tests\Feature;

use App\Services\Seo\PreviewProxy;
use App\Support\PublicHttpFetcher;
use Tests\TestCase;

class PreviewProxyTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $requested;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requested = new \ArrayObject;
        $requested = $this->requested;
        $this->app->instance(PublicHttpFetcher::class, new class($requested) extends PublicHttpFetcher
        {
            public function __construct(private \ArrayObject $requested) {}

            public function fetch(string $url, int $maxBytes): array
            {
                $this->requested[] = $url;
                $pages = [
                    'https://shop.test/' => ['type' => 'text/html', 'body' => '<html><head><base href="/assets/"><link rel="stylesheet" href="site.css" integrity="sha384-x"><style>body{background:url(bg.png)}</style></head><body><img src="logo.png" srcset="logo.png 1x, //cdn.test/logo@2x.png 2x"><a href="https://other.test/page#top">Other</a><a href="#top">Top</a><a href="mailto:a@shop.test">Mail</a></body></html>'],
                    'https://shop.test/old' => ['status' => 301, 'location' => '/'],
                    'https://shop.test/assets/site.css' => ['type' => 'text/css', 'body' => '@import "more.css"; h1{background:url(\'../img/h.png\')}'],
                ];
                $page = $pages[$url] ?? ['status' => 404, 'type' => 'text/plain', 'body' => 'missing'];

                return ['url' => $url, 'status' => $page['status'] ?? 200, 'type' => $page['type'] ?? '', 'body' => $page['body'] ?? '', 'headers' => [], 'location' => $page['location'] ?? null, 'truncated' => false, 'error' => null, 'time_ms' => 1];
            }
        });
    }

    private function proxied(string $url): string
    {
        return app(PreviewProxy::class)->url($url, 'default', null);
    }

    /** The target URLs a proxied body links to. */
    private function targets(string $body): array
    {
        preg_match_all('#seo/preview-proxy/([^/"\'\s)]+)([^"\'\s)]*)#', $body, $m, PREG_SET_ORDER);

        return array_map(fn (array $link): string => app(PreviewProxy::class)->target($link[1], html_entity_decode($link[2]))['url'], $m);
    }

    public function test_every_url_in_a_page_points_at_the_proxy(): void
    {
        $this->get($this->proxied('https://shop.test/old'))->assertStatus(301)->assertHeader('Location', $this->proxied('https://shop.test/'));
        $response = $this->get($this->proxied('https://shop.test/'))->assertOk();

        $response->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-popups allow-forms');
        // No session, so no cookies for the browser to reject.
        $this->assertSame([], $response->headers->getCookies());
        $body = $response->getContent();
        $this->assertSame([
            'https://shop.test/assets/site.css',
            'https://shop.test/assets/bg.png',
            'https://shop.test/assets/logo.png',
            'https://shop.test/assets/logo.png',
        ], $this->targets($body));
        // Other hosts load directly, made absolute.
        $this->assertStringContainsString('https://cdn.test/logo@2x.png 2x', $body);
        $this->assertStringContainsString('href="https://other.test/page"', $body);
        $this->assertStringNotContainsString('integrity', $body);
        $this->assertStringNotContainsString('href="/assets/"', $body);
        $this->assertStringContainsString('href="#top"', $body);
        $this->assertStringContainsString('href="mailto:a@shop.test"', $body);
        $this->assertSame(['https://shop.test/old', 'https://shop.test/'], $this->requested->getArrayCopy());
    }

    public function test_paths_a_script_builds_under_a_proxied_page_are_served(): void
    {
        // e.g. import "./site.css" from a module at /assets/app.js resolves here.
        $page = $this->proxied('https://shop.test/');
        $this->get($page.'assets/site.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->assertSame(['https://shop.test/assets/site.css'], $this->requested->getArrayCopy());
    }

    public function test_stylesheets_are_rewritten_against_their_own_url(): void
    {
        $body = $this->get($this->proxied('https://shop.test/assets/site.css'))->assertOk()->getContent();

        $this->assertSame(['https://shop.test/assets/more.css', 'https://shop.test/img/h.png'], $this->targets($body));
    }

    public function test_unsigned_or_altered_urls_are_refused(): void
    {
        $this->get('/seo/preview-proxy/forged.token/')->assertForbidden();
        // Another origin under a token signed for shop.test.
        [$payload, $signature] = explode('.', explode('/', explode('/seo/preview-proxy/', $this->proxied('https://shop.test/'))[1])[0]);
        $forged = rtrim(strtr(base64_encode(str_replace('shop.test', 'evil.test', base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=');
        $this->get("/seo/preview-proxy/{$forged}.{$signature}/")->assertForbidden();
        $expired = $this->proxied('https://shop.test/');
        $this->travel(2)->hours();
        $this->get($expired)->assertForbidden();
        $this->requested->exchangeArray([]);
        $this->assertSame([], $this->requested->getArrayCopy());
    }

    public function test_upstream_status_is_passed_on(): void
    {
        $this->get($this->proxied('https://shop.test/nope'))->assertNotFound();
    }
}
