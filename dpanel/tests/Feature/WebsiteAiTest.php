<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\User;
use App\Models\Website;
use App\Services\AiGateway\PanelAi;
use App\Services\Platform\SiteLogClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebsiteAiTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        Website::query()->create([
            'id' => 'site-1',
            'domain' => 'example.test',
            'status' => 'active',
            'type' => 'primary',
            'php_version' => '8.3',
            'site_owner' => 'exampleuser',
            'root_path' => '/home/exampleuser/public_html',
        ]);
    }

    private function ai(bool $available = true): Mockery\MockInterface
    {
        $ai = Mockery::mock(PanelAi::class)->makePartial();
        $ai->shouldReceive('available')->andReturn($available);
        $this->app->instance(PanelAi::class, $ai);

        return $ai;
    }

    private function asAdmin(): static
    {
        return $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->withSession(['panel_session_token' => self::SESSION])
            ->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_cron_request_fills_schedule_and_uses_the_site_php(): void
    {
        $this->ai()->shouldReceive('askJson')->once()
            ->with(Mockery::any(), Mockery::on(fn (string $content) => str_contains($content, '/usr/bin/php8.3') && str_contains($content, 'every night at 2')), 'cron_suggest', Mockery::any(), Mockery::any())
            ->andReturn(['expression' => '0  2 * * *', 'name' => 'Nightly schedule', 'command' => '/usr/bin/php8.3 artisan schedule:run', 'explanation' => 'Runs at 02:00.']);

        $this->asAdmin()
            ->postJson(route('websites.cronjobs.ai', ['token' => self::SESSION, 'id' => 'site-1']), ['request' => 'every night at 2'])
            ->assertOk()
            ->assertJson(['ok' => true, 'expression' => '0 2 * * *', 'name' => 'Nightly schedule']);
    }

    public function test_an_invalid_ai_schedule_is_refused(): void
    {
        $this->ai()->shouldReceive('askJson')->andReturn(['expression' => '@daily; rm -rf /', 'name' => 'x']);

        $this->asAdmin()
            ->postJson(route('websites.cronjobs.ai', ['token' => self::SESSION, 'id' => 'site-1']), ['request' => 'daily'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_log_explanation_reads_the_log_on_the_server_and_applies_the_filter(): void
    {
        $this->mock(SiteLogClient::class)->shouldReceive('tail')->once()
            ->with('php', 'example.test', 'exampleuser', 200)
            ->andReturn(['exists' => true, 'lines' => ['PHP Fatal error: Allowed memory size exhausted in /index.php', 'PHP Notice: harmless']]);
        $this->ai()->shouldReceive('askJson')->once()
            ->with(Mockery::any(), Mockery::on(fn (string $content) => str_contains($content, 'memory size') && ! str_contains($content, 'harmless') && str_contains($content, 'Bangla')), 'site_log_explain', Mockery::any(), Mockery::any())
            ->andReturn(['summary' => 'Memory runs out.', 'actions' => [['type' => 'ban_ip', 'ip' => '203.0.113.9']]]);

        // A PHP error log never offers a ban.
        $this->asAdmin()
            ->postJson(route('websites.logs.explain', ['token' => self::SESSION, 'id' => 'site-1']), ['kind' => 'php', 'lines' => 200, 'filter' => 'fatal', 'language' => 'bn'])
            ->assertOk()
            ->assertJson(['ok' => true, 'text' => 'Memory runs out.', 'actions' => []]);
    }

    public function test_only_safe_ban_suggestions_reach_the_apply_button(): void
    {
        \App\Models\WebsiteIpRule::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'website_id' => 'site-1', 'rule_type' => 'allow', 'ip_address' => '198.51.100.7']);
        $this->mock(SiteLogClient::class)->shouldReceive('tail')->andReturn(['exists' => true, 'lines' => [
            '203.0.113.9 - - "GET /wp-login.php" 401',
            '198.51.100.7 - - "GET /.env" 404',
            '10.0.0.5 - - "GET /" 200',
            '127.0.0.1 - - "GET /xmlrpc.php" 403',
        ]]);
        $this->ai()->shouldReceive('askJson')->andReturn(['summary' => 'Brute force.', 'actions' => [
            ['type' => 'ban_ip', 'ip' => '203.0.113.9', 'reason' => 'login brute force'],
            ['type' => 'ban_ip', 'ip' => '203.0.113.9'],          // duplicate
            ['type' => 'ban_ip', 'ip' => '198.51.100.7'],         // whitelisted
            ['type' => 'ban_ip', 'ip' => '10.0.0.5'],             // private
            ['type' => 'ban_ip', 'ip' => '127.0.0.1'],            // the viewer, and loopback
            ['type' => 'ban_ip', 'ip' => '192.0.2.44'],           // not in the log
            ['type' => 'run_shell', 'command' => 'rm -rf /'],     // unknown action
        ]]);

        $this->asAdmin()
            ->postJson(route('websites.logs.explain', ['token' => self::SESSION, 'id' => 'site-1']), ['kind' => 'access'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'text' => 'Brute force.', 'actions' => [['type' => 'ban_ip', 'ip' => '203.0.113.9', 'reason' => 'login brute force']]]);
    }

    public function test_ai_endpoints_and_props_are_off_without_a_model(): void
    {
        $this->ai(false)->shouldNotReceive('askJson');

        $this->asAdmin()
            ->postJson(route('websites.logs.explain', ['token' => self::SESSION, 'id' => 'site-1']), ['kind' => 'access'])
            ->assertNotFound();

        $this->asAdmin()
            ->get(route('websites.cronjobs.index', ['token' => self::SESSION, 'id' => 'site-1']))
            ->assertInertia(fn ($page) => $page->where('aiAvailable', false));
    }
}
