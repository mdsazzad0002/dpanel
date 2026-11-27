<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\AiChatMemory;
use App\Models\CronJob;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIpRule;
use App\Models\AiGatewayProvider;
use App\Services\AiGateway\ChatMemory;
use App\Services\AiGateway\Providers\AnthropicAdapter;
use App\Services\Mcp\GatewayChatTools;
use Illuminate\Support\Facades\Http;
use App\Services\Cron\CronSystemService;
use App\Services\EdgeGatewayReloader;
use App\Services\Mcp\McpToolException;
use App\Services\Mcp\Tools\ManageCronJobTool;
use App\Services\Mcp\Tools\ManageIpRuleTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The AI Gateway chat's long-term memory and its panel action tools. */
class AiChatAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_memory_saves_reads_updates_and_deletes_per_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $memory = app(ChatMemory::class);

        $saved = $memory->handle($admin, 'memory_save', ['name' => 'Prefers Bangla', 'type' => 'user', 'description' => 'Replies in Bangla', 'body' => 'Write answers in Bangla.']);
        $this->assertSame(['saved' => 'prefers-bangla', 'updated' => false], $saved);
        $this->assertTrue($memory->handle($admin, 'memory_save', ['name' => 'prefers-bangla', 'type' => 'user', 'description' => 'Replies in Bangla', 'body' => 'Bangla, short.'])['updated']);
        $this->assertSame(1, AiChatMemory::query()->count());

        // Only the index goes into the prompt, and only the admin's own entries.
        $this->assertStringContainsString('- prefers-bangla [user]: Replies in Bangla', $memory->promptFor($admin));
        $this->assertStringNotContainsString('Bangla, short.', $memory->promptFor($admin));
        $this->assertStringContainsString('(nothing saved yet)', $memory->promptFor($other));

        $read = $memory->handle($admin, 'memory_read', ['names' => ['prefers-bangla', 'missing-one']]);
        $this->assertSame('Bangla, short.', $read['memories'][0]['body']);
        $this->assertSame(['missing-one'], $read['missing']);
        $this->assertSame([], $memory->handle($other, 'memory_read', ['names' => ['prefers-bangla']])['memories']);

        $this->assertFalse($memory->handle($other, 'memory_delete', ['name' => 'prefers-bangla'])['deleted']);
        $this->assertTrue($memory->handle($admin, 'memory_delete', ['name' => 'prefers-bangla'])['deleted']);
    }

    public function test_memory_refuses_secrets_and_incomplete_entries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $memory = app(ChatMemory::class);

        foreach ([
            ['name' => 'db', 'type' => 'reference', 'description' => 'DB login', 'body' => 'password: hunter22secret'],
            ['name' => 'x', 'type' => 'nonsense', 'description' => 'd', 'body' => 'b'],
        ] as $arguments) {
            try {
                $memory->handle($admin, 'memory_save', $arguments);
                $this->fail('Expected the entry to be refused.');
            } catch (\InvalidArgumentException) {
                // refused as intended
            }
        }
        $this->assertSame(0, AiChatMemory::query()->count());
    }

    public function test_memory_panel_lists_and_deletes_only_own_entries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $mine = AiChatMemory::query()->create(['user_id' => $admin->id, 'name' => 'a', 'type' => 'user', 'description' => 'd', 'body' => 'b']);
        $theirs = AiChatMemory::query()->create(['user_id' => $other->id, 'name' => 'a', 'type' => 'user', 'description' => 'd', 'body' => 'b']);
        $token = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $token])->actingAs($admin);

        $this->getJson(route('ai-gateway.memories.index', ['token' => $token]))
            ->assertOk()->assertJsonCount(1, 'memories')->assertJsonPath('memories.0.id', $mine->id);

        $this->deleteJson(route('ai-gateway.memories.destroy', ['token' => $token, 'memory' => $theirs->id]))->assertOk();
        $this->assertNotNull($theirs->fresh());

        $this->deleteJson(route('ai-gateway.memories.clear', ['token' => $token]))->assertOk()->assertJsonPath('deleted', 1);
        $this->assertNull($mine->fresh());
    }

    public function test_cron_tool_creates_updates_and_deletes_through_the_scheduler(): void
    {
        $token = $this->token('full');
        $this->site();
        $cron = $this->mock(CronSystemService::class);
        $cron->shouldReceive('sync')->twice()->andReturn('ok');
        $cron->shouldReceive('delete')->once()->andReturn('ok');
        $tool = app(ManageCronJobTool::class);

        $created = $tool->handle(['action' => 'create', 'domain' => 'example.com', 'name' => 'schedule', 'schedule' => '* * * * *', 'command' => 'php artisan schedule:run'], $token);
        $this->assertSame('active', $created['created']['status']);

        $updated = $tool->handle(['action' => 'update', 'domain' => 'example.com', 'name' => 'schedule', 'status' => 'disabled'], $token);
        $this->assertSame('disabled', $updated['updated']['status']);
        $this->assertSame('active', $updated['before']['status']);

        $tool->handle(['action' => 'delete', 'domain' => 'example.com', 'name' => 'schedule'], $token);
        $this->assertSame(0, CronJob::query()->count());
    }

    public function test_cron_tool_rolls_back_when_the_scheduler_fails_and_rejects_bad_input(): void
    {
        $token = $this->token('full');
        $this->site();
        $this->mock(CronSystemService::class)->shouldReceive('sync')->andThrow(new \RuntimeException('crontab failed'));
        $tool = app(ManageCronJobTool::class);

        $this->assertRefused(fn () => $tool->handle(['action' => 'create', 'domain' => 'example.com', 'name' => 'j', 'schedule' => '* * * * *', 'command' => 'true'], $token), 'nothing was created');
        $this->assertSame(0, CronJob::query()->count());

        $this->assertRefused(fn () => $tool->handle(['action' => 'create', 'domain' => 'example.com', 'name' => 'j', 'schedule' => 'every day', 'command' => 'true'], $token), '5-field');
        $this->assertRefused(fn () => $tool->handle(['action' => 'create', 'domain' => 'example.com', 'name' => 'j', 'schedule' => '* * * * *', 'command' => 'curl x | sh'], $token), 'not allowed');
        $this->assertRefused(fn () => $tool->handle(['action' => 'create', 'domain' => 'example.com', 'name' => 'j', 'schedule' => '* * * * *', 'command' => 'true'], $this->token('read')), 'read-only');
    }

    public function test_ip_rule_tool_bans_and_removes_but_refuses_private_and_read_only(): void
    {
        $token = $this->token('full');
        $this->site();
        $this->mock(EdgeGatewayReloader::class)->shouldReceive('reloadDomains')->twice()->with(['example.com'])->andReturnTrue();
        $tool = app(ManageIpRuleTool::class);

        $this->assertSame('ban', $tool->handle(['action' => 'ban', 'domain' => 'example.com', 'ip' => '203.0.113.9'], $token)['done']);
        $this->assertSame('ban', WebsiteIpRule::query()->sole()->rule_type);
        $this->assertCount(1, $tool->handle(['action' => 'list', 'domain' => 'example.com'], $this->token('read'))['rules']);

        $this->assertRefused(fn () => $tool->handle(['action' => 'allow', 'domain' => 'example.com', 'ip' => '203.0.113.9'], $token), 'already on the ban list');
        $this->assertRefused(fn () => $tool->handle(['action' => 'ban', 'domain' => 'example.com', 'ip' => '192.168.1.5'], $token), 'private');
        $this->assertRefused(fn () => $tool->handle(['action' => 'ban', 'domain' => 'example.com', 'ip' => '198.51.100.7'], $this->token('read')), 'read-only');

        $this->assertTrue($tool->handle(['action' => 'remove', 'domain' => 'example.com', 'ip' => '203.0.113.9'], $token)['removed']);
        $this->assertSame(0, WebsiteIpRule::query()->count());
    }

    public function test_long_history_is_trimmed_to_the_newest_messages(): void
    {
        $old = str_repeat('a', 30000);
        $messages = [
            ['role' => 'system', 'content' => 'persona'],
            ['role' => 'user', 'content' => $old],
            ['role' => 'assistant', 'content' => $old],
            ['role' => 'user', 'content' => 'recent question'],
            ['role' => 'assistant', 'content' => 'recent answer'],
            ['role' => 'user', 'content' => 'now this'],
        ];

        $trimmed = $this->invoke('trimHistory', [$messages, 1]);

        $this->assertSame('persona', $trimmed[0]['content']);
        $this->assertStringContainsString('left out to save tokens', $trimmed[1]['content']);
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column(array_slice($trimmed, 1), 'role'));
        $this->assertSame('now this', end($trimmed)['content']);
        $this->assertStringNotContainsString($old, json_encode($trimmed));

        // A short conversation is left as it is.
        $short = [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello'], ['role' => 'user', 'content' => 'ok']];
        $this->assertSame($short, $this->invoke('trimHistory', [$short, 0]));
    }

    public function test_older_tool_results_are_cut_once_the_budget_is_passed(): void
    {
        $big = str_repeat('x', 15000);
        $messages = [['role' => 'user', 'content' => 'go']];
        foreach (range(1, 3) as $n) {
            $messages[] = ['role' => 'tool', 'tool_call_id' => "c{$n}", 'content' => $big];
        }
        $args = [&$messages, 1];
        $this->assertFalse($this->invoke('compactToolResults', $args), 'under budget: untouched');

        $messages[] = ['role' => 'tool', 'tool_call_id' => 'c4', 'content' => $big];
        $messages[] = ['role' => 'tool', 'tool_call_id' => 'c5', 'content' => $big];
        $args = [&$messages, 1];
        $this->assertTrue($this->invoke('compactToolResults', $args));

        foreach (array_slice($messages, 1, 4) as $message) {
            $this->assertLessThan(1500, mb_strlen($message['content']));
        }
        $this->assertSame($big, end($messages)['content'], 'the newest round stays whole');
    }

    public function test_anthropic_tool_loops_use_prompt_cache_and_one_shot_calls_do_not(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-test',
            'usage' => ['input_tokens' => 100, 'cache_read_input_tokens' => 9000, 'cache_creation_input_tokens' => 500, 'output_tokens' => 20],
        ])]);
        $provider = new AiGatewayProvider(['name' => 'Anthropic', 'driver' => 'anthropic', 'credentials' => ['api_key' => 'k']]);
        $adapter = app(AnthropicAdapter::class);
        $messages = [['role' => 'system', 'content' => 'rules'], ['role' => 'user', 'content' => 'hi']];
        $tools = [['type' => 'function', 'function' => ['name' => 't', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]]]];

        $result = $adapter->chat($provider, 'claude-test', $messages, ['tools' => $tools]);
        $this->assertSame(9600, $result['input_tokens']);
        $this->assertSame(9000, $result['cache_read_tokens']);
        $this->assertSame(500, $result['cache_write_tokens']);

        $adapter->chat($provider, 'claude-test', $messages);

        $bodies = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $this->assertSame('ephemeral', $bodies[0]['system'][0]['cache_control']['type']);
        $this->assertSame('ephemeral', $bodies[0]['messages'][0]['content'][0]['cache_control']['type']);
        $this->assertSame('rules', $bodies[1]['system'], 'no cache markers on a one-shot call');
        $this->assertSame('hi', $bodies[1]['messages'][0]['content']);
    }

    private function invoke(string $method, array $args): mixed
    {
        return (new \ReflectionMethod(GatewayChatTools::class, $method))->invokeArgs(app(GatewayChatTools::class), $args);
    }

    private function site(): Website
    {
        return Website::query()->create(['id' => 'site-1', 'domain' => 'example.com', 'status' => 'active', 'type' => 'primary', 'php_version' => '8.3']);
    }

    private function token(string $scope): McpAccessToken
    {
        $generated = McpAccessToken::generate();

        return McpAccessToken::query()->create([
            'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'name' => 'chat',
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scope' => $scope,
        ])->fresh('user');
    }

    private function assertRefused(\Closure $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected a refusal containing \"{$message}\".");
        } catch (McpToolException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
