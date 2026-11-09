<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageAlert extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'resource', 'period', 'level', 'notified_at'];

    protected $casts = [
        'level' => 'integer',
        'notified_at' => 'datetime',
    ];
}
