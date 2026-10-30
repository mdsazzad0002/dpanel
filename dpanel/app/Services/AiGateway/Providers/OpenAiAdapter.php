<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use App\Services\AiGateway\ModelCatalog;
use App\Services\AiGateway\GatewayMedia;
use Illuminate\Support\Facades\Http;

/**
 * Handles OpenAI and every other provider that speaks the OpenAI
 * chat-completions wire format (OpenRouter, Groq, DeepSeek, Mistral,
 * Cerebras, Kilo Code). Each driver has a fixed, well-known base URL — there is no
 * free-text base URL field in the UI.
 */
class OpenAiAdapter implements ProviderAdapter
{
    /**
     * driver => [label, base_url, api_key_url].
     */
    private const DRIVERS = [
        'openai' => ['OpenAI', 'https://api.openai.com/v1', 'https://platform.openai.com/api-keys'],
        'openrouter' => ['OpenRouter (many free models)', 'https://openrouter.ai/api/v1', 'https://openrouter.ai/keys'],
        'groq' => ['Groq (fast, generous free tier)', 'https://api.groq.com/openai/v1', 'https://console.groq.com/keys'],
        'deepseek' => ['DeepSeek', 'https://api.deepseek.com', 'https://platform.deepseek.com/api_keys'],
        'mistral' => ['Mistral', 'https://api.mistral.ai/v1', 'https://console.mistral.ai/api-keys'],
        'cerebras' => ['Cerebras (fast, free tier)', 'https://api.cerebras.ai/v1', 'https://cloud.cerebras.ai/'],
        'kilo' => ['Kilo Code (500+ models)', 'https://api.kilo.ai/api/gateway', 'https://app.kilo.ai/'],
    ];

    public function supportsDriver(string $driver): bool
    {
        return array_key_exists($driver, self::DRIVERS);
    }

    public static function drivers(): array
    {
        return array_map(fn (array $meta) => $meta[0], self::DRIVERS);
    }

    public static function driverMeta(): array
    {
        return array_map(fn (array $meta) => ['base_url' => $meta[1], 'api_key_url' => $meta[2]], self::DRIVERS);
    }

    /**
     * OpenAI's /images/generations, which OpenAI-compatible providers that
     * make images (xAI, Together, …) also serve.
     */
    public function generateImage(AiGatewayProvider $provider, string $model, string $prompt, array $options = []): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $body = array_filter([
            'model' => $model,
            'prompt' => $prompt,
            'n' => (int) ($options['n'] ?? 1),
            'size' => $options['size'] ?? null,
        ], fn ($value) => $value !== null);

        // gpt-image-* always answer in base64 and reject response_format;
        // DALL·E answers with short-lived URLs unless asked.
        if (str_starts_with($model, 'dall-e')) {
            $body['response_format'] = 'b64_json';
        }

        $response = $this->requestFor($provider, $apiKey, (int) config('aigateway.image_timeout', 180))
            ->post(rtrim($this->baseUrlFor($provider), '/').'/images/generations', $body);

        if ($response->failed()) {
            throw AiGatewayException::upstream($provider->name, $this->extractErrorMessage($response), $response->status());
        }

        $images = [];
        $notes = [];
        foreach ((array) $response->json('data', []) as $item) {
            $bytes = isset($item['b64_json'])
                ? base64_decode((string) $item['b64_json'], true)
                : (isset($item['url']) ? Http::timeout(60)->get((string) $item['url'])->body() : false);

            if (is_string($bytes) && $bytes !== '') {
                $images[] = ['mime' => GatewayMedia::detectMime($bytes) ?? 'image/png', 'data' => base64_encode($bytes)];
            }
            if (! empty($item['revised_prompt'])) {
                $notes[] = (string) $item['revised_prompt'];
            }
        }

        if ($images === []) {
            throw AiGatewayException::upstream($provider->name, 'The response contained no images.', $response->status());
        }

