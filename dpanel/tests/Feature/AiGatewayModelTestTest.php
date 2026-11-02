<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayModelTestTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
    }

    private function url(AiGatewayModel $model): string
    {
        return route('ai-gateway.models.test', ['token' => $this->session, 'model' => $model->id]);
    }

    private function model(string $name, array $capabilities = ['chat']): AiGatewayModel
    {
        $provider = AiGatewayProvider::query()->firstOrCreate(
            ['name' => 'Groq'],
            ['driver' => 'groq', 'slug' => 'groq', 'credentials' => ['api_key' => 'test-key'], 'is_active' => true, 'weight' => 1],
        );

        return $provider->models()->create(['name' => $name, 'capabilities' => $capabilities, 'is_active' => true]);
    }

    /** An OpenAI-style reply from Groq. */
    private function reply(string $content, array $toolCalls = []): array
    {
        return [
            'model' => 'm',
            'choices' => [['message' => array_filter(['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls]), 'finish_reason' => $toolCalls ? 'tool_calls' : 'stop']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 9],
        ];
    }

    public function test_probes_set_the_capabilities_the_model_really_has(): void
    {
        // Was tagged vision by hand and lacked code/tools; image generation
        // isn't probed and must survive.
        $model = $this->model('llama-3.3-70b-versatile', ['chat', 'vision', 'image_generation']);
        Http::fake(function (HttpRequest $request) {
            $body = json_encode($request->data());
            return match (true) {
                str_contains($body, 'get_weather') => Http::response($this->reply('', [[
                    'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Dhaka"}'],
                ]])),
                str_contains($body, 'range(4)') => Http::response($this->reply('14')),
                str_contains($body, 'colour') => Http::response($this->reply('I cannot see images.')),
                default => Http::response($this->reply('Hello! I can help with your websites.')),
            };
        });

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson($this->url($model))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Hello! I can help with your websites.')
            ->assertJsonPath('probes.code.ok', true)
            ->assertJsonPath('probes.tools.ok', true)
            ->assertJsonPath('probes.vision.ok', false);

        $this->assertSame(['chat', 'code', 'tools', 'image_generation'], $model->fresh()->capabilities);

        // Straight to this model, and the picture was really sent.
        Http::assertSent(fn (HttpRequest $request) => $request['model'] === 'llama-3.3-70b-versatile'
            && str_contains(json_encode($request->data(), JSON_UNESCAPED_SLASHES), 'data:image/png;base64,'));

        // The page shows the last result after a reload.
        $this->actingAs($admin)->get(route('ai-gateway.models.index', ['token' => $this->session]))
            ->assertInertia(fn ($page) => $page->where('models.0.last_test.ok', true)->where('models.0.testable', true));
    }

    public function test_a_failing_model_reports_the_provider_error(): void
    {
        $model = $this->model('retired-model');
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'The model `retired-model` does not exist']], 404)]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson($this->url($model))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJson(fn ($json) => $json->where('message', fn ($message) => str_contains($message, 'does not exist'))->etc());

        // A model that can't answer at all keeps its capabilities.
        $this->assertSame(['chat'], $model->fresh()->capabilities);
    }

    public function test_image_only_models_are_not_chat_tested(): void
    {
        $model = $this->model('gpt-image-1', ['image_generation']);
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson($this->url($model))
            ->assertUnprocessable();

        Http::assertNothingSent();
    }
}
