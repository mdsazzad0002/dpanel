<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronJob extends Model
{
    protected $table = 'cron_jobs';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'website_id',
        'domain',
        'name',
        'expression',
        'command',
        'status',
        'description',
    ];
}

