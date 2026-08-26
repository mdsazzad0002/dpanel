<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class InstagramAdapter implements ChannelAdapter
{
    public function driver(): string
    {
        return 'instagram';
    }

    public function verifyWebhook(ChatChannel $channel, Request $request): bool
    {
        $secret = $channel->getInstagramAppSecret();
        $signature = (string) $request->header('X-Hub-Signature-256');

        return $secret && str_starts_with($signature, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function normalizeInbound(ChatChannel $channel, array $payload): ?InboundMessage
    {
        $text = $payload['message']['text'] ?? null;
        $senderId = $payload['sender']['id'] ?? null;

        if (! $senderId || ! is_string($text) || trim($text) === '' || ($payload['message']['is_echo'] ?? false)) {
            return null;
        }

        return new InboundMessage(
            externalContactId: (string) $senderId,
            contactName: null,
            contactUsername: null,
            text: $text,
            externalMessageId: $payload['message']['mid'] ?? null,
            raw: $payload,
        );
    }

    public function sendMessage(ChatChannel $channel, string $externalContactId, string $content, array $context = []): array
    {
        $response = Http::timeout(30)
            ->withToken((string) $channel->getInstagramAccessToken())
            ->post(rtrim(config('chatengine.instagram.api_base_url'), '/').'/me/messages', [
                'recipient' => ['id' => $externalContactId],
                'message' => ['text' => $content],
            ]);

        if ($response->failed() || ! $response->json('message_id')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return ['external_message_id' => $response->json('message_id')];
    }

    public function setupWebhook(ChatChannel $channel, string $webhookUrl): void
    {
        $response = Http::timeout(30)
            ->withToken((string) $channel->getInstagramAccessToken())
            ->post(rtrim(config('chatengine.instagram.api_base_url'), '/')."/{$channel->external_account_id}/subscribed_apps", [
                'subscribed_fields' => 'messages',
            ]);

        if ($response->failed() || ! $response->json('success')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }
    }
}
