<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One `php artisan queue:work` definition for a Laravel website; it runs as
 * `processes` systemd instances that start at boot (see drust laravel_queue).
 */
class WebsiteQueueWorker extends Model
{
    public const MAX_PER_WEBSITE = 10;

    public const MAX_PROCESSES = 16;

    protected $table = 'website_queue_workers';

    protected $fillable = [
        'website_id',
        'connection',
        'queue',
        'processes',
        'tries',
        'timeout',
        'sleep',
        'memory',
        'enabled',
    ];

    protected $casts = [
        'processes' => 'integer',
        'tries' => 'integer',
        'timeout' => 'integer',
        'sleep' => 'integer',
        'memory' => 'integer',
        'enabled' => 'boolean',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
