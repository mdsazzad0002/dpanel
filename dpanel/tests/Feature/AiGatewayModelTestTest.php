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

    public function test_a_working_model_reports_its_reply_and_speed(): void
    {
        $model = $this->model('llama-3.1-8b-instant');
        Http::fake(['api.groq.com/*' => Http::response([
            'model' => 'llama-3.1-8b-instant',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello! I can help with your websites.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 9],
        ])]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson($this->url($model))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Hello! I can help with your websites.')
            ->assertJsonPath('output_tokens', 9);

        // Straight to this model, not routed elsewhere.
        Http::assertSent(fn (HttpRequest $request) => $request['model'] === 'llama-3.1-8b-instant');

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
