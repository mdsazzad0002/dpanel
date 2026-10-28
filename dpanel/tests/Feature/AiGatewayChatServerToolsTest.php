<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
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
}
