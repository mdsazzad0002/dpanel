<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Seo\SitemapVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class SitemapVerifyTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('b', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->string('scope')->nullable();
            $table->boolean('enable_ssl')->default(false);
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        DB::table('websites')->insert(['id' => 'site-1', 'domain' => 'shop.test', 'scope' => 'user', 'enable_ssl' => true]);
    }

    public function test_internal_is_the_default_source(): void
    {
        $this->expectVerify('shop.test', true, '/feed.xml');

        $this->verify(['website_id' => 'site-1', 'sitemap_path' => '/feed.xml'])->assertOk()->assertJsonPath('ok', true);
    }

    public function test_external_site_and_sitemap_urls_are_parsed(): void
    {
        $this->expectVerify('example.org', true, null);
        $this->verify(['source' => 'external', 'url' => 'Example.org/'])->assertOk();
    }

    public function test_external_sitemap_url_keeps_path_and_scheme(): void
    {
        $this->expectVerify('news.example.org', false, '/maps/sitemap.xml?page=2');
        $this->verify(['source' => 'external', 'url' => 'http://news.example.org/maps/sitemap.xml?page=2'])->assertOk();
    }

    public function test_external_rejects_ports_credentials_and_ip_literals(): void
    {
        $this->app->instance(SitemapVerifier::class, Mockery::mock(SitemapVerifier::class)->shouldNotReceive('discover')->getMock());

        foreach (['https://example.org:8080/', 'https://user@example.org/', 'http://127.0.0.1/', 'ftp://example.org/', ''] as $url) {
            $this->verify(['source' => 'external', 'url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
        }
    }

    public function test_inspect_and_pages_use_the_same_target(): void
    {
        $verifier = Mockery::mock(SitemapVerifier::class);
        $verifier->shouldReceive('inspect')->once()->with('example.org', true, 'https://example.org/a.xml')->andReturn(['type' => 'urlset']);
        $verifier->shouldReceive('checkPages')->once()->with('shop.test', true, ['https://shop.test/'])->andReturn(['samples' => []]);
        $verifier->shouldReceive('inspect')->once()->andThrow(new \InvalidArgumentException('Only sitemaps on shop.test'));
        $this->app->instance(SitemapVerifier::class, $verifier);

        $this->action('inspect', ['source' => 'external', 'url' => 'example.org', 'sitemap_url' => 'https://example.org/a.xml'])->assertOk();
        $this->action('pages', ['website_id' => 'site-1', 'urls' => ['https://shop.test/']])->assertOk();
        $this->action('inspect', ['website_id' => 'site-1', 'sitemap_url' => 'https://evil.test/x.xml'])->assertUnprocessable()->assertJsonValidationErrors('sitemap_url');
    }

    public function test_robots_txt_is_saved_for_internal_sites_only(): void
    {
        $robots = Mockery::mock(\App\Services\Seo\RobotsTxtFile::class);
        $robots->shouldReceive('save')->once()->withArgs(fn ($website, $content) => $website->id === 'site-1' && $content === "User-agent: *\nAllow: /")->andReturnNull();
        $robots->shouldReceive('locate')->andReturn(['editable' => true, 'reason' => null, 'path' => '/home/shop/robots.txt', 'exists' => true]);
        $this->app->instance(\App\Services\Seo\RobotsTxtFile::class, $robots);
        $activity = Mockery::mock(\App\Services\ActivityLogService::class);
        $activity->shouldReceive('log')->once();
        $this->app->instance(\App\Services\ActivityLogService::class, $activity);

        $this->withoutMiddleware()->actingAs($this->admin)->withSession(['panel_session_token' => $this->token])
            ->putJson("/cpsess{$this->token}/seo/sitemap-verify/robots", ['website_id' => 'site-1', 'content' => "User-agent: *\nAllow: /"])
            ->assertOk()->assertJsonPath('file.exists', true);
        $this->withoutMiddleware()->actingAs($this->admin)->withSession(['panel_session_token' => $this->token])
            ->putJson("/cpsess{$this->token}/seo/sitemap-verify/robots", ['website_id' => 'missing', 'content' => 'x'])
            ->assertNotFound();
    }

    public function test_external_report_marks_robots_read_only(): void
    {
        $this->expectVerify('example.org', true, null);
        $this->verify(['source' => 'external', 'url' => 'example.org'])->assertOk()->assertJsonPath('robots.file.editable', false);
    }

    private function action(string $action, array $body): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token])
            ->postJson("/cpsess{$this->token}/seo/sitemap-verify/{$action}", $body);
    }

    private function expectVerify(string $host, bool $https, ?string $path): void
    {
        $verifier = Mockery::mock(SitemapVerifier::class);
        $verifier->shouldReceive('discover')->once()->with($host, $https, $path)->andReturn(['ok' => true, 'robots' => []]);
        $this->app->instance(SitemapVerifier::class, $verifier);
    }

    private function verify(array $body): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token])
            ->postJson("/cpsess{$this->token}/seo/sitemap-verify", $body);
    }
}
