<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramAdapter implements ChannelAdapter
{
    public function driver(): string
    {
        return 'telegram';
    }

    public function verifyWebhook(ChatChannel $channel, Request $request): bool
    {
        $expected = $channel->webhook_secret;

        if (! $expected) {
            return false;
        }

        return hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    public function normalizeInbound(ChatChannel $channel, array $payload): ?InboundMessage
    {
        $message = $payload['message'] ?? $payload['edited_message'] ?? null;

        if (! $message || ! isset($message['chat']['id'])) {
            return null;
        }

        $text = $message['text'] ?? $message['caption'] ?? null;

        if ($text === null) {
            return null;
        }

        $from = $message['from'] ?? [];

        return new InboundMessage(
            externalContactId: (string) $message['chat']['id'],
            contactName: trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: null,
            contactUsername: $from['username'] ?? null,
            text: $text,
            externalMessageId: isset($message['message_id']) ? (string) $message['message_id'] : null,
            raw: $payload,
        );
    }

    public function sendMessage(ChatChannel $channel, string $externalContactId, string $content, array $context = []): array
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.telegram.api_base_url'))
            ->post("/bot{$channel->getBotToken()}/sendMessage", [
                'chat_id' => $externalContactId,
                'text' => $content,
            ]);

        if (! $response->successful() || ! ($response->json('ok'))) {
            throw ChatEngineException::sendFailed($response->json('description') ?? $response->body());
        }

        return [
            'external_message_id' => isset($response->json('result')['message_id'])
                ? (string) $response->json('result')['message_id']
                : null,
        ];
    }

    public function setupWebhook(ChatChannel $channel, string $webhookUrl): void
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.telegram.api_base_url'))
            ->post("/bot{$channel->getBotToken()}/setWebhook", [
                'url' => $webhookUrl,
                'secret_token' => $channel->webhook_secret,
            ]);

        if (! $response->successful() || ! ($response->json('ok'))) {
            throw ChatEngineException::sendFailed($response->json('description') ?? $response->body());
        }
    }
}
