<?php

namespace App\Services\ServerPanel;

use App\Services\ServerPanel\Contracts\AiSuggestionProvider;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiSuggestionProvider implements AiSuggestionProvider
{
    public function suggest(array $context): array
    {
        $apiKey = (string) config('services.openai.api_key', '');

        if ($apiKey === '') {
            return $this->fallback($context);
        }

        try {
            $response = Http::withToken($apiKey)
                ->baseUrl((string) config('services.openai.base_url', 'https://api.openai.com/v1'))
                ->timeout((int) config('serverpanel.ai.timeout', 45))
                ->acceptJson()
                ->post('/chat/completions', [
                    'model' => (string) config('serverpanel.ai.model', 'gpt-4.1-mini'),
                    'temperature' => (float) config('serverpanel.ai.temperature', 0.2),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a Linux server incident responder. Return only JSON with keys: problem_title, problem_summary, detected_cause, suggested_fix, fix_commands(array), risk_level(safe|approval_required|blocked), tags(array). Keep fixes minimal and safe-first.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildUserPrompt($context),
                        ],
                    ],
                ])
                ->throw()
                ->json();

            $content = (string) data_get($response, 'choices.0.message.content', '');
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                return $this->fallback($context);
            }

            return $this->sanitize($decoded, $context);
        } catch (Throwable) {
            return $this->fallback($context);
        }
    }

    private function buildUserPrompt(array $context): string
    {
        $payload = [
            'server' => $context['server'] ?? [],
            'command' => $context['command'] ?? '',
            'output' => mb_substr((string) ($context['output'] ?? ''), 0, 4000),
            'error_output' => mb_substr((string) ($context['error_output'] ?? ''), 0, 6000),
            'error_signature' => $context['error_signature'] ?? '',
            'memory_hint' => $context['memory_hint'] ?? null,
        ];

        return 'Analyze command failure and suggest safe remediation. Context JSON: '.json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function sanitize(array $data, array $context): array
    {
        $risk = (string) ($data['risk_level'] ?? 'approval_required');

        return [
            'problem_title' => (string) ($data['problem_title'] ?? 'Command failed'),
            'problem_summary' => (string) ($data['problem_summary'] ?? 'Command failed and needs review.'),
            'detected_cause' => (string) ($data['detected_cause'] ?? ''),
            'suggested_fix' => (string) ($data['suggested_fix'] ?? ''),
            'fix_commands' => array_values(array_filter((array) ($data['fix_commands'] ?? []), fn ($item): bool => is_string($item) && trim($item) !== '')),
            'risk_level' => in_array($risk, ['safe', 'approval_required', 'blocked'], true) ? $risk : 'approval_required',
            'tags' => array_values(array_filter((array) ($data['tags'] ?? []), fn ($item): bool => is_string($item) && trim($item) !== '')),
        ];
    }

    private function fallback(array $context): array
    {
        return (new HeuristicAiSuggestionProvider())->suggest($context);
    }
}
