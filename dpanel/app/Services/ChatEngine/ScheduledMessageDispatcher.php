<?php

namespace App\Services\ChatEngine;

use App\Jobs\SendScheduledChatMessageJob;
use App\Models\ChatBroadcastDelivery;
use App\Models\ChatContact;
use App\Models\ChatScheduledMessage;

class ScheduledMessageDispatcher
{
    /**
     * Find every scheduled message due to run, resolve its audience into
     * per-contact deliveries, and dispatch one send job per delivery.
     */
    public function dispatchDue(): int
    {
        $due = ChatScheduledMessage::query()
            ->where('status', 'pending')
            ->where('run_at', '<=', now())
            ->get();

        foreach ($due as $scheduled) {
            $this->dispatchOne($scheduled);
        }

        return $due->count();
    }

    private function dispatchOne(ChatScheduledMessage $scheduled): void
    {
        $contactIds = $scheduled->audience_type === 'contact'
            ? array_filter([$scheduled->chat_contact_id])
            : ChatContact::query()->where('chat_channel_id', $scheduled->chat_channel_id)->pluck('id')->all();

        foreach ($contactIds as $contactId) {
            $delivery = ChatBroadcastDelivery::firstOrCreate([
                'chat_scheduled_message_id' => $scheduled->id,
                'chat_contact_id' => $contactId,
            ]);

            if ($delivery->status === 'pending') {
                SendScheduledChatMessageJob::dispatch($delivery->id)->onQueue(config('chatengine.queue'));
            }
        }

        $scheduled->update(['status' => 'dispatched']);
    }
}
