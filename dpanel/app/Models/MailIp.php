<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailIp extends Model
{
    use HasUuids;

    protected $table = 'mail_ips';

    protected $fillable = ['ip', 'hostname', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function domains(): HasMany
    {
        return $this->hasMany(MailDomain::class, 'mail_ip_id');
    }
}
