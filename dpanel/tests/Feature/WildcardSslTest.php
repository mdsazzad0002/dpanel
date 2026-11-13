<?php

namespace Tests\Feature;

use App\Models\SslCertificate;
use App\Models\User;
use App\Models\Website;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Ssl\WildcardDnsTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog M4: a wildcard certificate, proved with a DNS-01 TXT record. */
class WildcardSslTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('c', 64);
        URL::defaults(['token' => $this->token]);
        config([
            'dpanel.execution_api_base_url' => 'http://drust.test',
            'dpanel.execution_api_token' => 'secret',
            'dns.engine' => 'powerdns',
            'services.cloudflare.api_token' => null,
        ]);
    }

    private function website(string $domain = 'example.com', array $extra = []): Website
    {
        return Website::query()->forceCreate($extra + [
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => true, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'letsencrypt',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);

        return $user;
    }

    private function valid(bool $wildcard): array
    {
        return ['success' => true, 'data' => [
            'status' => 'valid', 'issued' => false, 'renewed' => true, 'wildcard' => $wildcard,
            'certificate_path' => '/etc/drust/tls/example.com.crt',
            'private_key_path' => '/etc/drust/tls/example.com.key',
            'expires_at' => now()->addDays(90)->toIso8601String(),
        ]];
    }

    private function toggle(Website $site, bool $enabled): \Illuminate\Testing\TestResponse
    {
        return $this->patchJson("/dpt{$this->token}/websites/{$site->id}/ssl/wildcard", ['enabled' => $enabled]);
    }

    /** @return array<int, array<string, mixed>> what drust's ssl/ensure was sent */
    private function ensureCalls(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/api/v1/ssl/ensure'))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_a_zone_on_this_server_is_proved_through_powerdns(): void
    {
        Http::fake(['drust.test/api/v1/ssl/ensure' => Http::response($this->valid(true))]);
        DB::table('domains')->insert(['name' => 'example.com', 'type' => 'NATIVE']);
        $site = $this->website();
        $this->admin();

        $this->toggle($site, true)->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('ssl.wildcard', true);

        $this->assertTrue($site->fresh()->ssl_wildcard);
        $calls = $this->ensureCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(['provider' => 'powerdns', 'zone' => 'example.com'], $calls[0]['wildcard']);
        $this->assertFalse($calls[0]['force']);
    }

    public function test_a_subdomain_site_uses_the_zone_it_lives_in(): void
    {
        DB::table('domains')->insert(['name' => 'example.com', 'type' => 'NATIVE']);

        $target = app(WildcardDnsTarget::class)->for('Shop.Example.com.');

        $this->assertSame(['provider' => 'powerdns', 'zone' => 'example.com'], $target);
        $this->assertSame(['a.b.example.com', 'b.example.com', 'example.com'], WildcardDnsTarget::zoneCandidates('a.b.example.com'));
        $this->assertSame([], WildcardDnsTarget::zoneCandidates('203.0.113.7'));
        $this->assertSame([], WildcardDnsTarget::zoneCandidates('-bad.example.com'));
    }

    public function test_a_zone_on_cloudflare_is_proved_there_with_the_panel_token(): void
    {
        config(['services.cloudflare.api_token' => 'cf-token']);
        Http::fake([
            'api.cloudflare.com/client/v4/zones?name=example.com*' => Http::response(['success' => true, 'result' => [['id' => 'abc', 'name' => 'example.com']]]),
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []]),
            'drust.test/api/v1/ssl/ensure' => Http::response($this->valid(true)),
        ]);
        $site = $this->website('shop.example.com');
        $this->admin();

        $this->toggle($site, true)->assertOk();

        $this->assertSame(
            ['provider' => 'cloudflare', 'zone' => 'example.com', 'api_token' => 'cf-token'],
            $this->ensureCalls()[0]['wildcard'],
        );
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.cloudflare.com') && $request->hasHeader('Authorization', 'Bearer cf-token'));
    }

    public function test_without_a_zone_it_is_refused_and_nothing_changes(): void
    {
        Http::fake();
        $site = $this->website();
        $this->admin();

        $this->toggle($site, true)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'CLOUDFLARE_API_TOKEN'));

        $this->assertFalse($site->fresh()->ssl_wildcard);
        $this->assertTrue($site->fresh()->enable_ssl);
        $this->assertSame([], $this->ensureCalls());
    }

    public function test_a_failed_issue_keeps_the_old_setting(): void
    {
        Http::fake(['drust.test/api/v1/ssl/ensure' => Http::response(['success' => false, 'message' => 'Failed: No TXT record found'], 200)]);
        DB::table('domains')->insert(['name' => 'example.com', 'type' => 'NATIVE']);
        $site = $this->website(extra: ['enable_ssl' => false]);
        $this->admin();

        $this->toggle($site, true)->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, 'No TXT record found'));

        $this->assertFalse($site->fresh()->ssl_wildcard);
        $this->assertFalse($site->fresh()->enable_ssl);
    }

    public function test_only_a_main_domain_can_be_a_wildcard(): void
    {
        Http::fake();
        $main = $this->website();
        $this->admin();

        $this->toggle($this->website('www.example.com', ['type' => 'alias', 'parent_id' => $main->id]), true)->assertStatus(422);
        $this->toggle($this->website('203.0.113.7'), true)->assertStatus(422);
        $this->toggle($this->website('shop.test'), true)->assertStatus(422);
        $this->assertSame([], $this->ensureCalls());
    }

    public function test_turning_it_off_asks_for_a_plain_certificate_now(): void
    {
        Http::fake(['drust.test/api/v1/ssl/ensure' => Http::response($this->valid(false))]);
        $site = $this->website(extra: ['ssl_wildcard' => true]);
        $this->admin();

        $this->toggle($site, false)->assertOk()->assertJsonPath('enabled', false);

        $this->assertFalse($site->fresh()->ssl_wildcard);
        $call = $this->ensureCalls()[0];
        $this->assertArrayNotHasKey('wildcard', $call);
        $this->assertTrue($call['force']);
    }

    public function test_a_name_under_a_wildcard_site_uses_its_certificate_without_asking_drust(): void
    {
        Http::fake(['drust.test/api/v1/ssl/ensure' => Http::response($this->valid(false))]);
        $this->website(extra: ['ssl_wildcard' => true]);
        $expires = now()->addDays(60)->startOfSecond();
        SslCertificate::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => 'example.com', 'type' => 'letsencrypt', 'status' => 'valid',
            'certificate_path' => '/etc/drust/tls/example.com.crt', 'private_key_path' => '/etc/drust/tls/example.com.key',
            'expires_at' => $expires,
        ]);
        $shop = $this->website('shop.example.com');

        $data = app(SslLifecycleService::class)->ensureForWebsite($shop);

        $this->assertSame('example.com', $data['wildcard_of']);
        $this->assertSame([], $this->ensureCalls());
        $row = SslCertificate::query()->where('domain', 'shop.example.com')->firstOrFail();
        $this->assertSame('/etc/drust/tls/example.com.crt', $row->certificate_path);
        $this->assertTrue($row->expires_at->equalTo($expires));

        // Two labels down is not covered, and a forced reinstall always asks.
        app(SslLifecycleService::class)->ensureForWebsite($this->website('a.b.example.com'));
        app(SslLifecycleService::class)->ensureForWebsite($shop, true);
        $this->assertCount(2, $this->ensureCalls());
    }
}
