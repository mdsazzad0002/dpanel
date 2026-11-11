<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRequestLog;
use App\Models\AiGatewayUsageRecord;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Providers\AnthropicAdapter;
use App\Services\AiGateway\Providers\GeminiAdapter;
use App\Services\AiGateway\Providers\OpenAiAdapter;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Immutable descriptor of a registered provider driver.
 */
class RegisteredAdapter
{
    public function __construct(
        public readonly string $driver,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiKeyUrl,
    ) {}
}

class AiGatewayService
{
    /**
     * @var array<int, ProviderAdapter>
     */
    private array $adapters;

    private readonly GatewayMedia $media;

    public function __construct(
        private readonly Router $router,
        ?GatewayMedia $media = null,
    ) {
        $this->media = $media ?? app(GatewayMedia::class);
        $this->adapters = [
            new AnthropicAdapter,
            new OpenAiAdapter,
            new GeminiAdapter,
        ];
    }

    /**
     * Registered driver adapters, keyed by driver.
     *
     * @return array<string, RegisteredAdapter>
     */
    public function adapters(): array
    {
        $drivers = [];

        foreach ($this->adapters as $adapter) {
            $meta = $adapter::driverMeta();

            foreach ($adapter::drivers() as $driver => $label) {
                $drivers[$driver] = new RegisteredAdapter(
                    $driver,
                    $label,
                    $meta[$driver]['base_url'] ?? '',
                    $meta[$driver]['api_key_url'] ?? '',
                );
            }
        }

        return $drivers;
    }

