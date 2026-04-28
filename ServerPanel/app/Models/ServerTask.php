<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'server_id',
        'title',
        'goal',
        'status',
        'priority',
        'ai_plan',
        'final_report_path',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ai_plan' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ServerTaskStep::class)->orderBy('sort_order');
    }

    public function commandJobs(): HasMany
    {
        return $this->hasMany(CommandJob::class, 'task_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
