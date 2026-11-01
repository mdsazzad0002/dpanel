<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\AiGatewayProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayLocalLlmTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aigateway.local_llm.url' => 'http://llm.test',
            'aigateway.local_llm.key' => 'llm-key',
        ]);
        $this->session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
    }

    private function fakeRunner(array $models): void
    {
        Http::fake([
            'llm.test/admin/status' => Http::response([
                'version' => '0.1.0',
                'loaded' => [],
                'memory' => ['total_bytes' => 8 << 30, 'available_bytes' => 4 << 30],
                'config' => ['ctx_size' => 8192, 'max_tokens' => 1024, 'threads' => 3, 'max_loaded' => 1, 'idle_unload_secs' => 900],
            ]),
            'llm.test/admin/models' => Http::response(['models' => $models, 'pulls' => []]),
            'llm.test/admin/models/pull' => Http::response(['error' => ['message' => 'url must be https']], 400),
            'llm.test/admin/models/*' => Http::response(['deleted' => true]),
        ]);
    }

    private function url(string $name, array $params = []): string
    {
        return route($name, ['token' => $this->session, ...$params]);
    }

    public function test_page_shows_runner_state_to_admins_only(): void
    {
        $this->fakeRunner([['id' => 'qwen', 'size_bytes' => 100, 'created' => 0, 'loaded' => false]]);

        $this->actingAs(User::factory()->create(['role' => 'reseller']))
            ->get($this->url('ai-gateway.local.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson($this->url('ai-gateway.local.state'))
            ->assertOk()
            ->assertJsonPath('online', true)
            ->assertJsonPath('models.0.id', 'qwen')
            ->assertJsonPath('provider', null);

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer llm-key'));
    }

    public function test_connect_creates_provider_and_syncs_models(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->fakeRunner([
            ['id' => 'qwen', 'size_bytes' => 100, 'created' => 0, 'loaded' => false],
            ['id' => 'llama', 'size_bytes' => 100, 'created' => 0, 'loaded' => false],
        ]);

        $this->actingAs($admin)
            ->postJson($this->url('ai-gateway.local.connect'))
            ->assertOk()
            ->assertJsonPath('provider.models', ['llama', 'qwen']);

        $provider = AiGatewayProvider::where('slug', 'local-llm')->sole();
        $this->assertSame('openai', $provider->driver);
        $this->assertSame('http://llm.test/v1', $provider->base_url);
        $this->assertSame('llm-key', $provider->getApiKey());
        $this->assertSame(8192, $provider->models()->where('name', 'qwen')->value('context_window'));
        $this->assertSame(['chat'], $provider->models()->where('name', 'qwen')->first()->capabilities);

        // Deleting a model drops it from the gateway as well.
        $this->actingAs($admin)
            ->deleteJson($this->url('ai-gateway.local.destroy', ['model' => 'qwen']))
            ->assertOk();
        $this->assertSame(['llama'], $provider->models()->pluck('name')->all());
    }

    public function test_runner_errors_are_shown_and_bad_input_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->fakeRunner([]);

        $this->actingAs($admin)
            ->postJson($this->url('ai-gateway.local.pull'), ['url' => 'http://insecure.test/a.gguf'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->actingAs($admin)
            ->postJson($this->url('ai-gateway.local.pull'), ['url' => 'https://example.test/a.gguf'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'url must be https');

        $this->actingAs($admin)
            ->postJson($this->url('ai-gateway.local.load', ['model' => '..']))
            ->assertNotFound();
    }

    public function test_page_explains_when_runner_is_not_installed(): void
    {
        config(['aigateway.local_llm.key' => '']);
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson($this->url('ai-gateway.local.state'))
            ->assertOk()
            ->assertJsonPath('online', false);

        Http::assertNothingSent();
    }
}
