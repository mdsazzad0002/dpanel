<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One long-term fact the AI Gateway chat remembers for an admin. */
class AiChatMemory extends Model
{
    protected $table = 'ai_chat_memories';

    public const TYPES = ['user', 'feedback', 'project', 'reference'];

    protected $fillable = ['user_id', 'name', 'type', 'description', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
