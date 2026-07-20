<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiErrorResolution extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'command_job_id',
        'error_signature',
        'problem_title',
        'problem_summary',
        'detected_cause',
        'suggested_fix',
        'fix_commands',
        'risk_level',
        'success_status',
        'usage_count',
        'last_used_at',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'fix_commands' => 'array',
            'tags' => 'array',
            'usage_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function commandJob(): BelongsTo
    {
        return $this->belongsTo(CommandJob::class);
    }
}
