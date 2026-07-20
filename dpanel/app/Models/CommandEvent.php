<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'command_job_id',
        'type',
        'message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function commandJob(): BelongsTo
    {
        return $this->belongsTo(CommandJob::class);
    }
}
