<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordpressStaging extends Model
{
    public const TO_STAGING = 'to_staging';

    public const TO_PRODUCTION = 'to_production';

    /** W5: production is copied to staging, updated there, then on production. */
    public const SAFE_UPDATE = 'safe_update';

    protected $fillable = [
        'production_website_id', 'staging_website_id', 'user_id',
        'clone_id', 'direction', 'stage', 'message', 'copied_at', 'deployed_at',
    ];

    protected function casts(): array
    {
        return ['copied_at' => 'datetime', 'deployed_at' => 'datetime'];
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'production_website_id');
    }

    public function staging(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'staging_website_id');
    }

    public function busy(): bool
    {
        return in_array($this->stage, ['queued', 'copying', 'rewriting', 'testing', 'updating'], true);
    }
}
