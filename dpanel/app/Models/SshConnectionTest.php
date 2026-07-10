<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SshConnectionTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'status',
        'output',
        'error_output',
        'latency_ms',
        'tested_at',
    ];

    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'tested_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
