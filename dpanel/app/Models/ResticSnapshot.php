<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Backlog W6: one restore point of a website in the restic repository. */
class ResticSnapshot extends Model
{
    protected $fillable = ['website_id', 'snapshot_id', 'taken_at', 'files_new', 'files_changed', 'data_added', 'total_bytes', 'warning'];

    protected $casts = [
        'taken_at' => 'datetime',
        'files_new' => 'integer',
        'files_changed' => 'integer',
        'data_added' => 'integer',
        'total_bytes' => 'integer',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