        return ['images' => $images, 'text' => implode("\n", $notes), 'model' => $model];
    }

    /**
     * OpenAI's own API rejects max_tokens on its newer models (gpt-5.x, o-series)
     * and takes max_completion_tokens on every chat model; the compatible
     * providers (OpenRouter, Groq, Kilo, ...) still expect max_tokens.
     */
    private function tokenLimitKey(AiGatewayProvider $provider): string
    {
        return $provider->driver === 'openai' ? 'max_completion_tokens' : 'max_tokens';
    }

    /**
     * Whether the model is an ordinary chat model. OpenAI's reasoning models (gpt-5.x,
     * o1/o3/o4...) only accept the default temperature and reject any other value.
     */
    private function supportsTemperature(AiGatewayProvider $provider, string $model): bool
    {
        return ! ($provider->driver === 'openai' && preg_match('/^(gpt-5|o\d)/', $model));
    }

    private function baseUrlFor(AiGatewayProvider $provider): string
    {
        return $provider->base_url ?: (self::DRIVERS[$provider->driver][1] ?? self::DRIVERS['openai'][1]);
    }

    private function requestFor(AiGatewayProvider $provider, string $apiKey, ?int $timeout = null)
    {
        $request = Http::withToken($apiKey)->timeout($timeout ?? config('aigateway.timeout', 90));

        if ($provider->driver === 'openrouter') {
            $request = $request->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name', 'DPanel'),
            ]);
        }

        return $request;
    }

    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($this->baseUrlFor($provider), '/');

        $system = $options['system'] ?? null;
        $chatMessages = $this->normaliseMessages($messages);

        if ($system !== null && $system !== '' && ! collect($messages)->first(fn ($m) => $m['role'] === 'system')) {
            array_unshift($chatMessages, ['role' => 'system', 'content' => (string) $system]);
        }

        $body = [
            'model' => $model,
            'messages' => $chatMessages,
        ];

        if (isset($options['temperature']) && $this->supportsTemperature($provider, $model)) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body[$this->tokenLimitKey($provider)] = (int) $options['max_tokens'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->normaliseTools($options['tools']);

            // OpenAI's reasoning models only take function tools on /chat/completions with reasoning switched off.
            if (! $this->supportsTemperature($provider, $model)) {
                $body['reasoning_effort'] = 'none';
            }

            if (! empty($options['tool_choice'])) {
                $body['tool_choice'] = $options['tool_choice'];
            }
        }

        $request = $this->requestFor($provider, $apiKey, $options['timeout'] ?? null);

        $response = $request->post($base.'/chat/completions', $body);

        if ($response->failed()) {
            throw AiGatewayException::upstream($provider->name, $this->extractErrorMessage($response), $response->status());
        }

        $json = $response->json();
        $message = $json['choices'][0]['message'] ?? [];
        $content = $message['content'] ?? '';
        $usage = $json['usage'] ?? [];

        return [
            'content' => (string) $content,
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'model' => $json['model'] ?? $model,
            'raw' => $json,
            'tool_calls' => $message['tool_calls'] ?? null,
            'finish_reason' => $json['choices'][0]['finish_reason'] ?? 'stop',
        ];
    }

    public function stream(AiGatewayProvider $provider, string $model, array $messages, array $options, \Closure $onDelta): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($this->baseUrlFor($provider), '/');

        $system = $options['system'] ?? null;
        $chatMessages = $this->normaliseMessages($messages);

        if ($system !== null && $system !== '' && ! collect($messages)->first(fn ($m) => $m['role'] === 'system')) {
            array_unshift($chatMessages, ['role' => 'system', 'content' => (string) $system]);
        }

        $body = [
            'model' => $model,
            'messages' => $chatMessages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if (isset($options['temperature']) && $this->supportsTemperature($provider, $model)) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body[$this->tokenLimitKey($provider)] = (int) $options['max_tokens'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->normaliseTools($options['tools']);

            // OpenAI's reasoning models only take function tools on /chat/completions with reasoning switched off.
            if (! $this->supportsTemperature($provider, $model)) {
                $body['reasoning_effort'] = 'none';
            }

            if (! empty($options['tool_choice'])) {
                $body['tool_choice'] = $options['tool_choice'];
            }
        }

        $response = $this->requestFor($provider, $apiKey, $options['timeout'] ?? null)
            ->withOptions(['stream' => true])
            ->post($base.'/chat/completions', $body);

        if ($response->failed()) {
            throw AiGatewayException::upstream($provider->name, $this->extractErrorMessage($response), $response->status());
        }

        $content = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $finalModel = $model;
        $finishReason = 'stop';
        $raw = [];
        // Tool call arguments arrive as incremental string fragments, keyed
        // by index — accumulated here and only exposed once complete.
        $toolCalls = [];

        $this->eachSseEvent($response, function (array $json) use (&$content, &$inputTokens, &$outputTokens, &$finalModel, &$finishReason, &$raw, &$toolCalls, $onDelta): void {
            $raw = $json;
            $finalModel = $json['model'] ?? $finalModel;

            $choice = $json['choices'][0] ?? [];
            $delta = $choice['delta']['content'] ?? null;
            if ($delta !== null && $delta !== '') {
                $content .= $delta;
                $onDelta($delta);
            }

            foreach ($choice['delta']['tool_calls'] ?? [] as $tc) {
                $i = $tc['index'] ?? 0;
                $toolCalls[$i] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];

                if (! empty($tc['id'])) {
                    $toolCalls[$i]['id'] = $tc['id'];
                }
                if (! empty($tc['function']['name'])) {
                    $toolCalls[$i]['function']['name'] .= $tc['function']['name'];
                }
                if (isset($tc['function']['arguments'])) {
                    $toolCalls[$i]['function']['arguments'] .= $tc['function']['arguments'];
                }
            }

            if (! empty($choice['finish_reason'])) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($json['usage'])) {
                $inputTokens = (int) ($json['usage']['prompt_tokens'] ?? $inputTokens);
                $outputTokens = (int) ($json['usage']['completion_tokens'] ?? $outputTokens);
            }
        });

        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $finalModel,
            'tool_calls' => $toolCalls !== [] ? array_values($toolCalls) : null,
            'finish_reason' => $finishReason,
            'raw' => $raw,
        ];
    }

    /**
     * The wire format requires "type": "function" on every tool — default
     * it in rather than trust it survived request validation/the caller.
     */
    private function normaliseTools(array $tools): array
    {
        return array_map(fn (array $t): array => $t + ['type' => 'function'], $tools);
    }

    /**
     * Same defaulting as normaliseTools(), but for the "type": "function"
     * OpenAI requires on each entry of an assistant message's tool_calls
     * when it's echoed back in a follow-up request.
     */
    private function normaliseMessages(array $messages): array
    {
        return array_map(function (array $m): array {
            if (! empty($m['images'])) {
                $m['content'] = [
                    ['type' => 'text', 'text' => (string) ($m['content'] ?? '')],
                    ...array_map(fn (array $image): array => [
                        'type' => 'image_url',
                        'image_url' => ['url' => 'data:'.$image['mime'].';base64,'.$image['data']],
                    ], $m['images']),
                ];
            }
            unset($m['images']);

            if (! empty($m['tool_calls'])) {
                $m['tool_calls'] = array_map(fn (array $tc): array => $tc + ['type' => 'function'], $m['tool_calls']);
            }

            return $m;
        }, $messages);
    }

    /**
     * Read a "data: {json}" SSE body, invoking $onEvent for each decoded
     * JSON payload. Skips "[DONE]" sentinels and non-JSON lines.
     */
    /**
     * Providers in this family don't all agree on where the error text
     * lives: most nest it under "error.message" (OpenAI shape), but some
     * (Cerebras) put a flat top-level "message". Try both before falling
     * back to the raw body, and never return a blank string.
     */
    private function extractErrorMessage($response): string
    {
        $message = $response->json('error.message') ?: $response->json('message') ?: (string) $response->body();

        return $message !== '' ? $message : 'HTTP '.$response->status().' with no error details.';
    }

    private function eachSseEvent($response, \Closure $onEvent): void
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    continue;
                }

                $json = json_decode($data, true);
                if (is_array($json)) {
                    $onEvent($json);
                }
            }
        }
    }

    public function ping(AiGatewayProvider $provider): array
    {
        $model = $provider->default_model;

        try {
            $payload = ['model' => $model ?? 'gpt-4.1-mini', 'messages' => [['role' => 'user', 'content' => 'Say OK']], 'max_tokens' => 8];

            $base = rtrim($this->baseUrlFor($provider), '/');
            $response = $this->requestFor($provider, (string) $provider->getApiKey(), config('aigateway.timeout', 30))
                ->post($base.'/models', []);

            // Some local servers (Ollama) don't implement /models; fall back to a tiny chat.
            if ($response->failed()) {
                $result = $this->chat($provider, $model ?? 'gpt-4.1-mini', [['role' => 'user', 'content' => 'Say OK']], ['max_tokens' => 8]);

                return ['ok' => true, 'message' => 'Connected successfully (model '.$result['model'].').'];
            }

            return ['ok' => true, 'message' => 'Connected successfully.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * GET /models — the OpenAI-compatible list-models endpoint. Every
     * driver in this family implements it (OpenRouter and Groq additionally
     * report pricing/context data, which we surface when present).
     */
    public function listModels(AiGatewayProvider $provider): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($this->baseUrlFor($provider), '/');

        $response = $this->requestFor($provider, $apiKey, config('aigateway.timeout', 30))
            ->get($base.'/models');

        if ($response->failed()) {
            throw AiGatewayException::upstream($provider->name, $this->extractErrorMessage($response), $response->status());
        }

        return collect($response->json('data') ?? [])
            ->filter(fn ($m) => is_array($m) && ! empty($m['id']))
            ->map(function (array $m) use ($provider): ?array {
                $name = (string) $m['id'];
                $capabilities = $this->capabilitiesFor($provider->driver, $name, $m);

                if ($capabilities === null || ModelCatalog::flaggedDeprecated($m) || $this->isLegacy($provider->driver, $name)) {
                    return null;
                }

                $context = $m['context_length'] ?? $m['context_window'] ?? $m['max_context_length'] ?? null;
                $maxOutput = $m['top_provider']['max_completion_tokens'] ?? $m['max_completion_tokens'] ?? null;

                return [
                    'name' => $name,
                    'display_name' => isset($m['name']) && is_string($m['name']) ? $m['name'] : null,
                    'context_window' => $context !== null ? (int) $context : null,
                    'max_output_tokens' => $maxOutput !== null ? (int) $maxOutput : null,
                    'capabilities' => $capabilities,
                    'input_price' => isset($m['pricing']['prompt']) ? round(((float) $m['pricing']['prompt']) * 1_000_000, 4) : null,
                    'output_price' => isset($m['pricing']['completion']) ? round(((float) $m['pricing']['completion']) * 1_000_000, 4) : null,
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * What a listed model can do, or null when it can't serve chat or image
     * requests at all (embeddings, speech, moderation, ...). Uses the
     * provider's own metadata where it reports any — OpenRouter/Kilo
     * modalities and parameters, Mistral's capability flags — and falls back
     * to the model id for providers that only return ids.
     *
     * @return ?array<int, string>
     */
    private function capabilitiesFor(string $driver, string $name, array $m): ?array
    {
        $id = strtolower($name);

        if (preg_match('/embed|whisper|tts|transcribe|moderation|guard|audio|realtime|speech|ocr|rerank|sora|veo/', $id)) {
            return null;
        }

        // OpenRouter and Kilo describe each model's modalities and parameters.
        if (isset($m['architecture']) && is_array($m['architecture'])) {
            $in = (array) ($m['architecture']['input_modalities'] ?? ['text']);
            $out = (array) ($m['architecture']['output_modalities'] ?? ['text']);

            $capabilities = array_merge(
                in_array('text', $out, true) ? ['chat'] : [],
                in_array('image', $out, true) ? ['image_generation'] : [],
                in_array('image', $in, true) ? ['vision'] : [],
                in_array('tools', (array) ($m['supported_parameters'] ?? []), true) ? ['tools'] : [],
            );

            return in_array('chat', $capabilities, true) || in_array('image_generation', $capabilities, true)
                ? ModelCatalog::withCodeHint($name, $capabilities)
                : null;
        }

        // Mistral reports capability flags per model.
        if (isset($m['capabilities']) && is_array($m['capabilities'])) {
            $flags = $m['capabilities'];
            if (empty($flags['completion_chat'])) {
                return null;
            }

            return ModelCatalog::withCodeHint($name, array_merge(
                ['chat'],
                ! empty($flags['vision']) ? ['vision'] : [],
                ! empty($flags['function_calling']) ? ['tools'] : [],
            ));
        }

        if (preg_match('/^(gpt-image|dall-e)/', $id)) {
            return ['image_generation'];
        }

        // OpenAI models only served by the Responses API, which this adapter doesn't speak.
        if ($driver === 'openai' && preg_match('/codex|-pro\b|deep-research|search|computer-use|^(babbage|davinci)/', $id)) {
            return null;
        }

        $capabilities = ['chat'];

        if ($driver === 'openai') {
            if (preg_match('/^(gpt-4o|gpt-4\.1|gpt-5|o1(?!-mini)|o3|o4)/', $id)) {
                $capabilities[] = 'vision';
            }
            if (preg_match('/^(gpt-4|gpt-5|o1(?!-mini)|o3|o4)/', $id)) {
                $capabilities[] = 'tools';
            }
        } else {
            if (preg_match('/vision|llama-4|-vl\b|pixtral|gemma-3|kimi-k2\.5|qwen.*-vl/', $id)) {
                $capabilities[] = 'vision';
            }
            // DeepSeek, Groq, Cerebras and Mistral chat models all take tools.
            $capabilities[] = 'tools';
        }

        return ModelCatalog::withCodeHint($name, $capabilities);
    }

    /**
     * Models the provider has announced as deprecated or retired but that can
     * still show up in an id-only listing (OpenAI reports no deprecation field).
     */
    private function isLegacy(string $driver, string $name): bool
    {
        return $driver === 'openai'
            && preg_match('/^(gpt-3\.5|gpt-4-|gpt-4$|gpt-4\.5|o1-(preview|mini)|chatgpt-4o|dall-e-2)/', strtolower($name)) === 1;
    }
}
