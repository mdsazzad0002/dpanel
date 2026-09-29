<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'server_id', 'website_id', 'event_type', 'severity', 'source_ip', 'request_uri', 'user_agent',
        'rule_id', 'message', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];
}
