<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerTaskStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_task_id',
        'command_job_id',
        'title',
        'description',
        'status',
        'sort_order',
        'result_summary',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ServerTask::class, 'server_task_id');
    }

    public function commandJob(): BelongsTo
    {
        return $this->belongsTo(CommandJob::class);
    }
}
