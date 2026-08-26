<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppAdapter implements ChannelAdapter
{
    public function driver(): string
    {
        return 'whatsapp';
    }

    public function verifyWebhook(ChatChannel $channel, Request $request): bool
    {
        $secret = $channel->getWhatsAppAppSecret();
        $signature = (string) $request->header('X-Hub-Signature-256');

        return $secret && str_starts_with($signature, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function normalizeInbound(ChatChannel $channel, array $payload): ?InboundMessage
    {
        $message = $payload['message'] ?? [];
        $text = $message['text']['body'] ?? null;
        $contactId = $message['from'] ?? null;

        if (! $contactId || ! is_string($text) || trim($text) === '') {
            return null;
        }

        return new InboundMessage(
            externalContactId: (string) $contactId,
            contactName: $payload['contact']['profile']['name'] ?? null,
            contactUsername: null,
            text: $text,
            externalMessageId: $message['id'] ?? null,
            raw: $payload,
        );
    }

    public function sendMessage(ChatChannel $channel, string $externalContactId, string $content, array $context = []): array
    {
        $response = Http::timeout(30)
            ->withToken((string) $channel->getWhatsAppAccessToken())
            ->post(rtrim(config('chatengine.whatsapp.api_base_url'), '/')."/{$channel->external_account_id}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $externalContactId,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $content],
            ]);

        if ($response->failed() || ! $response->json('messages.0.id')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return ['external_message_id' => $response->json('messages.0.id')];
    }

    public function setupWebhook(ChatChannel $channel, string $webhookUrl): void
    {
        $wabaId = $channel->getWhatsAppBusinessAccountId();
        if (! $wabaId) {
            throw ChatEngineException::sendFailed('Missing WhatsApp Business Account ID.');
        }

        $response = Http::timeout(30)
            ->withToken((string) $channel->getWhatsAppAccessToken())
            ->post(rtrim(config('chatengine.whatsapp.api_base_url'), '/')."/{$wabaId}/subscribed_apps");

        if ($response->failed() || ! $response->json('success')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }
    }
}
