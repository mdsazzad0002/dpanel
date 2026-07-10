<?php

namespace App\Services\ServerPanel;

use Illuminate\Support\Facades\Http;
use Throwable;

class ChatGptService
{
    public function reply(string $input, array $server = []): string
    {
        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            return 'OpenAI API key is not configured. Please set OPENAI_API_KEY.';
        }

        try {
            $response = Http::withToken($apiKey)
                ->baseUrl((string) config('services.openai.base_url', 'https://api.openai.com/v1'))
                ->timeout((int) config('serverpanel.ai.timeout', 45))
                ->acceptJson()
                ->post('/chat/completions', [
                    'model' => (string) config('serverpanel.ai.model', 'gpt-4.1-mini'),
                    'temperature' => (float) config('serverpanel.ai.temperature', 0.2),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are ServerPanel assistant. Reply concise, practical, and safe for server operations.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildPrompt($input, $server),
                        ],
                    ],
                ])
                ->throw()
                ->json();

            $content = trim((string) data_get($response, 'choices.0.message.content', ''));
            return $content !== '' ? $content : 'I could not generate a response.';
        } catch (Throwable) {
            return 'AI service is temporarily unavailable. Please try again.';
        }
    }

    private function buildPrompt(string $input, array $server): string
    {
        $context = [
            'input' => $input,
            'server' => $server,
        ];

        return 'User request + context: '.json_encode($context, JSON_UNESCAPED_SLASHES);
    }
}
