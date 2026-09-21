<?php

namespace App\Jobs;

use App\Models\ChatChannel;
use App\Services\ChatEngine\ChatEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundChatMessageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public string $channelId, public array $payload)
    {
        $this->onQueue(config('chatengine.queue'));
    }

    public function handle(ChatEngineService $chatEngine): void
    {
        $channel = ChatChannel::find($this->channelId);

        if (! $channel || ! $channel->is_active) {
            return;
        }

        $adapter = $chatEngine->adapterFor($channel->type);
        $inbound = $adapter->normalizeInbound($channel, $this->payload);

        if (! $inbound) {
            return;
        }

        $chatEngine->handleInboundMessage($channel, $inbound);
    }
}
