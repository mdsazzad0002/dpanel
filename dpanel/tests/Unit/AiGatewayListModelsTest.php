<?php

namespace Tests\Unit;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayListModelsTest extends TestCase
{
    private function listFor(string $driver): array
    {
        $provider = new AiGatewayProvider(['name' => $driver, 'driver' => $driver]);
        $provider = \Mockery::mock($provider)->makePartial();
        $provider->shouldReceive('getApiKey')->andReturn('test-key');

        $models = $this->app->make(AiGatewayService::class)->adapterFor($driver)->listModels($provider);

        return collect($models)->keyBy('name')->map(fn ($m) => $m['capabilities'])->all();
    }

    public function test_openrouter_reads_modalities_and_hides_expiring_models(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [
            ['id' => 'a/vision-tools', 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']], 'supported_parameters' => ['tools']],
            ['id' => 'a/text-only', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']], 'supported_parameters' => []],
            ['id' => 'a/painter', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['image']]],
            ['id' => 'a/old', 'expiration_date' => '2026-11-01', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']]],
            ['id' => 'a/embedder', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['embeddings']]],
        ]])]);

        $this->assertSame([
            'a/painter' => ['image_generation'],
            'a/text-only' => ['chat'],
            'a/vision-tools' => ['chat', 'vision', 'code', 'tools'],
        ], $this->listFor('openrouter'));
    }

    public function test_openai_drops_non_chat_and_legacy_models(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [
            ['id' => 'gpt-4o'], ['id' => 'gpt-3.5-turbo'], ['id' => 'text-embedding-3-small'],
            ['id' => 'whisper-1'], ['id' => 'gpt-image-1'], ['id' => 'dall-e-2'], ['id' => 'o1-mini'],
        ]])]);

        $this->assertSame([
            'gpt-4o' => ['chat', 'vision', 'code', 'tools'],
            'gpt-image-1' => ['image_generation'],
        ], $this->listFor('openai'));
    }

    public function test_groq_hides_inactive_models(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['data' => [
            ['id' => 'llama-3.3-70b-versatile', 'active' => true, 'context_window' => 131072],
            ['id' => 'gemma2-9b-it', 'active' => false],
        ]])]);

        $this->assertSame(['llama-3.3-70b-versatile'], array_keys($this->listFor('groq')));
    }

    public function test_gemini_keeps_generate_content_models_only(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [
            ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-2.5-flash-image', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-embedding-001', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/imagen-4.0-generate-001', 'supportedGenerationMethods' => ['predict']],
            ['name' => 'models/gemini-1.5-pro', 'supportedGenerationMethods' => ['generateContent'], 'description' => 'This model is deprecated.'],
        ]])]);

        $this->assertSame([
            'gemini-2.5-flash' => ['chat', 'vision', 'code', 'tools'],
            'gemini-2.5-flash-image' => ['vision', 'image_generation'],
        ], $this->listFor('gemini'));
    }

    public function test_anthropic_hides_claude_3(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => [
            ['id' => 'claude-sonnet-4-5', 'display_name' => 'Claude Sonnet 4.5'],
            ['id' => 'claude-3-5-haiku-20241022'],
        ]])]);

        $this->assertSame(['claude-sonnet-4-5' => ['chat', 'vision', 'code', 'tools']], $this->listFor('anthropic'));
    }
}
