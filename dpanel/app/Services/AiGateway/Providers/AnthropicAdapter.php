<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use Illuminate\Support\Facades\Http;

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
        $system = $options['system'] ?? null;

        // Anthropic wants system prompts as a top-level "system" field and only
        // user/assistant messages in the message array.
        $converted = [];
        foreach ($messages as $message) {
            $role = $message['role'];
            if ($role === 'system') {
                $system = $system ? $system."\n\n".$message['content'] : $message['content'];
                continue;
            }

            $converted[] = [
                'role' => $role,
                'content' => (string) $message['content'],
            ];
        }

        $body = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'messages' => array_values($converted),
        ];

        if ($system !== null && $system !== '') {
            $body['system'] = (string) $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
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
        $content = collect($json['content'] ?? [])
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
        ];
    }

    public function stream(AiGatewayProvider $provider, string $model, array $messages, array $options, \Closure $onDelta): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim($provider->base_url ?: self::BASE_URL, '/');
        $system = $options['system'] ?? null;

        $converted = [];
        foreach ($messages as $message) {
            $role = $message['role'];
            if ($role === 'system') {
                $system = $system ? $system."\n\n".$message['content'] : $message['content'];
                continue;
            }

            $converted[] = [
                'role' => $role,
                'content' => (string) $message['content'],
            ];
        }

        $body = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'messages' => array_values($converted),
            'stream' => true,
        ];

        if ($system !== null && $system !== '') {
            $body['system'] = (string) $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
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
        $raw = [];

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

                if ($type === 'message_start') {
                    $inputTokens = (int) ($json['message']['usage']['input_tokens'] ?? $inputTokens);
                    $finalModel = $json['message']['model'] ?? $finalModel;
                } elseif ($type === 'content_block_delta') {
                    $delta = $json['delta']['text'] ?? '';
                    if ($delta !== '') {
                        $content .= $delta;
                        $onDelta($delta);
                    }
                } elseif ($type === 'message_delta') {
                    $outputTokens = (int) ($json['usage']['output_tokens'] ?? $outputTokens);
                }
            }
        }

        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $finalModel,
            'raw' => $raw,
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

