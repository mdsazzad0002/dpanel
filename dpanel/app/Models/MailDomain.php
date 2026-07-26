<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailDomain extends Model
{
    protected $table = 'mail_domains';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain',
        'server_id',
        'enable_dkim',
        'enable_spf',
        'enable_dmarc',
        'dkim_selector',
        'dkim_private_key',
        'dkim_public_key',
        'status',
        'assigned_user_id',
    ];

    protected $hidden = [
        'dkim_private_key',
    ];

    protected $casts = [
        'enable_dkim' => 'boolean',
        'enable_spf' => 'boolean',
        'enable_dmarc' => 'boolean',
    ];

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'mail_domain_id');
    }
}
