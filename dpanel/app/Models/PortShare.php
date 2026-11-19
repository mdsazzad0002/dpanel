<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A website path published through the edge gateway: a local port, or
 * (target_type "remote") one or more other servers, load balanced.
 */
class PortShare extends Model
{
    protected $fillable = [
        'website_id',
        'path_prefix',
        'target_port',
        'target_type',
        'targets',
        'lb_policy',
        'preserve_host',
        'verify_tls',
        'timeout_seconds',
        'app_project_id',
        'strip_prefix',
        'enabled',
        'created_by',
    ];

    protected $casts = [
        'target_port' => 'integer',
        'preserve_host' => 'boolean',
        'verify_tls' => 'boolean',
        'timeout_seconds' => 'integer',
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
            'target_type' => $this->target_type ?: 'local',
            'targets' => $this->targetList(),
            'lb_policy' => $this->lb_policy ?: 'round_robin',
            'preserve_host' => $this->preserve_host ?? true,
            'verify_tls' => $this->verify_tls ?? true,
            'timeout_seconds' => $this->timeout_seconds ?: 30,
            'app_project_id' => $this->app_project_id,
            'strip_prefix' => $this->strip_prefix,
            'enabled' => $this->enabled,
        ];
    }

    public function isRemote(): bool
    {
        return $this->target_type === 'remote';
    }

    /** @return array<int, string> */
    public function targetList(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', (string) $this->targets) ?: []));
    }
}
