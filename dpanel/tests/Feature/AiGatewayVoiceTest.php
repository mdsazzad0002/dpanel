<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayVoiceTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    private const STATUS = [
        'speech_built' => true,
        'models' => [['file' => 'ggml-base-q5_1.bin', 'size_mb' => 56], ['file' => 'ggml-small-q5_1.bin', 'size_mb' => 181]],
        'model' => 'ggml-base-q5_1.bin',
        'model_from' => 'panel',
        'loaded' => 'ggml-base-q5_1.bin',
        'threads' => 8,
        'threads_setting' => null,
        'cpus' => 8,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'drust-token']);
        $this->session = str_repeat('a', 64);
        $this->withoutMiddleware(EnsurePanelSessionIsValid::class)->withSession(['panel_session_token' => $this->session]);
    }

    private function url(string $name): string
    {
        return route($name, ['token' => $this->session]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_page_shows_whisper_status_to_admins_only(): void
    {
        Http::fake(['drust.test/api/v1/media/whisper' => Http::response(['success' => true, 'data' => self::STATUS])]);

        $this->actingAs(User::factory()->create(['role' => 'reseller']))
            ->get($this->url('ai-gateway.voice.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get($this->url('ai-gateway.voice.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('AiGateway/Voice/Index')
                ->where('status.reachable', true)
                ->where('status.model', 'ggml-base-q5_1.bin')
                ->where('status.cpus', 8));

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer drust-token'));
    }

    public function test_saving_a_model_and_threads_restarts_whisper(): void
    {
        Http::fake([
            'drust.test/api/v1/media/whisper/restart' => Http::response(['success' => true, 'data' => ['model' => 'ggml-small-q5_1.bin', 'threads' => 4, 'load_ms' => 600] + self::STATUS]),
            'drust.test/api/v1/media/whisper' => Http::response(['success' => true, 'data' => self::STATUS]),
        ]);

        $this->actingAs($this->admin())
            ->postJson($this->url('ai-gateway.voice.save'), ['model' => 'ggml-small-q5_1.bin', 'threads' => 4])
            ->assertOk()
            ->assertJsonPath('status.load_ms', 600);

        Http::assertSentInOrder([
            fn (HttpRequest $request) => $request->url() === 'http://drust.test/api/v1/media/whisper'
                && $request['model'] === 'ggml-small-q5_1.bin' && $request['threads'] === 4,
            fn (HttpRequest $request) => $request->url() === 'http://drust.test/api/v1/media/whisper/restart',
        ]);

        // only a bare model file name gets through
        $this->actingAs($this->admin())
            ->postJson($this->url('ai-gateway.voice.save'), ['model' => '../../etc/passwd'])
            ->assertUnprocessable();
    }

    public function test_the_test_form_transcribes_or_translates_a_recording(): void
    {
        Http::fake([
            'drust.test/api/v1/media/transcribe' => Http::response(['success' => true, 'data' => ['text' => 'What is the price?', 'ms' => 4200]]),
            'drust.test/api/v1/media/whisper' => Http::response(['success' => true, 'data' => self::STATUS]),
        ]);
        $file = UploadedFile::fake()->createWithContent('recording.webm', 'webm-audio');

        $this->actingAs($this->admin())
            ->post($this->url('ai-gateway.voice.test'), ['file' => $file, 'language' => 'bn', 'translate' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('text', 'What is the price?')
            ->assertJsonPath('ms', 4200);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://drust.test/api/v1/media/transcribe'
            && $request['data'] === base64_encode('webm-audio')
            && $request['language'] === 'bn'
            && $request['translate'] === true);
    }

    public function test_a_drust_error_is_shown(): void
    {
        Http::fake([
            'drust.test/api/v1/media/transcribe' => Http::response(['success' => false, 'message' => 'ffmpeg could not decode this audio file']),
            'drust.test/api/v1/media/whisper' => Http::response(['success' => true, 'data' => self::STATUS]),
        ]);

        $this->actingAs($this->admin())
            ->post($this->url('ai-gateway.voice.test'), ['file' => UploadedFile::fake()->createWithContent('x.ogg', 'x')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ffmpeg could not decode this audio file');
    }

    public function test_local_whisper_can_be_switched_off_so_live_models_transcribe(): void
    {
        config(['dpanel.media_transcribe_api_url' => 'http://drust.test/api/v1/media/transcribe', 'aigateway.local_transcription' => 'first']);
        $provider = \App\Models\AiGatewayProvider::query()->create(['name' => 'Main groq', 'driver' => 'groq', 'slug' => 'main-groq', 'credentials' => ['api_key' => 'k'], 'is_active' => true, 'weight' => 1]);
        $provider->models()->create(['name' => 'whisper-large-v3-turbo', 'capabilities' => ['transcription'], 'is_active' => true]);
        Http::fake([
            'drust.test/*' => Http::response(['success' => true, 'data' => ['text' => 'from drust']]),
            'api.groq.com/*' => Http::response(['text' => 'from groq']),
        ]);
        $gateway = app(\App\Services\AiGateway\AiGatewayService::class);

        $this->assertSame('from drust', $gateway->transcribe('OggS', 'a.ogg', 'audio/ogg')['text']);

        $this->actingAs($this->admin())->postJson($this->url('ai-gateway.voice.mode'), ['mode' => 'off'])
            ->assertOk()->assertJsonPath('mode', 'off');
        $this->travel(1)->days(); // a new usage row date for SQLite
        $this->assertSame('from groq', $gateway->transcribe('OggS', 'a.ogg', 'audio/ogg')['text']);

        $this->actingAs($this->admin())->postJson($this->url('ai-gateway.voice.mode'), ['mode' => 'sometimes'])->assertUnprocessable();
    }

    public function test_models_from_the_catalog_can_be_downloaded_and_removed(): void
    {
        Http::fake(['drust.test/api/v1/media/whisper/models/*' => Http::response(['success' => true, 'data' => self::STATUS + ['pulls' => [['file' => 'ggml-large-v3-turbo-q5_0.bin', 'downloaded' => 0, 'total' => null, 'error' => null]]]])]);

        $this->actingAs($this->admin())
            ->postJson($this->url('ai-gateway.voice.pull'), ['file' => 'ggml-large-v3-turbo-q5_0.bin'])
            ->assertOk()->assertJsonPath('status.pulls.0.file', 'ggml-large-v3-turbo-q5_0.bin');
        // only files from the catalog
        $this->actingAs($this->admin())
            ->postJson($this->url('ai-gateway.voice.pull'), ['file' => 'ggml-anything.bin'])
            ->assertUnprocessable();

        $this->actingAs($this->admin())
            ->postJson($this->url('ai-gateway.voice.remove'), ['file' => 'ggml-small-q5_1.bin'])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://drust.test/api/v1/media/whisper/models/pull' && $request['file'] === 'ggml-large-v3-turbo-q5_0.bin');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://drust.test/api/v1/media/whisper/models/delete' && $request['file'] === 'ggml-small-q5_1.bin');
    }
}
