<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommandLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'command',
        'output',
        'error_output',
        'source',
        'memory_id',
        'session_id',
        'server_id',
    ];

    public function memory(): BelongsTo
    {
        return $this->belongsTo(AiMemory::class, 'memory_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }
}

