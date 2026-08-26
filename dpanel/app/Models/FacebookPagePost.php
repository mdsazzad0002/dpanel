<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookPagePost extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_channel_id',
        'external_post_id',
        'external_comment_id',
        'message',
        'link',
        'first_comment',
        'comment_status',
        'permalink_url',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
