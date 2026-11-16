<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One panel or SSH sign-in, failed attempt or sign-out, with where it came from. */
class LoginEvent extends Model
{
    public const CHANNEL_PANEL = 'panel';
    public const CHANNEL_SSH = 'ssh';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILED = 'failed';
    public const RESULT_LOGOUT = 'logout';

    protected $fillable = [
        'channel',
        'result',
        'method',
        'reason',
        'user_id',
        'username',
        'actor_id',
        'ip_address',
        'user_agent',
        'country_code',
        'country',
        'region',
        'city',
        'latitude',
        'longitude',
        'device_latitude',
        'device_longitude',
        'device_accuracy',
        'geo_status',
        'fingerprint',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'device_latitude' => 'float',
        'device_longitude' => 'float',
        'device_accuracy' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
