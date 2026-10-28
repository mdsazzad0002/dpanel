<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\AiGatewayApiKey;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\User;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\ChatHistoryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiGatewayImagesTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['serverpanel.media_ocr_api_url' => 'http://drust.test/api/v1/media/ocr']);
        $this->session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
    }

    public function test_vision_model_gets_the_image_and_no_ocr_runs(): void
    {
        $this->model('openai', 'gpt-4o', ['chat', 'vision']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiReply('a red pixel'))]);

        $result = app(AiGatewayService::class)->chatAuto(null, [
            ['role' => 'user', 'content' => 'What is this?', 'images' => [['mime' => 'image/png', 'data' => self::PNG]]],
        ]);

        $this->assertSame('a red pixel', $result['content']);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'drust.test'));
        Http::assertSent(function (HttpRequest $request) {
            $content = $request['messages'][0]['content'] ?? null;

            return is_array($content)
                && $content[0] === ['type' => 'text', 'text' => 'What is this?']
                && $content[1]['image_url']['url'] === 'data:image/png;base64,'.self::PNG;
        });
    }

    public function test_model_without_vision_gets_ocr_text_instead(): void
    {
        $this->model('openai', 'gpt-3.5', ['chat']);
        Http::fake([
            'drust.test/*' => Http::response(['success' => true, 'data' => ['text' => 'INVOICE 42']]),
            'api.openai.com/*' => Http::response($this->openAiReply('ok')),
        ]);

        app(AiGatewayService::class)->chatAuto(null, [
            ['role' => 'user', 'content' => 'Read this', 'images' => [['mime' => 'image/png', 'data' => self::PNG]]],
        ]);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'drust.test') && $request['data'] === self::PNG);
        Http::assertSent(function (HttpRequest $request) {
            $content = $request['messages'][0]['content'] ?? null;

            return str_contains($request->url(), 'openai') && is_string($content)
                && str_contains($content, 'Read this') && str_contains($content, 'INVOICE 42');
        });
    }

    public function test_anthropic_and_gemini_get_images_in_their_own_format(): void
    {
        $this->model('anthropic', 'claude-sonnet-4', ['chat', 'vision']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);

        app(AiGatewayService::class)->chatAuto(null, [['role' => 'user', 'content' => 'hi', 'images' => [['mime' => 'image/png', 'data' => self::PNG]]]]);

        Http::assertSent(fn (HttpRequest $request) => ($request['messages'][0]['content'][0]['source'] ?? null) === ['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG]);

        AiGatewayModel::query()->delete();
        $this->model('gemini', 'gemini-2.5-flash', ['chat', 'vision']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])]);

        app(AiGatewayService::class)->chatAuto(null, [['role' => 'user', 'content' => 'hi', 'images' => [['mime' => 'image/png', 'data' => self::PNG]]]]);

        Http::assertSent(fn (HttpRequest $request) => ($request['contents'][0]['parts'][0]['inline_data'] ?? null) === ['mime_type' => 'image/png', 'data' => self::PNG]);
    }

    public function test_image_models_only_get_image_requests(): void
    {
        $this->model('openai', 'gpt-4o', ['chat', 'vision']);
        $this->model('openai', 'gpt-image-1', ['image_generation'], 'Images');

        $router = app(AiGatewayService::class)->router();

        $this->assertSame(['gpt-4o'], $router->candidates()->pluck('name')->all());
        $this->assertSame(['gpt-image-1'], $router->candidates(null, null, ['image_generation'])->pluck('name')->all());
    }

    public function test_panel_upload_chat_with_image_generate_and_history(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->model('openai', 'gpt-4o', ['chat', 'vision']);
        $this->model('openai', 'gpt-image-1', ['image_generation'], 'Images');
        Http::fake([
            'api.openai.com/v1/images/*' => Http::response(['data' => [['b64_json' => self::PNG]]]),
            'api.openai.com/*' => Http::response("data: {\"choices\":[{\"delta\":{\"content\":\"I see a pixel\"}}]}\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $upload = $this->actingAs($user)
            ->post(route('ai-gateway.media.upload', ['token' => $this->session]), ['image' => UploadedFile::fake()->createWithContent('pixel.png', base64_decode(self::PNG))])
            ->assertOk()
            ->json();

        $this->actingAs($user)
            ->get(route('ai-gateway.media.show', ['token' => $this->session, 'media' => $upload['id']]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $body = $this->actingAs($user)
            ->post(route('ai-gateway.chat.auto.stream', ['token' => $this->session]), [
                'messages' => [['role' => 'user', 'content' => '', 'images' => [$upload['id']]]],
            ], ['Accept' => 'text/event-stream'])
            ->streamedContent();
        $this->assertStringContainsString('I see a pixel', $body);
        Http::assertSent(fn (HttpRequest $request) => is_array($request['messages'][1]['content'] ?? null));

        $generated = $this->actingAs($user)
            ->postJson(route('ai-gateway.images.generate', ['token' => $this->session]), ['prompt' => 'a pixel'])
            ->assertOk()
            ->assertJsonPath('model', 'gpt-image-1')
            ->json('images.0.id');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/images/generations') && $request['prompt'] === 'a pixel');

        // Another user cannot open the images; history keeps them.
        $other = User::factory()->create(['role' => 'reseller']);
        $this->actingAs($other)
            ->get(route('ai-gateway.media.show', ['token' => $this->session, 'media' => $generated, 'owner' => $user->id]))
            ->assertForbidden();

        $store = app(ChatHistoryStore::class);
        $saved = $store->save(0, $user->id, null, [
            ['role' => 'user', 'content' => 'look', 'images' => [$upload['id']]],
            ['role' => 'assistant', 'content' => 'Here is the generated image.', 'images' => [$generated]],
        ]);
        $this->assertSame([
            ['role' => 'user', 'content' => 'look', 'images' => [$upload['id']]],
            ['role' => 'assistant', 'content' => 'Here is the generated image.', 'images' => [$generated]],
        ], $store->load(0, $user->id, $saved['id'])['messages']);
    }

    public function test_api_accepts_image_parts_and_generates_images(): void
    {
        $this->model('openai', 'gpt-4o', ['chat', 'vision']);
        $this->model('gemini', 'gemini-2.5-flash-image', ['image_generation'], 'Gemini');
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAiReply('pixel')),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [
                ['text' => 'Here you go'],
                ['inlineData' => ['mimeType' => 'image/png', 'data' => self::PNG]],
            ]]]]]),
        ]);

        $generated = AiGatewayApiKey::generate();
        AiGatewayApiKey::query()->create(['name' => 'test', 'key_prefix' => $generated['prefix'], 'key_hash' => $generated['hash'], 'is_active' => true]);
        $headers = ['Authorization' => 'Bearer '.$generated['plain']];

        $this->postJson('/api/v1/chat/completions', ['messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'what?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::PNG]],
        ]]]], $headers)->assertOk()->assertJsonPath('choices.0.message.content', 'pixel');

        $this->postJson('/api/v1/chat/completions', ['messages' => [['role' => 'user', 'content' => [
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/cat.png']],
        ]]]], $headers)->assertStatus(400)->assertJsonPath('error.code', 'invalid_content');

        $this->postJson('/api/v1/images/generations', ['prompt' => 'a pixel'], $headers)
            ->assertOk()
            ->assertJsonPath('model', 'gemini-2.5-flash-image')
            ->assertJsonPath('data.0.b64_json', self::PNG)
            ->assertJsonPath('data.0.revised_prompt', 'Here you go');
        Http::assertSent(fn (HttpRequest $request) => ($request['generationConfig']['responseModalities'] ?? null) === ['TEXT', 'IMAGE']);
    }

    private function model(string $driver, string $name, array $capabilities, string $providerName = 'Main'): AiGatewayModel
    {
        $provider = AiGatewayProvider::query()->firstOrCreate(
            ['name' => $providerName.' '.$driver],
            ['driver' => $driver, 'slug' => $providerName.'-'.$driver, 'credentials' => ['api_key' => 'test-key'], 'is_active' => true, 'weight' => 1],
        );

        return $provider->models()->create(['name' => $name, 'capabilities' => $capabilities, 'is_active' => true]);
    }

    private function openAiReply(string $content): array
    {
        return ['model' => 'gpt', 'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]];
    }
}
