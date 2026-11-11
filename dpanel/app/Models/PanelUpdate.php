<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One update or rollback of dPanel itself, as drust last reported it (backlog U6). */
class PanelUpdate extends Model
{
    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [
        'run_id', 'action', 'status', 'step', 'target_ref', 'from_version', 'from_ref', 'from_commit',
        'to_version', 'to_ref', 'to_commit', 'rollback_of', 'restore_database', 'has_backup', 'message',
        'user_id', 'started_at', 'finished_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'restore_database' => 'boolean',
            'has_backup' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
