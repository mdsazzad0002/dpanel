<?php

namespace Tests\Feature;

use App\Jobs\IssueWebsiteSslJob;
use App\Models\Website;
use App\Services\Ssl\SslLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class IssueWebsiteSslJobTest extends TestCase
{
    use RefreshDatabase;

    private function website(string $domain): Website
    {
        return Website::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'domain' => $domain,
            'root_path' => '/home/site/public_html',
            'site_owner' => 'site',
            'php_version' => '8.3',
            'enable_ssl' => true,
            'ssl_mode' => 'letsencrypt',
            'status' => 'live',
        ]);
    }

    public function test_it_issues_the_certificate(): void
    {
        $website = $this->website('ok-example.test');
        $ssl = Mockery::mock(SslLifecycleService::class);
        $ssl->shouldReceive('ensureForWebsite')->once()->andReturn(['status' => 'valid']);

        (new IssueWebsiteSslJob((string) $website->id))->handle($ssl);

        $this->assertSame('letsencrypt', $website->fresh()->ssl_mode);
    }

    public function test_a_failure_keeps_the_site_on_http_without_throwing(): void
    {
        $website = $this->website('fail-example.test');
        $ssl = Mockery::mock(SslLifecycleService::class);
        $ssl->shouldReceive('ensureForWebsite')->once()->andThrow(new \RuntimeException('ACME failed'));

        (new IssueWebsiteSslJob((string) $website->id))->handle($ssl);

        $website->refresh();
        $this->assertSame('none', $website->ssl_mode);
        $this->assertTrue((bool) $website->enable_ssl);
    }

    public function test_a_failed_www_alias_has_ssl_turned_off(): void
    {
        $alias = $this->website('www.fail-example.test');
        $ssl = Mockery::mock(SslLifecycleService::class);
        $ssl->shouldReceive('ensureForWebsite')->once()->andThrow(new \RuntimeException('ACME failed'));

        (new IssueWebsiteSslJob((string) $alias->id, disableSslOnFailure: true))->handle($ssl);

        $alias->refresh();
        $this->assertSame('none', $alias->ssl_mode);
        $this->assertFalse((bool) $alias->enable_ssl);
    }
}
