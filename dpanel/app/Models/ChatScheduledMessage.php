<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatScheduledMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_channel_id',
        'audience_type',
        'chat_contact_id',
        'content',
        'run_at',
        'status',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
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

    public function deliveries(): HasMany
    {
        return $this->hasMany(ChatBroadcastDelivery::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
