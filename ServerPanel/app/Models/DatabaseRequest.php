<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseRequest extends Model
{
    protected $fillable = [
        'id',
        'domain',
        'database_name',
        'database_user',
        'database_password',
        'database_host',
        'charset',
        'collation',
        'command',
        'status',
    ];

    public $incrementing = false;

    protected $keyType = 'string';
}

