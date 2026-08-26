<?php

namespace App\Services\ChatEngine\DTO;

/**
 * Channel-agnostic representation of one inbound message, produced by a
 * ChannelAdapter::normalizeInbound() call from a raw webhook payload.
 */
class InboundMessage
{
    /**
     * @param  string  $kind  "message" (private conversation) or "comment" (public post/feed comment).
     * @param  array  $replyContext  Extra data an adapter needs to send the reply back to the right
     *                               place (e.g. Facebook's comment_id for a comment reply). Passed
     *                               straight through to ChannelAdapter::sendMessage()'s $context arg.
     */
    public function __construct(
        public readonly string $externalContactId,
        public readonly ?string $contactName,
        public readonly ?string $contactUsername,
        public readonly ?string $text,
        public readonly ?string $externalMessageId,
        public readonly array $raw,
        public readonly string $kind = 'message',
        public readonly array $replyContext = [],
    ) {
    }
}
