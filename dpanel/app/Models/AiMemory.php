<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One long-term fact the AI Gateway chat remembers for an admin. */
class AiMemory extends Model
{
    public const TYPES = ['user', 'feedback', 'project', 'reference'];

    protected $fillable = ['user_id', 'name', 'type', 'description', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
