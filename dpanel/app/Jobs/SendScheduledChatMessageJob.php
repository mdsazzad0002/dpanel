<?php

namespace App\Jobs;

use App\Models\ChatBroadcastDelivery;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ChatEngine\ChatEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendScheduledChatMessageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public string $deliveryId)
    {
        $this->onQueue(config('chatengine.queue'));
    }

    public function handle(ChatEngineService $chatEngine): void
    {
        $delivery = ChatBroadcastDelivery::find($this->deliveryId);

        if (! $delivery || $delivery->status !== 'pending') {
            return;
        }

        $scheduled = $delivery->scheduledMessage;
        $channel = $scheduled->channel;
        $contact = $delivery->contact;
        $adapter = $chatEngine->adapterFor($channel->type);

        try {
            $result = $adapter->sendMessage($channel, $contact->external_id, $scheduled->content);

            $conversation = ChatConversation::firstOrCreate([
                'chat_channel_id' => $channel->id,
                'chat_contact_id' => $contact->id,
            ]);

            $message = ChatMessage::create([
                'chat_conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'role' => 'agent',
                'content' => $scheduled->content,
                'external_message_id' => $result['external_message_id'] ?? null,
            ]);

            DB::transaction(function () use ($delivery, $message, $scheduled): void {
                $delivery->update(['status' => 'sent', 'chat_message_id' => $message->id, 'sent_at' => now()]);
                $scheduled->increment('sent_count');
            });
        } catch (Throwable $e) {
            DB::transaction(function () use ($delivery, $e, $scheduled): void {
                $delivery->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $scheduled->increment('failed_count');
            });
        }
    }
}
