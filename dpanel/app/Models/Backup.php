<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    protected $table = 'backups';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'type',
        'source_type',
        'source_id',
        'server_id',
        'storage_type',
        'storage_path',
        'file_path',
        'file_size',
        'status',
        'error_message',
        'started_at',
        'completed_at',
        'expires_at',
        'assigned_user_id',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
