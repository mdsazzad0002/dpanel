<?php

namespace Tests\Feature;

use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunHealthCheckToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        config([
            'mcp.local_config_path' => sys_get_temp_dir().'/mcp-local-missing-'.uniqid().'.conf',
            'dpanel.execution_api_base_url' => 'http://drust.test',
            'dpanel.execution_api_token' => 'secret',
        ]);
    }

    public function test_it_is_listed(): void
    {
        $names = collect($this->mcp($this->token(), 'tools/list')->assertOk()->json('result.tools'))->pluck('name');

        $this->assertContains('run_health_check', $names);
    }

    public function test_a_server_without_backups_fails_the_check(): void
    {
        $this->callTool($this->token(), ['checks' => ['backups']])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.overall', 'fail')
            ->assertJsonPath('result.structuredContent.checks.backups.status', 'fail')
            ->assertJsonPath('result.structuredContent.summary.0', fn ($line) => str_contains($line, 'No successful backup'));
    }

    public function test_sites_that_ran_out_of_php_workers_are_reported(): void
    {
        Http::fake(['drust.test/api/v1/php/workers*' => Http::response(['success' => true, 'data' => [
            'pools' => [
                ['owner' => 'shop', 'version' => '8.3', 'max_children' => 2, 'active' => 1, 'running' => 2, 'limit_hits_24h' => 6],
                ['owner' => 'blog', 'version' => '8.3', 'max_children' => 4, 'active' => 0, 'running' => 0, 'limit_hits_24h' => 0],
            ],
            'memory_available_kb' => 4 * 1024 * 1024,
            'average_worker_kb' => 50 * 1024,
        ]])]);

        $this->callTool($this->token(), ['checks' => ['php_workers']])
            ->assertJsonPath('result.structuredContent.checks.php_workers.status', 'warn')
            ->assertJsonPath('result.structuredContent.checks.php_workers.data.sites_out_of_workers_24h', 1)
            ->assertJsonPath('result.structuredContent.checks.php_workers.findings.0.message', fn ($line) => str_contains($line, 'shop (PHP 8.3) ran out of workers 6 time(s)'));
    }

    public function test_an_old_drust_skips_the_php_worker_check(): void
    {
        Http::fake(['drust.test/*' => Http::response('Not Found', 404)]);

        $this->callTool($this->token(), ['checks' => ['php_workers']])
            ->assertJsonPath('result.structuredContent.checks.php_workers.status', 'skipped')
            ->assertJsonPath('result.structuredContent.overall', 'ok');
    }

    public function test_speed_probes_a_static_file_the_framework_and_the_home_page(): void
    {
        Website::query()->create(['id' => 'site-1', 'domain' => 'shop.test', 'status' => 'live', 'type' => 'primary', 'php_version' => '8.3', 'ssl_mode' => 'none']);
        Website::query()->create(['id' => 'site-2', 'domain' => 'www.shop.test', 'status' => 'live', 'type' => 'alias', 'php_version' => '8.3']);
        Http::fake([
            'shop.test/build/app.css' => Http::response('body{}', 200, ['Cache-Control' => 'public, max-age=31536000']),
            'shop.test/__dpanel-health-*' => Http::response('missing', 404),
            'shop.test/' => Http::response('<html><link href="/build/app.css?v=1" rel="stylesheet"><script src="https://cdn.example/x.js"></script></html>', 200, [
                'Cache-Control' => 'no-cache, private',
                'Set-Cookie' => 'session=abc',
            ]),
        ]);

        $site = $this->callTool($this->token(), ['checks' => ['speed'], 'samples' => 1])
            ->assertJsonPath('result.isError', false)
            ->json('result.structuredContent.checks.speed.data.sites');

        $this->assertCount(1, $site, 'aliases are not tested');
        $this->assertSame('shop.test', $site[0]['domain']);
        $this->assertSame(200, $site[0]['status']);
        $this->assertFalse($site[0]['cacheable_for_guests']);
        Http::assertSent(fn ($request) => $request->url() === 'http://shop.test/build/app.css');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://shop.test/__dpanel-health-'));
    }

    public function test_a_site_that_cannot_be_reached_fails(): void
    {
        Website::query()->create(['id' => 'site-1', 'domain' => 'down.test', 'status' => 'live', 'type' => 'primary', 'php_version' => '8.3', 'ssl_mode' => 'none']);
        Http::fake(['down.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

        $this->callTool($this->token(), ['checks' => ['speed'], 'domains' => ['down.test']])
            ->assertJsonPath('result.structuredContent.checks.speed.status', 'fail')
            ->assertJsonPath('result.structuredContent.summary.0', fn ($line) => str_contains($line, 'down.test: The home page could not be fetched'));
    }

    public function test_unknown_checks_and_domains_are_errors(): void
    {
        $plain = $this->token();

        $this->callTool($plain, ['checks' => ['nope']])->assertJsonPath('result.isError', true);
        $this->callTool($plain, ['checks' => ['speed'], 'domains' => ['missing.test']])->assertJsonPath('result.isError', true);
    }

    private function token(): string
    {
        $user = User::factory()->create(['role' => 'admin']);
        $generated = McpAccessToken::generate();
        McpAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scope' => 'read',
        ]);

        return $generated['plain'];
    }

    private function mcp(string $plain, string $method, array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params]);
    }

    private function callTool(string $plain, array $arguments): \Illuminate\Testing\TestResponse
    {
        return $this->mcp($plain, 'tools/call', ['name' => 'run_health_check', 'arguments' => $arguments]);
    }
}
