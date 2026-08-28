<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatContact extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_channel_id',
        'external_id',
        'name',
        'username',
        'phone',
        'email',
        'meta',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(ChatConversation::class);
    }
}
