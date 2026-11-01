<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'avatar_path',
        'site_owner',
        'mail_home',
        'mail_uid',
        'mail_gid',
        'plan_id',
        'created_by',
        'assigned_reseller_id',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reseller_id');
    }

    /**
     * Admins see every mailbox; anyone else the ones they created, and a
     * reseller also the ones an admin assigned to them.
     */
    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->hasRole('admin')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($actor): void {
            $query->where('created_by', $actor->id);
            if ($actor->hasRole('reseller')) {
                $query->orWhere('assigned_reseller_id', $actor->id);
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PackagePlan::class, 'plan_id');
    }
}
