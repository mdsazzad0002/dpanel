<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use App\Services\ChatEngine\MediaUnderstandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramAdapter implements ChannelAdapter
{
    public function __construct(private readonly MediaUnderstandingService $media)
    {
    }

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
        $text = $this->resolveAttachmentText($channel, $message, $text);

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

    /**
     * A voice note has no `text`/`caption` at all — only a `voice`/`audio`
     * object — and a photo's caption (if any) says nothing about what's
     * actually in the picture. Both go through MediaUnderstandingService so
     * the AI gets something to read either way. Crucially, once we know
     * there *is* an attachment, this never falls back to returning the
     * original (possibly null) $text on failure — OCR/transcription
     * finding nothing is itself worth telling the AI, so it can ask the
     * customer to describe the image/repeat themselves instead of the
     * message silently vanishing with no reply at all.
     */
    private function resolveAttachmentText(ChatChannel $channel, array $message, ?string $text): ?string
    {
        $voice = $message['voice'] ?? $message['audio'] ?? null;

        if (is_array($voice) && isset($voice['file_id'])) {
            $url = $this->fileUrl($channel, $voice['file_id']);
            $transcript = $url ? $this->media->transcribeAudio($url) : null;

            return $transcript ?: '[The customer sent a voice message that could not be transcribed. Ask them to type their message instead.]';
        }

        $photos = $message['photo'] ?? null;

        if (is_array($photos) && $photos !== []) {
            // Telegram sends the same photo at multiple resolutions,
            // smallest first — the last entry is the largest/clearest.
            $largest = end($photos);
            $url = is_array($largest) && isset($largest['file_id'])
                ? $this->fileUrl($channel, $largest['file_id'])
                : null;
            $extracted = $url ? $this->media->extractTextFromImage($url) : null;

            if ($extracted === null) {
                return $text ?: '[The customer sent an image with no readable text in it. This assistant cannot see image contents, only read text within them — ask the customer to describe what they need.]';
            }

            return $text ? "{$text}\n\n[Text found in the image]: {$extracted}" : $extracted;
        }

        return $text;
    }

    private function fileUrl(ChatChannel $channel, string $fileId): ?string
    {
        $token = $channel->getBotToken();

        if (! $token) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->baseUrl(config('chatengine.telegram.api_base_url'))
                ->get("/bot{$token}/getFile", ['file_id' => $fileId]);

            if (! $response->successful() || ! $response->json('ok')) {
                return null;
            }

            $path = $response->json('result.file_path');

            if (! $path) {
                return null;
            }

            return rtrim((string) config('chatengine.telegram.api_base_url'), '/')."/file/bot{$token}/{$path}";
        } catch (\Throwable $e) {
            return null;
        }
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
