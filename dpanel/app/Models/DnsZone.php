<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZone extends Model
{
    protected $table = 'dns_zones';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain',
        'server_id',
        'status',
        'assigned_user_id',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }
}
