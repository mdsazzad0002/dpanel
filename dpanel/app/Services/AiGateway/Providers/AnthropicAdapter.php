<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AnthropicAdapter implements ProviderAdapter
{
    private const BASE_URL = 'https://api.anthropic.com';

    public function supportsDriver(string $driver): bool
    {
        return $driver === 'anthropic';
    }

    public static function drivers(): array
    {
        return ['anthropic' => 'Claude (Anthropic)'];
    }

    public static function driverMeta(): array
    {
        return ['anthropic' => ['base_url' => self::BASE_URL, 'api_key_url' => 'https://console.anthropic.com/settings/keys']];
    }

    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($provider->base_url ?: self::BASE_URL, '/');
        [$system, $converted] = $this->convertMessages($messages, $options['system'] ?? null);

        $body = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'messages' => $converted,
        ];

        if ($system !== null && $system !== '') {
            $body['system'] = (string) $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->convertTools($options['tools']);
            $toolChoice = $this->convertToolChoice($options['tool_choice'] ?? null);
            if ($toolChoice !== null) {
                $body['tool_choice'] = $toolChoice;
            }
        }

        $response = Http::withToken($apiKey)
            ->withHeaders(['anthropic-version' => '2023-06-01'])
            ->timeout($options['timeout'] ?? config('aigateway.timeout', 90))
            ->post($base.'/v1/messages', $body);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: (string) $response->body();
            $message = $message !== '' ? $message : 'HTTP '.$response->status().' with no error details.';
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::upstream($provider->name, $message, $response->status());
        }

        $json = $response->json();
        $blocks = $json['content'] ?? [];
        $content = collect($blocks)
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        $usage = $json['usage'] ?? [];

        return [
            'content' => $content,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'model' => $json['model'] ?? $model,
            'raw' => $json,
            'tool_calls' => $this->extractToolCalls($blocks),
            'finish_reason' => $this->mapFinishReason($json['stop_reason'] ?? null),
        ];
    }

    public function stream(AiGatewayProvider $provider, string $model, array $messages, array $options, \Closure $onDelta): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($provider->base_url ?: self::BASE_URL, '/');
        [$system, $converted] = $this->convertMessages($messages, $options['system'] ?? null);

        $body = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'messages' => $converted,
            'stream' => true,
        ];

        if ($system !== null && $system !== '') {
            $body['system'] = (string) $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $this->convertTools($options['tools']);
            $toolChoice = $this->convertToolChoice($options['tool_choice'] ?? null);
            if ($toolChoice !== null) {
                $body['tool_choice'] = $toolChoice;
            }
        }

        $response = Http::withToken($apiKey)
            ->withHeaders(['anthropic-version' => '2023-06-01'])
            ->timeout($options['timeout'] ?? config('aigateway.timeout', 90))
            ->withOptions(['stream' => true])
            ->post($base.'/v1/messages', $body);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: (string) $response->body();
            $message = $message !== '' ? $message : 'HTTP '.$response->status().' with no error details.';
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::upstream($provider->name, $message, $response->status());
        }

        $content = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $finalModel = $model;
        $stopReason = null;
        $raw = [];
        // Tool use blocks stream in as {id, name} on content_block_start,
        // then incremental JSON string fragments — accumulated by index.
        $blocks = [];

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
                $type = $json['type'] ?? null;
                $index = $json['index'] ?? 0;

                if ($type === 'message_start') {
                    $inputTokens = (int) ($json['message']['usage']['input_tokens'] ?? $inputTokens);
                    $finalModel = $json['message']['model'] ?? $finalModel;
                } elseif ($type === 'content_block_start') {
                    $block = $json['content_block'] ?? [];
                    if (($block['type'] ?? null) === 'tool_use') {
                        $blocks[$index] = ['id' => $block['id'] ?? '', 'name' => $block['name'] ?? '', 'json' => ''];
                    }
                } elseif ($type === 'content_block_delta') {
                    $deltaType = $json['delta']['type'] ?? null;
                    if ($deltaType === 'text_delta') {
                        $delta = $json['delta']['text'] ?? '';
                        if ($delta !== '') {
                            $content .= $delta;
                            $onDelta($delta);
                        }
                    } elseif ($deltaType === 'input_json_delta' && isset($blocks[$index])) {
                        $blocks[$index]['json'] .= $json['delta']['partial_json'] ?? '';
                    }
                } elseif ($type === 'message_delta') {
                    $outputTokens = (int) ($json['usage']['output_tokens'] ?? $outputTokens);
                    $stopReason = $json['delta']['stop_reason'] ?? $stopReason;
                }
            }
        }

        $toolCalls = collect($blocks)->map(fn (array $b) => [
            'id' => $b['id'],
            'type' => 'function',
            'function' => ['name' => $b['name'], 'arguments' => $b['json'] !== '' ? $b['json'] : '{}'],
        ])->values()->all();

        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $finalModel,
            'raw' => $raw,
            'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
            'finish_reason' => $this->mapFinishReason($stopReason),
        ];
    }

    public function ping(AiGatewayProvider $provider): array
    {
        try {
            $result = $this->chat($provider, $provider->default_model ?: 'claude-sonnet-4-20250514', [
                ['role' => 'user', 'content' => 'Say OK'],
            ], ['max_tokens' => 8]);

            return ['ok' => true, 'message' => 'Connected successfully (model '.$result['model'].').'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Anthropic wants system prompts as a top-level "system" field and only
     * user/assistant messages in the message array, each with either a
     * plain string or content-block array. An incoming assistant
     * `tool_calls` becomes `tool_use` blocks; an incoming `tool` role
     * message becomes a `tool_result` block on a user turn.
     *
     * @param  array<int, array{role:string, content:?string, tool_calls?:array, tool_call_id?:string}>  $messages
     * @return array{0: ?string, 1: array<int, array{role:string, content:mixed}>}
     */
    private function convertMessages(array $messages, ?string $system): array
    {
        $converted = [];

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'system') {
                $system = $system ? $system."\n\n".$message['content'] : $message['content'];

                continue;
            }

            if ($role === 'tool') {
                $converted[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $message['tool_call_id'] ?? '',
                        'content' => (string) ($message['content'] ?? ''),
                    ]],
                ];

                continue;
            }

            if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                $blocks = [];
                if (! empty($message['content'])) {
                    $blocks[] = ['type' => 'text', 'text' => (string) $message['content']];
                }
                foreach ($message['tool_calls'] as $toolCall) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall['id'] ?? (string) Str::uuid(),
                        'name' => $toolCall['function']['name'] ?? '',
                        'input' => json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: new \stdClass(),
                    ];
                }

                $converted[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            $converted[] = ['role' => $role, 'content' => (string) ($message['content'] ?? '')];
        }

        return [$system, array_values($converted)];
    }

    /**
     * OpenAI-shape tools (`{type:'function', function:{name, description,
     * parameters}}`) to Anthropic's flat `{name, description, input_schema}`.
     */
    private function convertTools(array $tools): array
    {
        return collect($tools)->map(fn (array $t): array => [
            'name' => $t['function']['name'] ?? $t['name'] ?? '',
            'description' => $t['function']['description'] ?? $t['description'] ?? '',
            'input_schema' => $t['function']['parameters'] ?? $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
        ])->values()->all();
    }

    /**
     * OpenAI-shape tool_choice ("auto"|"none"|"required"|{type:'function',
     * function:{name}}) to Anthropic's {type:'auto'|'any'|'tool', name?}.
     * Returns null for "none" — Anthropic has no equivalent, so tools are
     * simply omitted from the request in that case (handled by the caller).
     */
    private function convertToolChoice(mixed $choice): ?array
    {
        if ($choice === null || $choice === 'auto') {
            return ['type' => 'auto'];
        }
        if ($choice === 'none') {
            return null;
        }
        if ($choice === 'required') {
            return ['type' => 'any'];
        }
        if (is_array($choice) && isset($choice['function']['name'])) {
            return ['type' => 'tool', 'name' => $choice['function']['name']];
        }

        return ['type' => 'auto'];
    }

    /**
     * @param  array<int, array{type?:string}>  $blocks
     */
    private function extractToolCalls(array $blocks): ?array
    {
        $toolCalls = collect($blocks)
            ->where('type', 'tool_use')
            ->map(fn (array $b): array => [
                'id' => $b['id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $b['name'] ?? '',
                    'arguments' => json_encode($b['input'] ?? new \stdClass()),
                ],
            ])
            ->values()
            ->all();

        return $toolCalls !== [] ? $toolCalls : null;
    }

    private function mapFinishReason(?string $stopReason): string
    {
        return match ($stopReason) {
            'tool_use' => 'tool_calls',
            'max_tokens' => 'length',
            default => 'stop',
        };
    }

    /**
     * GET /v1/models — returns every model the account currently has access
     * to, each with a human-readable "display_name" straight from Anthropic.
     */
    public function listModels(AiGatewayProvider $provider): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($provider->base_url ?: self::BASE_URL, '/');

        $response = Http::withToken($apiKey)
            ->withHeaders(['anthropic-version' => '2023-06-01'])
            ->timeout(config('aigateway.timeout', 30))
            ->get($base.'/v1/models', ['limit' => 1000]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: (string) $response->body();
            $message = $message !== '' ? $message : 'HTTP '.$response->status().' with no error details.';
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::upstream($provider->name, $message, $response->status());
        }

        return collect($response->json('data') ?? [])
            ->filter(fn ($m) => is_array($m) && ! empty($m['id']))
            ->map(fn (array $m): array => [
                'name' => (string) $m['id'],
                'display_name' => $m['display_name'] ?? null,
                'context_window' => null,
                'max_output_tokens' => null,
                'input_price' => null,
                'output_price' => null,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}

