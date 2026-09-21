<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use App\Services\ChatEngine\MediaUnderstandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class InstagramAdapter implements ChannelAdapter
{
    public function __construct(private readonly MediaUnderstandingService $media)
    {
    }

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
        $senderId = $payload['sender']['id'] ?? null;

        if (! $senderId || ($payload['message']['is_echo'] ?? false)) {
            return null;
        }

        $text = $payload['message']['text'] ?? null;
        $text = $this->resolveAttachmentText($payload['message']['attachments'] ?? null, is_string($text) ? $text : null);

        if (! $text) {
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

    /**
     * Instagram DM attachment URLs (image/audio) mirror Messenger's shape
     * and are already public, pre-signed CDN links.
     */
    private function resolveAttachmentText(?array $attachments, ?string $text): ?string
    {
        if (! is_array($attachments)) {
            return $text;
        }

        foreach ($attachments as $attachment) {
            $url = $attachment['payload']['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            if (($attachment['type'] ?? null) === 'audio') {
                $transcript = $this->media->transcribeAudio($url);

                return $transcript ?: '[The customer sent a voice message that could not be transcribed. Ask them to type their message instead.]';
            }

            if (($attachment['type'] ?? null) === 'image') {
                $extracted = $this->media->extractTextFromImage($url);

                if ($extracted === null) {
                    return $text ?: '[The customer sent an image with no readable text in it. This assistant cannot see image contents, only read text within them — ask the customer to describe what they need.]';
                }

                return $text ? "{$text}\n\n[Text found in the image]: {$extracted}" : $extracted;
            }
        }

        return $text;
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
