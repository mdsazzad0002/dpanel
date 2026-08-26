<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookPageActivity extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_channel_id',
        'activity_type',
        'external_id',
        'parent_post_id',
        'message',
        'actor_id',
        'actor_name',
        'verb',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_id');
    }
}
