<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    protected $table = 'websites';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'domain',
        'root_path',
        'site_owner',
        'php_version',
        'enable_ssl',
        'assigned_user_id',
        'assigned_reseller_id',
        'command',
        'status',
    ];

    protected $casts = [
        'enable_ssl' => 'boolean',
        'assigned_user_id' => 'integer',
        'assigned_reseller_id' => 'integer',
    ];
}

