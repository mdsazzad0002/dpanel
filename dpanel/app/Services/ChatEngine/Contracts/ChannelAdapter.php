<?php

namespace App\Services\ChatEngine\Contracts;

use App\Models\ChatChannel;
use App\Services\ChatEngine\DTO\InboundMessage;
use Illuminate\Http\Request;

interface ChannelAdapter
{
    /**
     * The channel type this adapter handles, e.g. "telegram".
     */
    public function driver(): string;

    /**
     * Verify an inbound webhook request actually came from the platform
     * (signature / secret token check) before any payload is trusted.
     */
    public function verifyWebhook(ChatChannel $channel, Request $request): bool;

    /**
     * Convert a raw webhook payload into a channel-agnostic InboundMessage.
     * Returns null when the payload doesn't represent a text message we can
     * act on (e.g. a platform status/ack event).
     */
    public function normalizeInbound(ChatChannel $channel, array $payload): ?InboundMessage;

    /**
     * Send a plain-text message to a contact on this channel.
     *
     * @param  array  $context  Reply-routing hints copied from the inbound InboundMessage's
     *                          $replyContext (e.g. Facebook's ['kind' => 'comment', 'comment_id' => ...]).
     *                          Adapters that only ever send one kind of reply (Telegram) can ignore it.
     * @return array{external_message_id: ?string}
     */
    public function sendMessage(ChatChannel $channel, string $externalContactId, string $content, array $context = []): array;

    /**
     * Register/refresh the platform-side webhook for this channel.
     */
    public function setupWebhook(ChatChannel $channel, string $webhookUrl): void;
}
