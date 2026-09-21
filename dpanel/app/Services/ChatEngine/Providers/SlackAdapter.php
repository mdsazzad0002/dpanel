<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use App\Services\ChatEngine\MediaUnderstandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SlackAdapter implements ChannelAdapter
{
    public function __construct(private readonly MediaUnderstandingService $media)
    {
    }

    public function driver(): string
    {
        return 'slack';
    }

    public function verifyWebhook(ChatChannel $channel, Request $request): bool
    {
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp');
        $signature = (string) $request->header('X-Slack-Signature');
        $secret = $channel->getSlackSigningSecret();

        if (! $secret || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$request->getContent()}", $secret);

        return hash_equals($expected, $signature);
    }

    public function normalizeInbound(ChatChannel $channel, array $payload): ?InboundMessage
    {
        $userId = $payload['user'] ?? null;
        $slackChannel = $payload['channel'] ?? null;
        $subtype = $payload['subtype'] ?? null;

        // file_share is a real user message (with an attached file) — every
        // other subtype (message_changed, channel_join, ...) isn't.
        if (! $userId || ! $slackChannel || isset($payload['bot_id'])
            || ($subtype !== null && $subtype !== 'file_share')) {
            return null;
        }

        $text = $payload['text'] ?? null;
        $text = $this->resolveAttachmentText($channel, $payload['files'] ?? null, is_string($text) ? $text : null);

        if (! $text || trim($text) === '') {
            return null;
        }

        return new InboundMessage(
            externalContactId: "{$slackChannel}:{$userId}",
            contactName: (string) $userId,
            contactUsername: null,
            text: $text,
            externalMessageId: isset($payload['ts']) ? (string) $payload['ts'] : null,
            raw: $payload,
            replyContext: ['slack_channel' => $slackChannel],
        );
    }

    /**
     * Slack's file URLs (url_private) require the same bot token used to
     * talk to Slack's API, unlike Telegram/Facebook/Instagram whose
     * attachment URLs are already public.
     */
    private function resolveAttachmentText(ChatChannel $channel, ?array $files, ?string $text): ?string
    {
        if (! is_array($files)) {
            return $text;
        }

        foreach ($files as $file) {
            $url = $file['url_private'] ?? null;
            $mimetype = (string) ($file['mimetype'] ?? '');

            if (! is_string($url) || $url === '') {
                continue;
            }

            if (str_starts_with($mimetype, 'audio/')) {
                $transcript = $this->media->transcribeAudio($url, null, $this->authHeaders($channel));

                return $transcript ?: '[The customer sent a voice message that could not be transcribed. Ask them to type their message instead.]';
            }

            if (str_starts_with($mimetype, 'image/')) {
                $extracted = $this->media->extractTextFromImage($url, $this->authHeaders($channel));

                if ($extracted === null) {
                    return $text ?: '[The customer sent an image with no readable text in it. This assistant cannot see image contents, only read text within them — ask the customer to describe what they need.]';
                }

                return $text ? "{$text}\n\n[Text found in the image]: {$extracted}" : $extracted;
            }
        }

        return $text;
    }

    /** @return array<string,string> */
    private function authHeaders(ChatChannel $channel): array
    {
        $token = (string) $channel->getSlackBotToken();

        return $token !== '' ? ['Authorization' => "Bearer {$token}"] : [];
    }

    public function sendMessage(ChatChannel $channel, string $externalContactId, string $content, array $context = []): array
    {
        $slackChannel = $context['slack_channel'] ?? explode(':', $externalContactId, 2)[0] ?? null;
        if (! $slackChannel) {
            throw ChatEngineException::sendFailed('Missing Slack channel ID.');
        }

        $response = Http::timeout(30)
            ->withToken((string) $channel->getSlackBotToken())
            ->post(rtrim(config('chatengine.slack.api_base_url'), '/').'/chat.postMessage', [
                'channel' => $slackChannel,
                'text' => $content,
            ]);

        if ($response->failed() || ! $response->json('ok')) {
            throw ChatEngineException::sendFailed($response->json('error') ?? $response->body());
        }

        return ['external_message_id' => $response->json('ts')];
    }

    public function setupWebhook(ChatChannel $channel, string $webhookUrl): void
    {
        $response = Http::timeout(30)
            ->withToken((string) $channel->getSlackBotToken())
            ->post(rtrim(config('chatengine.slack.api_base_url'), '/').'/auth.test');

        if ($response->failed() || ! $response->json('ok')) {
            throw ChatEngineException::sendFailed($response->json('error') ?? $response->body());
        }

        if ($channel->external_account_id && (string) $response->json('team_id') !== (string) $channel->external_account_id) {
            throw ChatEngineException::sendFailed('The bot token belongs to a different Slack Workspace ID.');
        }
    }
}
