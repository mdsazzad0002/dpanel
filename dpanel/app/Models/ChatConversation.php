<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_channel_id',
        'chat_contact_id',
        'is_ai_enabled',
        'status',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_ai_enabled' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ChatContact::class, 'chat_contact_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }
}