    public function adapterFor(string $driver): ProviderAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supportsDriver($driver)) {
                return $adapter;
            }
        }

        throw Exceptions\AiGatewayException::unsupportedDriver($driver);
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Perform a chat completion, auto-routing to the highest-weight active
     * provider (or the owner of a specific requested model), recording
     * usage + audit logs automatically.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array{model?: string, temperature?: float, max_tokens?: int, system?: string, channel?: string, operation?: string, created_by?: int|null}  $options
     */
    public function chat(array $messages, array $options = []): array
    {
        $resolved = $this->router->resolve(['model' => $options['model'] ?? null]);

        return $this->chatWithProvider($resolved['provider'], $resolved['model'], $resolved['modelName'], $messages, $options);
    }

    /**
     * Perform a chat completion against a specific provider + model,
     * bypassing auto-routing, while still recording usage + audit logs.
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, tool_call_id?:string, name?:string}>  $messages
     * @param  array{temperature?: float, max_tokens?: int, system?: string, tools?: array, tool_choice?: mixed, channel?: string, operation?: string, created_by?: int|null}  $options
     */
    public function chatWithProvider(AiGatewayProvider $provider, ?AiGatewayModel $modelRecord, string $modelName, array $messages, array $options = []): array
    {
        $adapter = $this->adapterFor($provider->driver);
        $traceId = (string) Str::uuid();

        $start = microtime(true);

        try {
            $result = $adapter->chat($provider, $modelName, $this->media->prepareForModel($messages, $modelRecord), [
                'system' => $options['system'] ?? null,
                'temperature' => $options['temperature'] ?? config('aigateway.default_temperature', 0.3),
                'max_tokens' => $options['max_tokens'] ?? config('aigateway.default_max_tokens', 2048),
                'tools' => $this->normaliseTools($options['tools'] ?? null),
                'tool_choice' => $options['tool_choice'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->recordFailure($provider, $modelRecord, $modelName, $traceId, $options, $e);

            throw $e;
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $cost = $this->calculateCost(
            $modelRecord,
            $result['input_tokens'],
            $result['output_tokens']
        );

        AiGatewayUsageRecord::record(
            $provider->id,
            $modelRecord?->id,
            $result['input_tokens'],
            $result['output_tokens'],
            $cost
        );

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $modelRecord?->id,
            'operation' => $options['operation'] ?? 'chat',
            'model' => $modelName,
            'status' => 'success',
            'request_payload' => config('aigateway.log_payloads', true) ? $this->trimForLog(GatewayMedia::withoutImageData($messages)) : null,
            'response_snippet' => config('aigateway.log_payloads', true) ? $this->trimForLog($result['content']) : null,
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cost' => $cost,
            'latency_ms' => $latencyMs,
            'created_by' => $options['created_by'] ?? auth()->id() ?? null,
        ]);

        return [
            'content' => $result['content'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cost' => $cost,
            'model' => $result['model'],
            'model_id' => $modelRecord?->id,
            'provider' => $provider,
            'latency_ms' => $latencyMs,
            'trace_id' => $traceId,
            'raw' => $result['raw'],
            'tool_calls' => $result['tool_calls'] ?? null,
            'finish_reason' => $result['finish_reason'] ?? 'stop',
        ];
    }

    /**
     * Perform a streaming chat completion against a specific provider +
     * model, invoking $onDelta with each text chunk as it arrives. Records
     * usage + audit logs once the stream completes, same as chatWithProvider.
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, tool_call_id?:string, name?:string}>  $messages
     * @param  array{temperature?: float, max_tokens?: int, system?: string, tools?: array, tool_choice?: mixed, channel?: string, operation?: string, created_by?: int|null}  $options
     * @param  \Closure(string):void  $onDelta
     */
    public function chatStreamWithProvider(AiGatewayProvider $provider, ?AiGatewayModel $modelRecord, string $modelName, array $messages, array $options, \Closure $onDelta): array
    {
        $adapter = $this->adapterFor($provider->driver);
        $traceId = (string) Str::uuid();

        $start = microtime(true);

        try {
            $result = $adapter->stream($provider, $modelName, $this->media->prepareForModel($messages, $modelRecord), [
                'system' => $options['system'] ?? null,
                'temperature' => $options['temperature'] ?? config('aigateway.default_temperature', 0.3),
                'max_tokens' => $options['max_tokens'] ?? config('aigateway.default_max_tokens', 2048),
                'tools' => $this->normaliseTools($options['tools'] ?? null),
                'tool_choice' => $options['tool_choice'] ?? null,
            ], $onDelta);
        } catch (\Throwable $e) {
            $this->recordFailure($provider, $modelRecord, $modelName, $traceId, $options, $e);

            throw $e;
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $cost = $this->calculateCost(
            $modelRecord,
            $result['input_tokens'],
            $result['output_tokens']
        );

        AiGatewayUsageRecord::record(
            $provider->id,
            $modelRecord?->id,
            $result['input_tokens'],
            $result['output_tokens'],
            $cost
        );

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $modelRecord?->id,
            'operation' => $options['operation'] ?? 'chat',
            'model' => $modelName,
            'status' => 'success',
            'request_payload' => config('aigateway.log_payloads', true) ? $this->trimForLog(GatewayMedia::withoutImageData($messages)) : null,
            'response_snippet' => config('aigateway.log_payloads', true) ? $this->trimForLog($result['content']) : null,
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cost' => $cost,
            'latency_ms' => $latencyMs,
            'created_by' => $options['created_by'] ?? auth()->id() ?? null,
        ]);

        return [
            'content' => $result['content'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cost' => $cost,
            'model' => $result['model'],
            'model_id' => $modelRecord?->id,
            'provider' => $provider,
            'latency_ms' => $latencyMs,
            'trace_id' => $traceId,
            'raw' => $result['raw'],
            'tool_calls' => $result['tool_calls'] ?? null,
            'finish_reason' => $result['finish_reason'] ?? 'stop',
        ];
    }

    /**
     * Perform a chat completion picking the provider automatically: tries
     * every active, non-cooldown model matching $modelName (any active
     * model if null) in weighted order, failing over to the next candidate
     * whenever the current one returns an upstream error of any kind
     * (rate-limit, auth, bad request, provider-side policy, ...) — the
     * failing model is put in a short cooldown (skipped for a few minutes)
     * so the next request doesn't retry it immediately either. Only a
     * genuinely unexpected (non-gateway) exception aborts immediately.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     */
    public function chatAuto(?string $modelName, array $messages, array $options = [], ?int $providerId = null): array
    {
        $candidates = $this->router->candidates($modelName, $providerId, $this->requiredCapabilities($messages, $options));

        if ($candidates->isEmpty()) {
            throw Exceptions\AiGatewayException::noActiveProvider();
        }

        $lastException = null;

        foreach ($candidates as $modelRecord) {
            try {
                $result = $this->chatWithProvider($modelRecord->provider, $modelRecord, $modelRecord->name, $messages, $options);
                $modelRecord->recordSuccess();

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($e instanceof Exceptions\AiGatewayException) {
                    $modelRecord->recordFailure($e->suggestedRetrySeconds());

                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Streaming counterpart to chatAuto(). $onDelta may be invoked for a
     * candidate that ultimately fails over (partial output before the
     * failure) — callers should treat delta text as provisional until this
     * call returns. Same blanket failover-on-any-upstream-error behavior
     * as chatAuto() — see its docblock.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  \Closure(string):void  $onDelta
     */
    public function chatStreamAuto(?string $modelName, array $messages, array $options, \Closure $onDelta, ?int $providerId = null): array
    {
        $candidates = $this->router->candidates($modelName, $providerId, $this->requiredCapabilities($messages, $options));

        if ($candidates->isEmpty()) {
            throw Exceptions\AiGatewayException::noActiveProvider();
        }

        $lastException = null;

        foreach ($candidates as $modelRecord) {
            try {
                $result = $this->chatStreamWithProvider($modelRecord->provider, $modelRecord, $modelRecord->name, $messages, $options, $onDelta);
                $modelRecord->recordSuccess();

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($e instanceof Exceptions\AiGatewayException) {
                    $modelRecord->recordFailure($e->suggestedRetrySeconds());

                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Generate images from a prompt with a model tagged "image_generation",
     * failing over between such models like chatAuto().
     *
     * @param  array{size?: string, n?: int, channel?: string, created_by?: int|null}  $options
     * @return array{images: array<int, array{mime: string, data: string}>, text: string, model: string, provider: AiGatewayProvider, trace_id: string}
     */
    public function generateImage(?string $modelName, string $prompt, array $options = [], ?int $providerId = null): array
    {
        $candidates = $this->router->candidates($modelName, $providerId, ['image_generation']);

        if ($candidates->isEmpty()) {
            throw new Exceptions\AiGatewayException('No active image model. Add one (e.g. gpt-image-1 or gemini-2.5-flash-image) under AI Gateway → Models and give it the "image_generation" capability.');
        }

        $lastException = null;

        foreach ($candidates as $modelRecord) {
            $provider = $modelRecord->provider;
            $traceId = (string) Str::uuid();
            $start = microtime(true);
            $options['operation'] = 'image';

            try {
                $result = $this->adapterFor($provider->driver)->generateImage($provider, $modelRecord->name, $prompt, [
                    'size' => $options['size'] ?? null,
                    'n' => max(1, min(4, (int) ($options['n'] ?? 1))),
                ]);
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->recordFailure($provider, $modelRecord, $modelRecord->name, $traceId, $options, $e);

                if ($e instanceof Exceptions\AiGatewayException) {
                    $modelRecord->recordFailure($e->suggestedRetrySeconds());

                    continue;
                }

                throw $e;
            }

            $modelRecord->recordSuccess();
            AiGatewayUsageRecord::record($provider->id, $modelRecord->id, 0, 0, 0);
            AiGatewayRequestLog::create([
                'trace_id' => $traceId,
                'channel' => $options['channel'] ?? 'gateway',
                'provider_id' => $provider->id,
                'model_id' => $modelRecord->id,
                'operation' => 'image',
                'model' => $modelRecord->name,
                'status' => 'success',
                'request_payload' => config('aigateway.log_payloads', true) ? $this->trimForLog($prompt) : null,
                'response_snippet' => count($result['images']).' image(s)'.($result['text'] !== '' ? ': '.Str::limit($result['text'], 500) : ''),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost' => 0,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_by' => $options['created_by'] ?? auth()->id() ?? null,
            ]);

            return [...$result, 'provider' => $provider, 'trace_id' => $traceId];
        }

        throw $lastException;
    }

    /**
     * Turn a voice recording into text with a model tagged "transcription",
     * failing over between such models like generateImage().
     *
     * @param  array{language?: ?string, prompt?: ?string, channel?: string, created_by?: int|null}  $options
     * @return array{text: string, language: ?string, model: string, provider: AiGatewayProvider, trace_id: string}
     */
    public function transcribe(string $audio, string $filename, string $mime, array $options = []): array
    {
        // drust's own whisper.cpp (free, on this server) is the voice-to-text gateway when it is
        // there: "first" tries it before the models, "fallback" only when they all fail, "off" never
        $local = self::localTranscriptionMode();

        // speech straight to English (/audio/translations): only drust's whisper does that here
        if (! empty($options['translate'])) {
            if ($local !== 'off' && ($result = $this->transcribeLocally($audio, $filename, $mime, $options))) {
                return $result;
            }

            throw new Exceptions\AiGatewayException('Voice translation needs drust\'s voice to text (AI Gateway → Voice to Text), and it did not answer.');
        }

        if ($local === 'first' && ($result = $this->transcribeLocally($audio, $filename, $mime, $options))) {
            return $result;
        }

        $candidates = $this->router->candidates(null, null, ['transcription']);

        if ($candidates->isEmpty()) {
            if ($local === 'fallback' && ($result = $this->transcribeLocally($audio, $filename, $mime, $options))) {
                return $result;
            }

            throw new Exceptions\AiGatewayException('No active transcription model. Add one (e.g. whisper-large-v3-turbo on Groq, whisper-1 on OpenAI or gemini-2.5-flash) under AI Gateway → Models and give it the "transcription" capability'
                .($local !== 'off' ? ', or check that drust\'s voice transcription (media/transcribe) is running.' : '.'));
        }

        $lastException = null;
        $options['operation'] = 'transcription';

        foreach ($candidates as $modelRecord) {
            $provider = $modelRecord->provider;
            $traceId = (string) Str::uuid();
            $start = microtime(true);

            try {
                $result = $this->adapterFor($provider->driver)->transcribe($provider, $modelRecord->name, $audio, $filename, $mime, [
                    'language' => $options['language'] ?? null,
                    'prompt' => $options['prompt'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->recordFailure($provider, $modelRecord, $modelRecord->name, $traceId, $options, $e);

                if ($e instanceof Exceptions\AiGatewayException) {
                    $modelRecord->recordFailure($e->suggestedRetrySeconds());

                    continue;
                }

                throw $e;
            }

            $modelRecord->recordSuccess();
            AiGatewayUsageRecord::record($provider->id, $modelRecord->id, 0, 0, 0);
            AiGatewayRequestLog::create([
                'trace_id' => $traceId,
                'channel' => $options['channel'] ?? 'gateway',
                'provider_id' => $provider->id,
                'model_id' => $modelRecord->id,
                'operation' => 'transcription',
                'model' => $modelRecord->name,
                'status' => 'success',
                'request_payload' => config('aigateway.log_payloads', true) ? $filename.' ('.$mime.', '.strlen($audio).' bytes)' : null,
                'response_snippet' => Str::limit($result['text'], 500),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost' => 0,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_by' => $options['created_by'] ?? auth()->id() ?? null,
            ]);

            return [...$result, 'provider' => $provider, 'trace_id' => $traceId];
        }

        if ($local === 'fallback' && ($result = $this->transcribeLocally($audio, $filename, $mime, $options))) {
            return $result;
        }

        throw $lastException;
    }

    /**
     * Speech to text with drust's whisper.cpp (POST media/transcribe, the file as base64).
     * Never throws: null when drust is not configured, not reachable or built without speech.
     *
     * @return array{text: string, language: ?string, model: string, provider: null, trace_id: string}|null
     */
    private function transcribeLocally(string $audio, string $filename, string $mime, array $options): ?array
    {
        $url = (string) config('serverpanel.media_transcribe_api_url');
        if ($url === '' || $audio === '') {
            return null;
        }

        $traceId = (string) Str::uuid();
        $start = microtime(true);
        $language = $options['language'] ?? null;

        try {
            $token = (string) config('serverpanel.execution_api_token');
            $response = \Illuminate\Support\Facades\Http::timeout((int) config('aigateway.transcription_timeout', 180))->acceptJson()
                ->when($token !== '', fn ($request) => $request->withToken($token))
                ->post($url, array_filter([
                    'data' => base64_encode($audio),
                    'language' => $language ?: null,
                    'translate' => ! empty($options['translate']) ?: null,
                ]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AI Gateway: local transcription failed', ['error' => $e->getMessage()]);

            return null;
        }

        $text = trim((string) $response->json('data.text'));
        if (! $response->successful() || ! $response->json('success') || $text === '') {
            \Illuminate\Support\Facades\Log::warning('AI Gateway: local transcription did not succeed', ['status' => $response->status(), 'body' => Str::limit($response->body(), 500)]);

            return null;
        }

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'operation' => ! empty($options['translate']) ? 'translation' : 'transcription',
            'model' => self::LOCAL_TRANSCRIPTION_MODEL,
            'status' => 'success',
            'request_payload' => config('aigateway.log_payloads', true) ? $filename.' ('.$mime.', '.strlen($audio).' bytes)' : null,
            'response_snippet' => Str::limit($text, 500),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'created_by' => $options['created_by'] ?? auth()->id() ?? null,
        ]);

        return ['text' => $text, 'language' => $language, 'model' => self::LOCAL_TRANSCRIPTION_MODEL, 'provider' => null, 'trace_id' => $traceId];
    }

    /** Where the panel keeps the Voice to Text page's choice (over AIGATEWAY_LOCAL_TRANSCRIPTION). */
    public const LOCAL_TRANSCRIPTION_MODE_KEY = 'aigateway.local_transcription_mode';

    public const LOCAL_TRANSCRIPTION_MODES = ['first', 'fallback', 'off'];

    /**
     * drust's whisper as the voice-to-text gateway: "first" (before the live transcription
     * models), "fallback" (only when they fail or none exist) or "off" (live models only).
     */
    public static function localTranscriptionMode(): string
    {
        $mode = (string) (\Illuminate\Support\Facades\Cache::get(self::LOCAL_TRANSCRIPTION_MODE_KEY) ?: config('aigateway.local_transcription', 'first'));

        return in_array($mode, self::LOCAL_TRANSCRIPTION_MODES, true) ? $mode : 'first';
    }

    /** How a transcription made by drust's whisper.cpp shows up in responses and the request log. */
    public const LOCAL_TRANSCRIPTION_MODEL = 'whisper.cpp (drust)';

    /**
     * Capabilities auto-routing should prefer for a request.
     *
     * @return array<int, string>
     */
    private function requiredCapabilities(array $messages, array $options): array
    {
        return array_values(array_filter([
            empty($options['tools']) ? null : 'tools',
            GatewayMedia::hasImages($messages) ? 'vision' : null,
        ]));
    }

    /**
     * Test connectivity for a provider, persisting the result.
     *
     * @return array{ok:bool, message:string}
     */
    public function testProvider(AiGatewayProvider $provider): array
    {
        $adapter = $this->adapterFor($provider->driver);
        $result = $adapter->ping($provider);

        $provider->update([
            'last_tested_at' => Carbon::now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'fail',
            'last_test_message' => Str::limit($result['message'], 255),
        ]);

        return $result;
    }

    private function calculateCost(?AiGatewayModel $model, int $inputTokens, int $outputTokens): float
    {
        $inputPrice = $model?->input_price ?: 0;
        $outputPrice = $model?->output_price ?: 0;

        return round(
            ($inputTokens / 1_000_000 * $inputPrice) + ($outputTokens / 1_000_000 * $outputPrice),
            6
        );
    }

    private function recordFailure(
        AiGatewayProvider $provider,
        ?AiGatewayModel $model,
        string $modelName,
        string $traceId,
        array $options,
        \Throwable $e
    ): void {
        AiGatewayUsageRecord::record($provider->id, $model?->id, 0, 0, 0, 1);

        AiGatewayRequestLog::create([
            'trace_id' => $traceId,
            'channel' => $options['channel'] ?? 'gateway',
            'provider_id' => $provider->id,
            'model_id' => $model?->id,
            'operation' => $options['operation'] ?? 'chat',
            'model' => $modelName,
            'status' => 'error',
            'error_message' => Str::limit($e->getMessage(), 2000),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0,
            'latency_ms' => 0,
            'created_by' => $options['created_by'] ?? auth()->id() ?? null,
        ]);
    }

    /**
     * Tools arrive JSON-decoded as PHP arrays, so an empty schema object
     * like `"properties": {}` comes back out of json_encode as `[]` —
     * which OpenAI, Groq and Gemini reject as an invalid schema (only
     * OpenRouter tolerates it). Restore those keywords to empty objects
     * before any adapter serialises the request.
     */
    private function normaliseTools(?array $tools): ?array
    {
        if (empty($tools)) {
            return $tools;
        }

        return array_map(function ($tool) {
            if (! is_array($tool)) {
                return $tool;
            }

            if (isset($tool['function']) && is_array($tool['function'])) {
                $params = $tool['function']['parameters'] ?? null;
                $tool['function']['parameters'] = empty($params)
                    ? ['type' => 'object', 'properties' => new \stdClass]
                    : $this->normaliseSchema($params);
            } elseif (array_key_exists('parameters', $tool)) {
                $tool['parameters'] = empty($tool['parameters'])
                    ? ['type' => 'object', 'properties' => new \stdClass]
                    : $this->normaliseSchema($tool['parameters']);
            }

            return $tool;
        }, $tools);
    }

    private function normaliseSchema(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        if (array_is_list($schema)) {
            return array_map(fn ($v) => $this->normaliseSchema($v), $schema);
        }

        static $mapKeywords = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];
        static $schemaKeywords = ['items', 'additionalProperties', 'not', 'contains', 'propertyNames'];

        foreach ($schema as $key => $value) {
            if (in_array($key, $mapKeywords, true) && is_array($value)) {
                $schema[$key] = $value === []
                    ? new \stdClass
                    : array_map(fn ($v) => $v === [] ? new \stdClass : $this->normaliseSchema($v), $value);
            } elseif (in_array($key, $schemaKeywords, true) && $value === []) {
                $schema[$key] = new \stdClass;
            } elseif (is_array($value)) {
                $schema[$key] = $this->normaliseSchema($value);
            }
        }

        return $schema;
    }

    private function trimForLog(mixed $value): ?string
    {
        if (is_string($value)) {
            return Str::limit($value, 12000);
        }

        if (is_array($value)) {
            return Str::limit(json_encode($value), 12000);
        }

        return null;
    }
}
