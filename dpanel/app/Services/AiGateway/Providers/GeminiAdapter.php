<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeminiAdapter implements ProviderAdapter
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com';

    public function supportsDriver(string $driver): bool
    {
        return $driver === 'gemini';
    }

    public static function drivers(): array
    {
        return ['gemini' => 'Google Gemini'];
    }

    public static function driverMeta(): array
    {
        return ['gemini' => ['base_url' => self::BASE_URL, 'api_key_url' => 'https://aistudio.google.com/apikey']];
    }

    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($provider->base_url ?: self::BASE_URL, '/');
        [$system, $contents] = $this->convertMessages($messages, $options['system'] ?? null);

        $body = ['contents' => $contents];

        if ($system !== null && $system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => (string) $system]]];
        }

        $generationConfig = [];
        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
        }
        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->convertTools($options['tools']);
            $body['toolConfig'] = $this->convertToolChoice($options['tool_choice'] ?? null);
        }

        $url = $base.'/v1beta/models/'.$model.':generateContent?key='.urlencode($apiKey);

        $response = Http::timeout($options['timeout'] ?? config('aigateway.timeout', 90))
            ->acceptJson()
            ->post($url, $body);

        if ($response->failed()) {
            $error = $response->json('error.message') ?: $response->json('error.status') ?: (string) $response->body();
            $error = $error !== '' ? $error : 'HTTP '.$response->status().' with no error details.';
            throw AiGatewayException::upstream($provider->name, $error, $response->status());
        }

        $json = $response->json();
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        $content = collect($parts)
            ->where('text', ! null)
            ->pluck('text')
            ->implode("\n");

        $metadata = $json['usageMetadata'] ?? [];
        $toolCalls = $this->extractToolCalls($parts);

        return [
            'content' => (string) $content,
            'input_tokens' => (int) ($metadata['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($metadata['candidatesTokenCount'] ?? 0),
            'model' => $model,
            'raw' => $json,
            'tool_calls' => $toolCalls,
            'finish_reason' => $toolCalls !== null ? 'tool_calls' : $this->mapFinishReason($json['candidates'][0]['finishReason'] ?? null),
        ];
    }

    public function stream(AiGatewayProvider $provider, string $model, array $messages, array $options, \Closure $onDelta): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($provider->base_url ?: self::BASE_URL, '/');
        [$system, $contents] = $this->convertMessages($messages, $options['system'] ?? null);

        $body = ['contents' => $contents];

        if ($system !== null && $system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => (string) $system]]];
        }

        $generationConfig = [];
        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
        }
        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->convertTools($options['tools']);
            $body['toolConfig'] = $this->convertToolChoice($options['tool_choice'] ?? null);
        }

        $url = $base.'/v1beta/models/'.$model.':streamGenerateContent?alt=sse&key='.urlencode($apiKey);

        $response = Http::timeout($options['timeout'] ?? config('aigateway.timeout', 90))
            ->acceptJson()
            ->withOptions(['stream' => true])
            ->post($url, $body);

        if ($response->failed()) {
            $error = $response->json('error.message') ?: $response->json('error.status') ?: (string) $response->body();
            $error = $error !== '' ? $error : 'HTTP '.$response->status().' with no error details.';
            throw AiGatewayException::upstream($provider->name, $error, $response->status());
        }

        $content = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = null;
        $raw = [];
        // Function-call parts arrive whole (not incrementally) in whichever
        // chunk they complete in — collected across chunks, not streamed.
        $functionCallParts = [];

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

                $json = json_decode(trim(substr($line, 5)), true);
                if (! is_array($json)) {
                    continue;
                }

                $raw = $json;
                $parts = $json['candidates'][0]['content']['parts'] ?? [];

                $delta = collect($parts)
                    ->pluck('text')
                    ->filter(fn ($t) => $t !== null)
                    ->implode('');

                if ($delta !== '') {
                    $content .= $delta;
                    $onDelta($delta);
                }

                foreach ($parts as $part) {
                    if (isset($part['functionCall'])) {
                        $functionCallParts[] = $part;
                    }
                }

                if (isset($json['candidates'][0]['finishReason'])) {
                    $finishReason = $json['candidates'][0]['finishReason'];
                }

                $metadata = $json['usageMetadata'] ?? [];
                if ($metadata) {
                    $inputTokens = (int) ($metadata['promptTokenCount'] ?? $inputTokens);
                    $outputTokens = (int) ($metadata['candidatesTokenCount'] ?? $outputTokens);
                }
            }
        }

        $toolCalls = $this->extractToolCalls($functionCallParts);

        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $model,
            'raw' => $raw,
            'tool_calls' => $toolCalls,
            'finish_reason' => $toolCalls !== null ? 'tool_calls' : $this->mapFinishReason($finishReason),
        ];
    }

    /**
     * Gemini uses "contents" with role user/model/function. An incoming
     * assistant `tool_calls` becomes `functionCall` parts on a "model"
     * turn; an incoming `tool` role message becomes a `functionResponse`
     * part on a "function" turn (correlated by name — Gemini has no
     * call-id concept, so the id we invent for our own tool_calls output
     * is never sent back to Gemini).
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, name?:string}>  $messages
     * @return array{0: ?string, 1: array<int, array{role:string, parts:array}>}
     */
    private function convertMessages(array $messages, ?string $system): array
    {
        $contents = [];

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'system') {
                $system = $system ? $system."\n\n".$message['content'] : $message['content'];

                continue;
            }

            if ($role === 'tool') {
                $contents[] = [
                    'role' => 'function',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $message['name'] ?? '',
                            'response' => ['content' => (string) ($message['content'] ?? '')],
                        ],
                    ]],
                ];

                continue;
            }

            if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                $parts = [];
                if (! empty($message['content'])) {
                    $parts[] = ['text' => (string) $message['content']];
                }
                foreach ($message['tool_calls'] as $toolCall) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $toolCall['function']['name'] ?? '',
                            'args' => json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: new \stdClass(),
                        ],
                    ];
                }

                $contents[] = ['role' => 'model', 'parts' => $parts];

                continue;
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($message['content'] ?? '')]],
            ];
        }

        return [$system, array_values($contents)];
    }

    /**
     * OpenAI-shape tools to Gemini's `functionDeclarations` list.
     */
    private function convertTools(array $tools): array
    {
        $declarations = collect($tools)->map(fn (array $t): array => [
            'name' => $t['function']['name'] ?? $t['name'] ?? '',
            'description' => $t['function']['description'] ?? $t['description'] ?? '',
            'parameters' => $this->sanitizeSchema($t['function']['parameters'] ?? $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()]),
        ])->values()->all();

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Gemini accepts only a small subset of JSON Schema (no `$schema`,
     * `additionalProperties`, `exclusiveMinimum`, etc. — it rejects the
     * whole request with an "Unknown name" error if any of those show up
     * anywhere in the tree). Tool schemas from real client libraries (MCP,
     * JSON-Schema generators) routinely include them, so every field is
     * stripped down to Gemini's known-supported allowlist, recursively.
     */
    private function sanitizeSchema(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        static $allowed = [
            'type', 'format', 'description', 'enum', 'items', 'properties',
            'required', 'nullable', 'minimum', 'maximum', 'minLength',
            'maxLength', 'minItems', 'maxItems', 'pattern',
        ];

        // A plain list (e.g. "required": ["city"], "enum": [...]) — sanitize
        // any nested schema elements but leave scalar entries alone.
        if (array_is_list($schema)) {
            return array_map(fn ($v) => $this->sanitizeSchema($v), $schema);
        }

        $clean = [];
        foreach ($schema as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            // "properties" is keyed by arbitrary user-defined property names
            // (not schema keywords) — those keys must be preserved as-is;
            // only each property's own nested schema gets sanitized.
            if ($key === 'properties' && is_array($value)) {
                $clean[$key] = array_map(fn ($v) => $this->sanitizeSchema($v), $value);
            } else {
                $clean[$key] = is_array($value) ? $this->sanitizeSchema($value) : $value;
            }
        }

        return $clean;
    }

    /**
     * OpenAI-shape tool_choice to Gemini's `toolConfig.functionCallingConfig`.
     */
    private function convertToolChoice(mixed $choice): array
    {
        if ($choice === 'none') {
            return ['functionCallingConfig' => ['mode' => 'NONE']];
        }
        if ($choice === 'required') {
            return ['functionCallingConfig' => ['mode' => 'ANY']];
        }
        if (is_array($choice) && isset($choice['function']['name'])) {
            return ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => [$choice['function']['name']]]];
        }

        return ['functionCallingConfig' => ['mode' => 'AUTO']];
    }

    /**
     * @param  array<int, array{functionCall?:array{name?:string, args?:array}}>  $parts
     */
    private function extractToolCalls(array $parts): ?array
    {
        $toolCalls = collect($parts)
            ->filter(fn ($p) => isset($p['functionCall']))
            ->map(fn (array $p): array => [
                'id' => 'call_'.Str::random(20),
                'type' => 'function',
                'function' => [
                    'name' => $p['functionCall']['name'] ?? '',
                    'arguments' => json_encode($p['functionCall']['args'] ?? new \stdClass()),
                ],
            ])
            ->values()
            ->all();

        return $toolCalls !== [] ? $toolCalls : null;
    }

    private function mapFinishReason(?string $finishReason): string
    {
        return match ($finishReason) {
            'MAX_TOKENS' => 'length',
            default => 'stop',
        };
    }

    public function ping(AiGatewayProvider $provider): array
    {
        try {
            $result = $this->chat($provider, $provider->default_model ?: 'gemini-2.5-flash', [
                ['role' => 'user', 'content' => 'Say OK'],
            ], ['max_tokens' => 8]);

            return ['ok' => true, 'message' => 'Connected successfully (model '.$result['model'].').'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * GET /v1beta/models — model names come back prefixed with "models/",
     * which is stripped so what we store/send matches the bare model IDs
     * this adapter's chat()/stream() expect.
     */
    public function listModels(AiGatewayProvider $provider): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($provider->base_url ?: self::BASE_URL, '/');

        $response = Http::timeout(config('aigateway.timeout', 30))
            ->acceptJson()
            ->get($base.'/v1beta/models', ['pageSize' => 1000, 'key' => $apiKey]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?: $response->json('error.status') ?: (string) $response->body();
            $error = $error !== '' ? $error : 'HTTP '.$response->status().' with no error details.';
            throw AiGatewayException::upstream($provider->name, $error, $response->status());
        }

        return collect($response->json('models') ?? [])
            ->filter(fn ($m) => is_array($m) && ! empty($m['name']))
            ->map(fn (array $m): array => [
                'name' => Str::after($m['name'], 'models/'),
                'display_name' => $m['displayName'] ?? null,
                'context_window' => isset($m['inputTokenLimit']) ? (int) $m['inputTokenLimit'] : null,
                'max_output_tokens' => isset($m['outputTokenLimit']) ? (int) $m['outputTokenLimit'] : null,
                'input_price' => null,
                'output_price' => null,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}

