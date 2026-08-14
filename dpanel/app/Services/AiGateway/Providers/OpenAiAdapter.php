<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Exceptions\AiGatewayException;
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

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = (int) $options['max_tokens'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->normaliseTools($options['tools']);

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

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = (int) $options['max_tokens'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->normaliseTools($options['tools']);

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
            ->map(fn (array $m): array => [
                'name' => (string) $m['id'],
                'display_name' => null,
                'context_window' => isset($m['context_length']) ? (int) $m['context_length'] : null,
                'max_output_tokens' => null,
                'input_price' => isset($m['pricing']['prompt']) ? round(((float) $m['pricing']['prompt']) * 1_000_000, 4) : null,
                'output_price' => isset($m['pricing']['completion']) ? round(((float) $m['pricing']['completion']) * 1_000_000, 4) : null,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
