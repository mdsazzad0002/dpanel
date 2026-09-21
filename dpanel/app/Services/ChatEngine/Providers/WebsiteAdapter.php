<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use Illuminate\Http\Request;

/**
 * The embeddable website widget isn't an async webhook platform like the
 * others — a visitor's browser calls our public widget endpoint and expects
 * the AI's reply in that same HTTP response. So there's no external
 * platform to verify/push to: verifyWebhook()/setupWebhook() are unused,
 * and sendMessage() is a no-op (the message is already persisted by
 * ChatEngineService; WebsiteChatWidgetController reads it back directly).
 */
class WebsiteAdapter implements ChannelAdapter
{
    public function driver(): string
    {
        return 'website';
    }

    public function verifyWebhook(ChatChannel $channel, Request $request): bool
    {
        return true;
    }

    public function normalizeInbound(ChatChannel $channel, array $payload): ?InboundMessage
    {
        $text = trim((string) ($payload['message'] ?? ''));

        if ($text === '') {
            return null;
        }

        return new InboundMessage(
            externalContactId: (string) $payload['contact_token'],
            contactName: null,
            contactUsername: null,
            text: $text,
            externalMessageId: null,
            raw: $payload,
        );
    }

    public function sendMessage(ChatChannel $channel, string $externalContactId, string $content, array $context = []): array
    {
        return ['external_message_id' => null];
    }

    public function setupWebhook(ChatChannel $channel, string $webhookUrl): void
    {
    }
}
