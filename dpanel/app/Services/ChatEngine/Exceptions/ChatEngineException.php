<?php

namespace App\Services\ChatEngine\Exceptions;

class ChatEngineException extends \RuntimeException
{
    public static function unsupportedChannelType(string $type): self
    {
        return new self("Unsupported chat channel type: {$type}");
    }

    public static function invalidWebhookSignature(): self
    {
        return new self('Webhook signature verification failed.');
    }

    public static function sendFailed(string $message): self
    {
        return new self("Failed to send channel message: {$message}");
    }
}
