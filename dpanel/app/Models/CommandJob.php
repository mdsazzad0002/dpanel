<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommandJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'server_id',
        'parent_id',
        'task_id',
        'command',
        'normalized_command',
        'command_hash',
        'risk_level',
        'risk_reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'started_at',
        'finished_at',
        'exit_code',
        'output',
        'error_output',
        'ai_summary',
        'ai_fix_suggestion',
        'ai_fix_commands',
        'report_path',
        'retry_count',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'exit_code' => 'integer',
            'retry_count' => 'integer',
            'ai_fix_commands' => 'array',
            'tags' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ServerTask::class, 'task_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommandEvent::class)->orderBy('id');
    }
}
