<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use Illuminate\Support\Facades\Http;

class AnthropicAdapter implements ProviderAdapter
{
    public function supportsDriver(string $driver): bool
    {
        return $driver === 'anthropic';
    }

    public static function drivers(): array
    {
        return ['anthropic' => 'Claude (Anthropic)'];
    }

    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim((string) ($provider->base_url ?: 'https://api.anthropic.com'), '/');
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
            throw \App\Services\AiGateway\Exceptions\AiGatewayException::upstream($provider->name, $message);
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
}
