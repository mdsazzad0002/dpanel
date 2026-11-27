<?php

namespace App\Services\AiGateway;

use RuntimeException;
use Throwable;

/**
 * One-shot AI helpers for panel pages (mail, cron, logs, ...): whether any
 * AI Gateway model is active, and a single question with a cleaned answer.
 * Pages hide their AI buttons when available() is false.
 */
class PanelAi
{
    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly Router $router,
    ) {}

    /** An active AI Gateway model exists. */
    public function available(): bool
    {
        try {
            return $this->router->candidates()->isNotEmpty();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ask one question and return the answer, without a Markdown fence the
     * model may add anyway.
     *
     * @throws RuntimeException when the answer is empty (AiGatewayException is one too)
     */
    public function ask(string $system, string $content, string $operation, int $maxTokens = 1000, ?int $actorId = null, float $temperature = 0.2): string
    {
        $result = $this->gateway->chatAuto(null, [['role' => 'user', 'content' => $content]], [
            'system' => $system,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'channel' => 'panel',
            'operation' => $operation,
            'created_by' => $actorId,
        ]);

        $answer = trim((string) ($result['content'] ?? ''));
        if (preg_match('/^```[a-zA-Z]*\s*\n(.*?)\n?```$/s', $answer, $match)) {
            $answer = trim($match[1]);
        }
        if ($answer === '') {
            throw new RuntimeException('The AI gave an empty answer. Try again.');
        }

        return $answer;
    }

    /**
     * Ask for a JSON object. The first {...} in the answer is parsed, so a
     * sentence around it does not break the result.
     *
     * @return array<string, mixed>
     */
    public function askJson(string $system, string $content, string $operation, int $maxTokens = 800, ?int $actorId = null): array
    {
        $answer = $this->ask($system, $content, $operation, $maxTokens, $actorId, 0);
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($answer, $start, $end - $start + 1), true) : null;
        if (! is_array($data)) {
            throw new RuntimeException('The AI answer could not be read. Try again or rephrase.');
        }

        return $data;
    }
}
