<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use Illuminate\Support\Facades\Http;

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

        $base = rtrim(self::BASE_URL, '/');

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
            $error = $error !== '' ? $error : 'HTTP '.$response->status().' with no error details.';
            throw AiGatewayException::upstream($provider->name, $error, $response->status());
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

    public function stream(AiGatewayProvider $provider, string $model, array $messages, array $options, \Closure $onDelta): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim(self::BASE_URL, '/');

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

                $delta = collect($json['candidates'][0]['content']['parts'] ?? [])
                    ->pluck('text')
                    ->filter(fn ($t) => $t !== null)
                    ->implode('');

                if ($delta !== '') {
                    $content .= $delta;
                    $onDelta($delta);
                }

                $metadata = $json['usageMetadata'] ?? [];
                if ($metadata) {
                    $inputTokens = (int) ($metadata['promptTokenCount'] ?? $inputTokens);
                    $outputTokens = (int) ($metadata['candidatesTokenCount'] ?? $outputTokens);
                }
            }
        }

        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $model,
            'raw' => $raw,
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
