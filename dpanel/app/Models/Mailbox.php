<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    protected $fillable = [
        'id',
        'domain',
        'mailbox',
        'email',
        'password',
        'client_password',
        'quota_mb',
        'forwarding_to',
        'status',
        'site_owner',
        'mail_home',
        'mail_uid',
        'mail_gid',
        'plan_id',
    ];

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'client_password' => 'encrypted',
        ];
    }

    protected $keyType = 'string';

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MailPlan::class, 'plan_id');
    }
}
