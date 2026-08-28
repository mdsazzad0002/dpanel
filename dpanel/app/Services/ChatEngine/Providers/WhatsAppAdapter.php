<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use App\Services\ChatEngine\MediaUnderstandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppAdapter implements ChannelAdapter
{
    public function __construct(private readonly MediaUnderstandingService $media)
    {
    }

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
        $contactId = $message['from'] ?? null;

        if (! $contactId) {
            return null;
        }

        $text = $message['text']['body'] ?? null;
        $text = $this->resolveAttachmentText($channel, $message, is_string($text) ? $text : null);

        if (! $text || trim($text) === '') {
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

    /**
     * A WhatsApp voice/image message carries only a media id — resolving it
     * to a downloadable URL (GET /{media-id}) and then actually fetching
     * that URL both require the same access token, unlike Telegram/
     * Facebook/Instagram whose attachment URLs are already public.
     */
    private function resolveAttachmentText(ChatChannel $channel, array $message, ?string $text): ?string
    {
        $type = $message['type'] ?? null;

        if ($type === 'audio' || $type === 'voice') {
            $mediaId = $message['audio']['id'] ?? $message['voice']['id'] ?? null;
            $url = is_string($mediaId) ? $this->mediaUrl($channel, $mediaId) : null;
            $transcript = $url ? $this->media->transcribeAudio($url, null, $this->authHeaders($channel)) : null;

            return $transcript ?: '[The customer sent a voice message that could not be transcribed. Ask them to type their message instead.]';
        }

        if ($type === 'image') {
            $mediaId = $message['image']['id'] ?? null;
            $caption = $message['image']['caption'] ?? null;
            $url = is_string($mediaId) ? $this->mediaUrl($channel, $mediaId) : null;
            $extracted = $url ? $this->media->extractTextFromImage($url, $this->authHeaders($channel)) : null;

            if ($extracted === null) {
                return $caption ?: '[The customer sent an image with no readable text in it. This assistant cannot see image contents, only read text within them — ask the customer to describe what they need.]';
            }

            return $caption ? "{$caption}\n\n[Text found in the image]: {$extracted}" : $extracted;
        }

        return $text;
    }

    private function mediaUrl(ChatChannel $channel, string $mediaId): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withToken((string) $channel->getWhatsAppAccessToken())
                ->get(rtrim(config('chatengine.whatsapp.api_base_url'), '/')."/{$mediaId}");

            return $response->successful() ? $response->json('url') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<string,string> */
    private function authHeaders(ChatChannel $channel): array
    {
        $token = (string) $channel->getWhatsAppAccessToken();

        return $token !== '' ? ['Authorization' => "Bearer {$token}"] : [];
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
