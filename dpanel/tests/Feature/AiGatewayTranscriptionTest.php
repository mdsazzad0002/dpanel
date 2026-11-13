<?php

namespace Tests\Feature;

use App\Models\AiGatewayApiKey;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    /** The first bytes of an Ogg file, enough to be detected as audio. */
    private const OGG = "OggS\0\2\0\0\0\0\0\0\0\0\x01\0\0\0\0\0\0\0\0\0\0\0\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb\0\0\0\0\0";

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $generated = AiGatewayApiKey::generate();
        AiGatewayApiKey::query()->create(['name' => 'test', 'key_prefix' => $generated['prefix'], 'key_hash' => $generated['hash'], 'is_active' => true]);
        $this->headers = ['Authorization' => 'Bearer '.$generated['plain'], 'Accept' => 'application/json'];
    }

    public function test_transcription_models_only_get_transcription_requests(): void
    {
        $this->model('groq', 'llama-3.3-70b-versatile', ['chat']);
        $this->model('groq', 'whisper-large-v3-turbo', ['transcription']);
        $this->model('gemini', 'gemini-2.5-flash', ['chat', 'vision', 'transcription'], 'Gemini');

        $router = app(AiGatewayService::class)->router();

        $this->assertEqualsCanonicalizing(['llama-3.3-70b-versatile', 'gemini-2.5-flash'], $router->candidates()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['whisper-large-v3-turbo', 'gemini-2.5-flash'], $router->candidates(null, null, ['transcription'])->pluck('name')->all());
        $this->assertSame([], $router->candidates(null, null, ['image_generation'])->pluck('name')->all());
    }

    public function test_api_transcribes_with_whisper(): void
    {
        $this->model('groq', 'whisper-large-v3-turbo', ['transcription']);
        Http::fake(['api.groq.com/*' => Http::response(['text' => ' POS er dam koto? '])]);

        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio(), 'language' => 'bn', 'model' => 'auto'], $this->headers)
            ->assertOk()
            ->assertJson(['text' => 'POS er dam koto?', 'model' => 'whisper-large-v3-turbo', 'provider' => 'Main groq']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.groq.com/openai/v1/audio/transcriptions'
            && $request->isMultipart()
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'model' && $part['contents'] === 'whisper-large-v3-turbo')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'language' && $part['contents'] === 'bn')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'file' && $part['filename'] === 'voice.ogg'));

        // A new day: SQLite keeps the usage row's date with a time, so a second same-day row would clash.
        $this->travel(1)->days();
        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio(), 'response_format' => 'text'], $this->headers)
            ->assertOk()
            ->assertSee('POS er dam koto?');
    }

    public function test_gemini_listens_to_the_audio_and_failover_works(): void
    {
        $this->model('openai', 'whisper-1', ['transcription'])->provider->update(['weight' => 100]);
        $this->model('gemini', 'gemini-2.5-flash', ['chat', 'transcription'], 'Gemini');
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => "Hello there\n"]]]]]]),
        ]);

        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio()], $this->headers)
            ->assertOk()
            ->assertJsonPath('text', 'Hello there')
            ->assertJsonPath('model', 'gemini-2.5-flash');

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'gemini-2.5-flash:generateContent')
            && ($request['contents'][0]['parts'][0]['inline_data']['mime_type'] ?? null) === 'audio/ogg'
            && ($request['contents'][0]['parts'][0]['inline_data']['data'] ?? null) === base64_encode(self::OGG));
    }

    public function test_bad_requests_are_refused(): void
    {
        $this->model('groq', 'whisper-large-v3-turbo', ['transcription']);
        Http::fake();

        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio(), 'model' => 'whisper-1'], $this->headers)
            ->assertStatus(400)->assertJsonPath('error.code', 'unsupported_model');
        $this->post('/api/v1/audio/transcriptions', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')], $this->headers)
            ->assertStatus(400)->assertJsonPath('error.code', 'invalid_file');
        $this->post('/api/v1/audio/transcriptions', [], $this->headers)->assertUnprocessable();
        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio()], ['Accept' => 'application/json'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_drusts_whisper_is_the_voice_to_text_gateway_when_it_is_running(): void
    {
        config(['dpanel.media_transcribe_api_url' => 'http://drust.test/api/v1/media/transcribe', 'aigateway.local_transcription' => 'first']);
        Http::fake(['drust.test/*' => Http::response(['success' => true, 'message' => 'Transcribed', 'data' => ['text' => ' POS er dam koto? ']])]);

        // no transcription model at all: drust alone is enough
        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio(), 'language' => 'bn'], $this->headers)
            ->assertOk()
            ->assertJson(['text' => 'POS er dam koto?', 'model' => AiGatewayService::LOCAL_TRANSCRIPTION_MODEL, 'provider' => 'drust']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://drust.test/api/v1/media/transcribe'
            && $request['data'] === base64_encode(self::OGG) && $request['language'] === 'bn');
        $this->assertDatabaseHas('ai_gateway_request_logs', ['operation' => 'transcription', 'model' => AiGatewayService::LOCAL_TRANSCRIPTION_MODEL, 'status' => 'success']);
    }

    public function test_models_take_over_when_drust_cannot_transcribe(): void
    {
        config(['dpanel.media_transcribe_api_url' => 'http://drust.test/api/v1/media/transcribe', 'aigateway.local_transcription' => 'first']);
        $this->model('groq', 'whisper-large-v3-turbo', ['transcription']);
        Http::fake([
            'drust.test/*' => Http::response(['success' => false, 'message' => 'drust was built without speech support'], 500),
            'api.groq.com/*' => Http::response(['text' => 'Hello there']),
        ]);

        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio()], $this->headers)
            ->assertOk()->assertJson(['text' => 'Hello there', 'model' => 'whisper-large-v3-turbo']);
    }

    public function test_fallback_mode_asks_drust_only_after_the_models_fail(): void
    {
        config(['dpanel.media_transcribe_api_url' => 'http://drust.test/api/v1/media/transcribe', 'aigateway.local_transcription' => 'fallback']);
        $this->model('groq', 'whisper-large-v3-turbo', ['transcription']);
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
            'drust.test/*' => Http::response(['success' => true, 'data' => ['text' => 'from drust']]),
        ]);

        $this->post('/api/v1/audio/transcriptions', ['file' => $this->audio()], $this->headers)
            ->assertOk()->assertJsonPath('text', 'from drust');
        Http::assertSentInOrder([
            fn (HttpRequest $request) => str_contains($request->url(), 'api.groq.com'),
            fn (HttpRequest $request) => str_contains($request->url(), 'drust.test'),
        ]);
    }

    public function test_translations_turn_speech_into_english_with_drusts_whisper(): void
    {
        config(['dpanel.media_transcribe_api_url' => 'http://drust.test/api/v1/media/transcribe', 'aigateway.local_transcription' => 'first']);
        $this->model('groq', 'whisper-large-v3-turbo', ['transcription']);
        Http::fake(['drust.test/*' => Http::response(['success' => true, 'data' => ['text' => 'What is the price?']])]);

        $this->post('/api/v1/audio/translations', ['file' => $this->audio(), 'language' => 'bn'], $this->headers)
            ->assertOk()
            ->assertJson(['text' => 'What is the price?', 'provider' => 'drust']);

        Http::assertSent(fn (HttpRequest $request) => $request['translate'] === true && $request['language'] === 'bn');
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'groq'));
        $this->assertDatabaseHas('ai_gateway_request_logs', ['operation' => 'translation']);
    }

    public function test_translation_without_drust_says_why(): void
    {
        config(['aigateway.local_transcription' => 'off']);

        $this->post('/api/v1/audio/translations', ['file' => $this->audio()], $this->headers)
            ->assertStatus(502)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'Voice to Text'));
    }

    private function audio(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('voice.ogg', self::OGG);
    }

    private function model(string $driver, string $name, array $capabilities, string $providerName = 'Main'): AiGatewayModel
    {
        $provider = AiGatewayProvider::query()->firstOrCreate(
            ['name' => $providerName.' '.$driver],
            ['driver' => $driver, 'slug' => $providerName.'-'.$driver, 'credentials' => ['api_key' => 'test-key'], 'is_active' => true, 'weight' => 1],
        );

        return $provider->models()->create(['name' => $name, 'capabilities' => $capabilities, 'is_active' => true]);
    }
}
