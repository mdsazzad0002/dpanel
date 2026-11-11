<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Backlog W8: one run of the restore test. `results` holds, per website,
 * which restore point was read back and what came of it.
 */
class ResticRestoreTest extends Model
{
    protected $fillable = [
        'trigger', 'status', 'started_at', 'finished_at', 'repository_healthy', 'repository_message',
        'read_data_percent', 'websites_tested', 'websites_failed', 'results', 'message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'repository_healthy' => 'boolean',
        'read_data_percent' => 'integer',
        'websites_tested' => 'integer',
        'websites_failed' => 'integer',
        'results' => 'array',
    ];

    /** A run still going, unless it is so old its worker must have died. */
    public static function running(): ?self
    {
        return self::query()->where('status', 'running')->where('started_at', '>', now()->subDay())->latest('started_at')->first();
    }
}
