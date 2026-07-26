<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SslCertificate extends Model
{
    protected $table = 'ssl_certificates';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain',
        'certificate_path',
        'private_key_path',
        'ca_bundle_path',
        'certificate_pem',
        'private_key_pem',
        'type',
        'challenge_type',
        'cloudflare_zone_id',
        'status',
        'issued_at',
        'expires_at',
        'renewed_at',
        'auto_renew',
        'server_id',
        'website_id',
    ];

    protected $hidden = [
        'private_key_pem',
    ];

    protected $casts = [
        'auto_renew' => 'boolean',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'renewed_at' => 'datetime',
    ];

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->diffInDays(now()) <= $days;
    }
}
