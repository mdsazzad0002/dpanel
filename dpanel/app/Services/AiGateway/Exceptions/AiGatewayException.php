<?php

namespace App\Services\AiGateway\Exceptions;

use RuntimeException;

class AiGatewayException extends RuntimeException
{
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

    public static function upstream(string $provider, string $message): self
    {
        return new self("AI provider [{$provider}] returned an error: {$message}");
    }
}
