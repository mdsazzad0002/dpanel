<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\AiGatewayApiKey;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Website;
use App\Services\AiGateway\AiGatewayService;
use App\Services\Mcp\GatewayChatTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiGatewayChatServerToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
    }

    public function test_model_calls_a_tool_and_gets_its_result(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Website::query()->create(['id' => 'site-1', 'domain' => 'example.com', 'status' => 'active', 'type' => 'primary', 'php_version' => '8.3']);

        $turns = [];
        $this->mock(AiGatewayService::class)
            ->shouldReceive('chatStreamAuto')
            ->twice()
            ->andReturnUsing(function (?string $model, array $messages, array $options, \Closure $onDelta) use (&$turns) {
                $turns[] = ['messages' => $messages, 'options' => $options];
                $result = ['input_tokens' => 10, 'output_tokens' => 5, 'model' => 'test-model', 'raw' => [], 'provider' => new \App\Models\AiGatewayProvider(['name' => 'Test'])];

                if (count($turns) === 1) {
                    $onDelta('Checking.');

                    return [...$result, 'content' => 'Checking.', 'tool_calls' => [
                        ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_websites', 'arguments' => '{"search":"example"}']],
                    ]];
                }

                $onDelta('example.com is active.');

                return [...$result, 'content' => 'example.com is active.'];
            });

        $body = $this->actingAs($admin)
            ->post(route('ai-gateway.chat.auto.stream', ['token' => $this->session]), [
                'messages' => [['role' => 'user', 'content' => 'Is example.com up?']],
                'tools' => 'read',
            ], ['Accept' => 'text/event-stream'])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('event: tool', $body);
        $this->assertStringContainsString('"status":"ok"', $body);
        $this->assertStringContainsString('"content":"Checking.\n\nexample.com is active."', $body);
        $this->assertStringContainsString('"input_tokens":20', $body);

        // Only tools a read-only token can use are offered, with Gemini-safe types.
        $offered = array_column(array_column($turns[0]['options']['tools'], 'function'), 'name');
        $this->assertContains('read_file', $offered);
        $this->assertContains('run_command', $offered);
        $this->assertNotContains('write_file', $offered);
        // A read-only token is told exactly which commands run_command accepts.
        $runCommand = collect($turns[0]['options']['tools'])->firstWhere('function.name', 'run_command');
        $this->assertStringContainsString('systemctl show', $runCommand['function']['description']);
        $readFile = collect($turns[0]['options']['tools'])->firstWhere('function.name', 'read_file');
        $this->assertSame('string', $readFile['function']['parameters']['properties']['server_id']['type']);

        // The second turn carries the call and its result back to the model.
        $toolMessage = collect($turns[1]['messages'])->firstWhere('role', 'tool');
        $this->assertSame('call_1', $toolMessage['tool_call_id']);
        $this->assertStringContainsString('example.com', $toolMessage['content']);

        $this->assertDatabaseHas('mcp_access_tokens', ['user_id' => $admin->id, 'name' => GatewayChatTools::tokenName('read'), 'scope' => 'read']);
    }

    public function test_tools_are_refused_for_non_admins_and_disabled_chat_tokens(): void
    {
        $this->mock(AiGatewayService::class)->shouldNotReceive('chatStreamAuto');

        $reseller = User::factory()->create(['role' => 'reseller']);
        $body = $this->actingAs($reseller)
            ->post(route('ai-gateway.chat.auto.stream', ['token' => $this->session]), [
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => 'full',
            ], ['Accept' => 'text/event-stream'])
            ->streamedContent();
        $this->assertStringContainsString('only available to admins', $body);

        $admin = User::factory()->create(['role' => 'admin']);
        McpAccessToken::query()->create([
            'user_id' => $admin->id,
            'name' => GatewayChatTools::tokenName('full'),
            'token_prefix' => 'panel chat',
            'token_hash' => hash('sha256', 'x'),
            'scope' => 'full',
            'is_active' => false,
        ]);

        $body = $this->actingAs($admin)
            ->post(route('ai-gateway.chat.auto.stream', ['token' => $this->session]), [
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => 'full',
            ], ['Accept' => 'text/event-stream'])
            ->streamedContent();
        $this->assertStringContainsString('turned off under Settings', $body);
    }

    public function test_api_streams_each_server_tool_step(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Website::query()->create(['id' => 'site-1', 'domain' => 'example.com', 'status' => 'active', 'type' => 'primary', 'php_version' => '8.3']);
        $headers = $this->apiKeyHeaders($admin);

        $turn = 0;
        $this->mock(AiGatewayService::class)
            ->shouldReceive('chatStreamAuto')
            ->twice()
            ->andReturnUsing(function (?string $model, array $messages, array $options, \Closure $onDelta) use (&$turn) {
                $result = ['input_tokens' => 1, 'output_tokens' => 1, 'model' => 'test-model', 'raw' => [], 'provider' => new \App\Models\AiGatewayProvider(['name' => 'Test'])];
                if (++$turn === 1) {
                    return [...$result, 'content' => '', 'tool_calls' => [
                        ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_websites', 'arguments' => '{}']],
                    ]];
                }
                $onDelta('example.com is active.');

                return [...$result, 'content' => 'example.com is active.'];
            });

        $body = $this->post('/api/v1/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'Is example.com up?']],
            'stream' => true,
            'server_tools' => 'read',
        ], $headers)->assertOk()->streamedContent();

        $this->assertStringContainsString('"dpanel_tool_step":{"id":"call_1","name":"list_websites","arguments":{},"status":"running"}', $body);
        $this->assertMatchesRegularExpression('/"dpanel_tool_step":\{"id":"call_1","name":"list_websites","status":"ok".*"duration_ms":\d+/', $body);
        $this->assertStringContainsString('example.com is active.', $body);
        $this->assertStringEndsWith("data: [DONE]\n\n", $body);
    }

    public function test_api_server_tools_need_an_admin_key_and_no_own_tools(): void
    {
        $this->mock(AiGatewayService::class)->shouldNotReceive('chatAuto', 'chatStreamAuto');

        $this->postJson('/api/v1/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'server_tools' => 'read',
        ], $this->apiKeyHeaders(User::factory()->create(['role' => 'reseller'])))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'server_tools_unavailable');

        $this->postJson('/api/v1/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'server_tools' => 'read',
            'tools' => [['type' => 'function', 'function' => ['name' => 'mine']]],
        ], $this->apiKeyHeaders(User::factory()->create(['role' => 'admin'])))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'server_tools_unavailable');

        $this->postJson('/api/v1/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'server_tools' => 'full',
        ], $this->apiKeyHeaders(User::factory()->create(['role' => 'admin'])))
            ->assertStatus(422);
    }

    private function apiKeyHeaders(User $creator): array
    {
        $generated = AiGatewayApiKey::generate();
        AiGatewayApiKey::query()->create(['name' => 'test', 'key_prefix' => $generated['prefix'], 'key_hash' => $generated['hash'], 'is_active' => true, 'created_by' => $creator->id]);

        return ['Authorization' => 'Bearer '.$generated['plain'], 'Accept' => 'application/json'];
    }
}
