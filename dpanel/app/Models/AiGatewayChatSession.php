<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGatewayChatSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'provider_id',
        'user_id',
        'title',
        'message_count',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'message_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
