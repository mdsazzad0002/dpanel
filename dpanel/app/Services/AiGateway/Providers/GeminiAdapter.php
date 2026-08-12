<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use Illuminate\Support\Facades\Http;

class GeminiAdapter implements ProviderAdapter
{
    public function supportsDriver(string $driver): bool
    {
        return $driver === 'gemini';
    }

    public static function drivers(): array
    {
        return ['gemini' => 'Google Gemini'];
    }

    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim((string) ($provider->base_url ?: 'https://generativelanguage.googleapis.com'), '/');

        $system = $options['system'] ?? null;
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system = $system ? $system."\n\n".$message['content'] : $message['content'];
            }
        }

        $contents = collect($messages)
            ->where('role', '!=', 'system')
            ->map(fn (array $message): array => [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $message['content']]],
            ])
            ->values()
            ->all();

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

        $url = $base.'/v1beta/models/'.$model.':generateContent?key='.urlencode($apiKey);

        $response = Http::timeout($options['timeout'] ?? config('aigateway.timeout', 90))
            ->acceptJson()
            ->post($url, $body);

        if ($response->failed()) {
            $error = $response->json('error.message') ?: $response->json('error.status') ?: (string) $response->body();
            throw AiGatewayException::upstream($provider->name, $error);
        }

        $json = $response->json();
        $content = collect($json['candidates'][0]['content']['parts'] ?? [])
            ->where('text', ! null)
            ->pluck('text')
            ->implode("\n");

        $metadata = $json['usageMetadata'] ?? [];

        return [
            'content' => (string) $content,
            'input_tokens' => (int) ($metadata['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($metadata['candidatesTokenCount'] ?? 0),
            'model' => $model,
            'raw' => $json,
        ];
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
}
