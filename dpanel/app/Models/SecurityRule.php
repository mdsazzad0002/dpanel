<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityRule extends Model
{
    protected $fillable = ['rule_id', 'name', 'category', 'severity', 'description', 'detection_type', 'remediation', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
