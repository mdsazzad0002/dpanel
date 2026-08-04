<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    protected $table = 'dns_records';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'dns_zone_id',
        'powerdns_record_id',
        'type',
        'name',
        'content',
        'ttl',
        'priority',
        'is_active',
        'proxied',
        'settings',
    ];

    protected $casts = [
        'ttl' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'proxied' => 'boolean',
        'settings' => 'array',
        'powerdns_record_id' => 'integer',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }
}
