<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    /** Delivers to any address. */
    public const MODE_LIVE = 'live';

    /** Test mode: mail reaches mailboxes on this server only, never the outside. */
    public const MODE_SANDBOX = 'sandbox';

    public const DELIVERY_MODES = [self::MODE_LIVE, self::MODE_SANDBOX];

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
        'delivery_mode',
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

    public function isSandbox(): bool
    {
        return $this->delivery_mode === self::MODE_SANDBOX;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PackagePlan::class, 'plan_id');
    }
}
