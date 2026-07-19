<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringMetric extends Model
{
    protected $table = 'monitoring_metrics';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'server_id',
        'metric_type',
        'value',
        'unit',
        'metadata',
    ];

    protected $casts = [
        'value' => 'float',
        'metadata' => 'array',
    ];

    public function scopeOfType($query, string $type)
    {
        return $query->where('metric_type', $type);
    }

    public function scopeForServer($query, int $serverId)
    {
        return $query->where('server_id', $serverId);
    }

    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
}
