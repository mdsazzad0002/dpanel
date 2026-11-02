<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayModel;
use Illuminate\Support\Str;

/**
 * Finds out what a chat model can really do by asking it: a plain reply
 * (chat), a small code question with one right answer (code), a request
 * that should come back as a tool call (tools), and a picture whose colour
 * it must name (vision). Each probe is one short call straight to the model
 * on its own provider, so the result is about that model alone.
 *
 * Image generation and transcription aren't probed (they cost money per
 * call and are known from the provider's model list), so they're kept as
 * they were.
 */
class ModelProber
{
    public function __construct(private readonly AiGatewayService $gateway)
    {
    }

    /**
     * @return array{ok: bool, message: string, latency_ms: int, output_tokens: int, tokens_per_second: float, probes: array<string, array{ok: bool, note: string}>, capabilities: array<int, string>|null}
     */
    public function probe(AiGatewayModel $model, ?int $userId = null): array
    {
        $started = microtime(true);
        try {
            $chat = $this->ask($model, 'Say hello and name one thing you can help with, in one short sentence.', $userId);
        } catch (\Throwable $e) {
            // Down, bad key or unknown model: nothing to learn, change nothing.
            return [
                'ok' => false,
                'message' => Str::limit($e->getMessage(), 300),
                'latency_ms' => $this->ms($started),
                'output_tokens' => 0,
                'tokens_per_second' => 0.0,
                'probes' => [],
                'capabilities' => null,
            ];
        }

        $seconds = max(microtime(true) - $started, 0.001);
        $reply = trim((string) $chat['content']);
        $probes = ['chat' => ['ok' => $reply !== '', 'note' => $reply !== '' ? 'Answered' : 'Empty reply']];

        if ($reply !== '') {
            $probes['code'] = $this->probeCode($model, $userId);
            $probes['tools'] = $this->probeTools($model, $userId);
            $probes['vision'] = $this->probeVision($model, $userId);
        }

        return [
            'ok' => $reply !== '',
            'message' => $reply !== '' ? Str::limit($reply, 160) : 'The model answered with an empty reply.',
            'latency_ms' => (int) round($seconds * 1000),
            'output_tokens' => (int) $chat['output_tokens'],
            // Whole request, so it includes the wait for the first token.
            'tokens_per_second' => round($chat['output_tokens'] / $seconds, 1),
            'probes' => $probes,
            'capabilities' => $reply !== '' ? $this->capabilitiesFrom($model, $probes) : null,
        ];
    }

    /** Passed probes, plus the kinds of work that weren't probed. */
    private function capabilitiesFrom(AiGatewayModel $model, array $probes): array
    {
        $found = array_keys(array_filter($probes, fn (array $probe) => $probe['ok']));
        $kept = array_intersect($model->capabilities ?? [], Router::SPECIAL_CAPABILITIES);

        return ModelCatalog::normalise([...$found, ...$kept]);
    }

    private function probeCode(AiGatewayModel $model, ?int $userId): array
    {
        return $this->attempt(function () use ($model, $userId) {
            $answer = (string) $this->ask($model, "What does this Python code print? Reply with only the number.\n\nprint(sum(x * x for x in range(4)))", $userId)['content'];

            return preg_match('/\b14\b/', $answer)
                ? [true, 'Solved the code question']
                : [false, 'Wrong answer: '.Str::limit(trim($answer), 60)];
        });
    }

    private function probeTools(AiGatewayModel $model, ?int $userId): array
    {
        return $this->attempt(function () use ($model, $userId) {
            $result = $this->gateway->chatWithProvider($model->provider, $model, $model->name, [
                ['role' => 'user', 'content' => 'What is the weather in Dhaka right now? Use the get_weather tool.'],
            ], $this->options($userId) + [
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Current weather for a city.',
                        'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                    ],
                ]],
                'tool_choice' => 'auto',
            ]);
            $names = array_map(fn ($call) => data_get($call, 'function.name', data_get($call, 'name')), $result['tool_calls'] ?? []);

            return in_array('get_weather', $names, true)
                ? [true, 'Called the tool']
                : [false, 'Answered in text instead of calling the tool'];
        });
    }

    private function probeVision(AiGatewayModel $model, ?int $userId): array
    {
        return $this->attempt(function () use ($model, $userId) {
            // Marked as vision for this call only, so the image is sent as is
            // instead of being swapped for OCR text.
            $asVision = $model->replicate();
            $asVision->capabilities = [...($model->capabilities ?? []), 'vision'];
            $answer = (string) $this->gateway->chatWithProvider($model->provider, $asVision, $model->name, [[
                'role' => 'user',
                'content' => 'What colour is this square? Reply with one word.',
                'images' => [['mime' => 'image/png', 'data' => base64_encode($this->redSquare())]],
            ]], $this->options($userId))['content'];

            return str_contains(strtolower($answer), 'red')
                ? [true, 'Saw the picture']
                : [false, 'Could not see the picture: '.Str::limit(trim($answer), 60)];
        });
    }

    private function ask(AiGatewayModel $model, string $prompt, ?int $userId): array
    {
        return $this->gateway->chatWithProvider($model->provider, $model, $model->name, [
            ['role' => 'user', 'content' => $prompt],
        ], $this->options($userId));
    }

    private function options(?int $userId): array
    {
        return ['temperature' => 0, 'max_tokens' => 48, 'channel' => 'model_test', 'created_by' => $userId];
    }

    /** A provider error (e.g. "tools not supported") counts as a failed probe. */
    private function attempt(callable $probe): array
    {
        try {
            [$ok, $note] = $probe();
        } catch (\Throwable $e) {
            [$ok, $note] = [false, 'Not supported: '.Str::limit($e->getMessage(), 80)];
        }

        return ['ok' => $ok, 'note' => $note];
    }

    private function redSquare(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, imagecolorallocate($image, 220, 20, 20));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
