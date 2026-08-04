<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailPlan extends Model
{
    protected $fillable = [
        'id',
        'owner_user_id',
        'name',
        'slug',
        'max_storage_mb',
        'max_mailboxes',
        'max_websites',
        'max_databases',
        'max_bandwidth_gb',
        'allow_forwarding',
        'allow_aliases',
        'priority_support',
        'sort_order',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'allow_forwarding' => 'boolean',
        'allow_aliases' => 'boolean',
        'priority_support' => 'boolean',
        'max_storage_mb' => 'integer',
        'max_mailboxes' => 'integer',
        'max_websites' => 'integer',
        'max_databases' => 'integer',
        'max_bandwidth_gb' => 'integer',
        'sort_order' => 'integer',
    ];

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'plan_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'package_id');
    }

    public function mailboxCount(): int
    {
        return (int) ($this->getAttribute('mailboxes_count') ?? $this->mailboxes()->count());
    }

    public function totalStorageMb(): int
    {
        return (int) ($this->getAttribute('mailboxes_sum_quota_mb') ?? $this->mailboxes()->sum('quota_mb'));
    }
}
