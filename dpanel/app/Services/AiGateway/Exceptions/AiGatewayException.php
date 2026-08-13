<?php

namespace App\Services\AiGateway\Exceptions;

use RuntimeException;

class AiGatewayException extends RuntimeException
{
    public ?int $upstreamStatus = null;

    public static function noActiveProvider(): self
    {
        return new self('No active AI provider is available. Add and enable a provider in the AI Gateway.');
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("No adapter registered for AI provider driver [{$driver}].");
    }

    public static function missingCredentials(string $provider): self
    {
        return new self("AI provider [{$provider}] has no API credentials configured.");
    }

    public static function upstream(string $provider, string $message, ?int $status = null): self
    {
        $e = new self("AI provider [{$provider}] returned an error: {$message}");
        $e->upstreamStatus = $status;

        return $e;
    }

    /**
     * Whether this failure looks like a rate-limit/quota/credit exhaustion
     * issue — the class of failure worth automatically failing over to
     * another provider for. Auth/bad-request errors are not, since another
     * provider won't fix a malformed request or a bad key.
     */
    public function isRateLimitOrQuota(): bool
    {
        if (in_array($this->upstreamStatus, [429, 402], true)) {
            return true;
        }

        return (bool) preg_match('/rate.?limit|quota|insufficient.{0,20}credit|too many requests/i', $this->getMessage());
    }

    /**
     * How many seconds the provider itself said to wait before retrying
     * (e.g. Groq/OpenAI's "Please try again in 7.725s"), if it said so.
     * Used to give a short, precise cooldown instead of a blanket one when
     * the provider tells us it'll recover quickly.
     */
    public function suggestedRetrySeconds(): ?int
    {
        if (preg_match('/try again in\s+([\d.]+)\s*s\b/i', $this->getMessage(), $m)) {
            return (int) ceil((float) $m[1]);
        }

        if (preg_match('/retry.?after[:\s]+([\d.]+)/i', $this->getMessage(), $m)) {
            return (int) ceil((float) $m[1]);
        }

        return null;
    }
}
