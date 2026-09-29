<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityScore extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'website_id', 'overall_score', 'firewall_score', 'waf_score', 'malware_score', 'php_score',
        'ssh_score', 'ssl_score', 'update_score', 'backup_score', 'integrity_score', 'calculated_at',
    ];

    protected $casts = ['calculated_at' => 'datetime'];
}
