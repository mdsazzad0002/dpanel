<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A local port published on a website path through the edge gateway. */
class PortShare extends Model
{
    protected $fillable = [
        'website_id',
        'path_prefix',
        'target_port',
        'app_project_id',
        'strip_prefix',
        'enabled',
        'created_by',
    ];

    protected $casts = [
        'target_port' => 'integer',
        'app_project_id' => 'integer',
        'strip_prefix' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AppProject::class, 'app_project_id');
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'website_id' => (string) $this->website_id,
            'path_prefix' => $this->path_prefix,
            'target_port' => $this->target_port,
            'app_project_id' => $this->app_project_id,
            'strip_prefix' => $this->strip_prefix,
            'enabled' => $this->enabled,
        ];
    }
}
