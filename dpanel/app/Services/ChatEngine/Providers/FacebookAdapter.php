<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Models\ChatFacebookApp;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use App\Services\ChatEngine\MediaUnderstandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Facebook Page adapter — Messenger messages and public feed comments come
 * through a webhook covering every page subscribed to a given Facebook App.
 * Each registered ChatFacebookApp gets its own callback URL
 * (/webhooks/chat/facebook/{app}), so different people/orgs each running
 * their own Facebook App never share an endpoint or a secret; a legacy
 * shared URL (/webhooks/chat/facebook, no app id) is also kept for channels
 * connected manually before per-app registration existed. See
 * FacebookChatWebhookController for how a single payload is split across
 * possibly multiple pages before normalizeInbound() is called.
 */
class FacebookAdapter implements ChannelAdapter
{
    public function __construct(private readonly MediaUnderstandingService $media)
    {
    }

    public function driver(): string
    {
        return 'facebook';
    }

    /**
     * Verify a payload against one specific app's own secret — used by the
     * per-app webhook URL, so one tenant's app can never be spoofed using
     * another tenant's (or the legacy shared) secret.
     */
    public function verifySignatureForApp(Request $request, ChatFacebookApp $app): bool
    {
        return $this->matchesSignature($request, [$app->app_secret]);
    }

    /**
     * Verify a payload against every active app's secret plus the legacy
     * config-based secret — used only by the legacy shared webhook URL kept
     * for backward compatibility with manually-connected channels.
     */
    public function verifyAppSignature(Request $request): bool
    {
        $secrets = ChatFacebookApp::query()->where('is_active', true)->pluck('app_secret')->all();

        if ($legacySecret = config('chatengine.facebook.app_secret')) {
            $secrets[] = $legacySecret;
        }

        return $this->matchesSignature($request, $secrets);
    }

    private function matchesSignature(Request $request, array $secrets): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! $signature || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $body = $request->getContent();

        foreach ($secrets as $secret) {
            $expected = 'sha256='.hash_hmac('sha256', $body, (string) $secret);

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function verifyWebhook(ChatChannel $channel, Request $request): bool
    {
        return $this->verifyAppSignature($request);
    }

    /**
     * @param  array{type: string, data: array}  $payload  Wrapped by FacebookChatWebhookController:
     *                                                      type "messaging" for a Messenger message,
     *                                                      "comment" for a feed comment.
     */
    public function normalizeInbound(ChatChannel $channel, array $payload): ?InboundMessage
    {
        return match ($payload['type'] ?? null) {
            'messaging' => $this->normalizeMessaging($channel, $payload['data'] ?? []),
            'comment' => $this->normalizeComment($channel, $payload['data'] ?? []),
            default => null,
        };
    }

    private function normalizeMessaging(ChatChannel $channel, array $data): ?InboundMessage
    {
        $text = $data['message']['text'] ?? null;

        // Skip echoes of the page's own sent messages, delivery/read
        // receipts, and anything without a psid.
        if (($data['message']['is_echo'] ?? false) || empty($data['sender']['id'])) {
            return null;
        }

        $text = $this->resolveAttachmentText($data['message']['attachments'] ?? null, $text);

        if (! $text) {
            return null;
        }

        return new InboundMessage(
            externalContactId: (string) $data['sender']['id'],
            contactName: null,
            contactUsername: null,
            text: $text,
            externalMessageId: $data['message']['mid'] ?? null,
            raw: $data,
            kind: 'message',
            replyContext: ['kind' => 'message'],
        );
    }

    /**
     * Messenger attachment URLs (image/audio/video/file) are already
     * public, pre-signed CDN links — unlike WhatsApp/Slack, no bearer
     * token is needed to fetch them.
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

    private function normalizeComment(ChatChannel $channel, array $data): ?InboundMessage
    {
        if (($data['verb'] ?? null) !== 'add' || empty($data['comment_id']) || ! isset($data['message'])) {
            return null;
        }

        // Ignore the page replying to its own comments (avoid echo loops).
        if (($data['from']['id'] ?? null) === $channel->external_account_id) {
            return null;
        }

        return new InboundMessage(
            externalContactId: (string) ($data['from']['id'] ?? $data['comment_id']),
            contactName: $data['from']['name'] ?? null,
            contactUsername: null,
            text: $data['message'],
            externalMessageId: $data['comment_id'],
            raw: $data,
            kind: 'comment',
            replyContext: ['kind' => 'comment', 'comment_id' => $data['comment_id']],
        );
    }

    public function sendMessage(ChatChannel $channel, string $externalContactId, string $content, array $context = []): array
    {
        if (($context['kind'] ?? 'message') === 'comment') {
            return $this->replyToComment($channel, $context['comment_id'] ?? '', $content);
        }

        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->post('/me/messages', [
                'recipient' => ['id' => $externalContactId],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $content],
                'access_token' => $channel->getPageAccessToken(),
            ]);

        if ($response->failed()) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return ['external_message_id' => $response->json('message_id')];
    }

    private function replyToComment(ChatChannel $channel, string $commentId, string $content): array
    {
        if ($commentId === '') {
            throw ChatEngineException::sendFailed('Missing comment_id to reply to.');
        }

        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->post("/{$commentId}/comments", [
                'message' => $content,
                'access_token' => $channel->getPageAccessToken(),
            ]);

        if ($response->failed()) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return ['external_message_id' => $response->json('id')];
    }

    /**
     * Publish a post directly to a connected Facebook Page's feed.
     *
     * @return array{id: string|null, permalink_url: string|null}
     */
    public function publishPagePost(ChatChannel $channel, string $message, ?string $link = null): array
    {
        $payload = [
            'message' => $message,
            'access_token' => $channel->getPageAccessToken(),
        ];

        if ($link) {
            $payload['link'] = $link;
        }

        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->post("/{$channel->external_account_id}/feed", $payload);

        if ($response->failed() || ! $response->json('id')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        $postId = $response->json('id');

        return [
            'id' => $postId,
            'permalink_url' => $postId ? 'https://www.facebook.com/'.str_replace('_', '/posts/', $postId) : null,
        ];
    }

    public function commentOnPagePost(ChatChannel $channel, string $postId, string $message): string
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->post("/{$postId}/comments", [
                'message' => $message,
                'access_token' => $channel->getPageAccessToken(),
            ]);

        if ($response->failed() || ! $response->json('id')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return (string) $response->json('id');
    }

    /**
     * Subscribe this page to the app's webhook fields (messages + feed
     * comments). The webhook callback URL itself is configured once, at the
     * app level, in the Meta developer dashboard — $webhookUrl is accepted
     * only to satisfy the shared ChannelAdapter contract.
     */
    public function setupWebhook(ChatChannel $channel, string $webhookUrl): void
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->post("/{$channel->external_account_id}/subscribed_apps", [
                'subscribed_fields' => 'messages,feed',
                'access_token' => $channel->getPageAccessToken(),
            ]);

        if ($response->failed() || ! $response->json('success')) {
            $message = $response->json('error.message') ?? $response->body();

            if (str_contains((string) $message, 'pages_messaging')) {
                $message .= ' The Page was added, but Messenger cannot work until the Meta app has the Messenger product/use case and pages_messaging Advanced Access, and the Page is reconnected afterward.';
            }

            throw ChatEngineException::sendFailed($message);
        }
    }
}
