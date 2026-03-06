<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mailbox extends Model
{
    protected $fillable = [
        'id',
        'domain',
        'mailbox',
        'email',
        'password',
        'quota_mb',
        'forwarding_to',
        'status',
    ];

    public $incrementing = false;

    protected $keyType = 'string';
}
