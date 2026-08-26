<?php

namespace App\Services\ChatEngine\Providers;

use App\Models\ChatChannel;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SlackAdapter implements ChannelAdapter
{
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
        $text = $payload['text'] ?? null;
        $userId = $payload['user'] ?? null;
        $slackChannel = $payload['channel'] ?? null;

        if (! $userId || ! $slackChannel || ! is_string($text) || trim($text) === ''
            || isset($payload['bot_id']) || isset($payload['subtype'])) {
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
