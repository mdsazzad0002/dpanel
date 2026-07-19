<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    protected $table = 'managed_domains';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'server_id',
        'assigned_user_id',
        'assigned_reseller_id',
        'is_active',
        'ssl_enabled',
        'ssl_status',
        'ssl_expires_at',
        'ssl_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ssl_enabled' => 'boolean',
        'ssl_expires_at' => 'datetime',
        'ssl_checked_at' => 'datetime',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedReseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reseller_id');
    }
}
