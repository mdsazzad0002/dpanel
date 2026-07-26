<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceStatus extends Model
{
    protected $table = 'service_status';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'server_id',
        'service_name',
        'display_name',
        'status',
        'is_enabled',
        'details',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
