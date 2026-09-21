<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatBroadcastDelivery extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_scheduled_message_id',
        'chat_contact_id',
        'chat_message_id',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ChatScheduledMessage::class, 'chat_scheduled_message_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ChatContact::class, 'chat_contact_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }
}
