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
        'quota_mb',
        'forwarding_to',
        'status',
        'plan_id',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MailPlan::class);
    }
}
