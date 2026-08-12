<?php

namespace App\Services\AiGateway\Providers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\Contracts\ProviderAdapter;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use Illuminate\Support\Facades\Http;

/**
 * Handles OpenAI and any OpenAI-compatible endpoint (used for Codex and
 * local model servers such as Ollama / vLLM / LM Studio).
 */
class OpenAiAdapter implements ProviderAdapter
{
    public function supportsDriver(string $driver): bool
    {
        return in_array($driver, ['openai', 'openai_compatible'], true);
    }

    public static function drivers(): array
    {
        return ['openai' => 'OpenAI', 'openai_compatible' => 'OpenAI-compatible'];
    }

    public function chat(AiGatewayProvider $provider, string $model, array $messages, array $options = []): array
    {
        $apiKey = $provider->getApiKey();

        if (! $apiKey) {
            throw AiGatewayException::missingCredentials($provider->name);
        }

        $base = rtrim((string) ($provider->base_url ?: 'https://api.openai.com'), '/');

        $system = $options['system'] ?? null;
        $chatMessages = $messages;

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

        $request = Http::withToken($apiKey)
            ->timeout($options['timeout'] ?? config('aigateway.timeout', 90));

        $response = $request->post($base.'/chat/completions', $body);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: (string) $response->body();
            throw AiGatewayException::upstream($provider->name, $message);
        }

        $json = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? '';
        $usage = $json['usage'] ?? [];

        return [
            'content' => (string) $content,
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'model' => $json['model'] ?? $model,
            'raw' => $json,
        ];
    }

    public function ping(AiGatewayProvider $provider): array
    {
        $model = $provider->default_model;

        try {
            $payload = ['model' => $model ?? 'gpt-4.1-mini', 'messages' => [['role' => 'user', 'content' => 'Say OK']], 'max_tokens' => 8];

            $base = rtrim((string) ($provider->base_url ?: 'https://api.openai.com'), '/');
            $response = Http::withToken((string) $provider->getApiKey())
                ->timeout(config('aigateway.timeout', 30))
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
}
