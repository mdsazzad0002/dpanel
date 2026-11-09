<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackagePlan extends Model
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
        'max_emails_per_hour',
        'max_emails_per_day',
        'max_cpu_percent',
        'max_memory_mb',
        'max_processes',
        'enforcement_mode',
        'over_bandwidth_action',
        'allow_forwarding',
        'allow_aliases',
        'priority_support',
        'sort_order',
    ];

    public const MODE_REPORT = 'report';

    public const MODE_ENFORCE = 'enforce';

    public const BANDWIDTH_NOTIFY = 'notify';

    public const BANDWIDTH_BLOCK = 'block';

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
        'max_emails_per_hour' => 'integer',
        'max_emails_per_day' => 'integer',
        'max_cpu_percent' => 'integer',
        'max_memory_mb' => 'integer',
        'max_processes' => 'integer',
        'sort_order' => 'integer',
    ];

    public function hasResourceLimits(): bool
    {
        return $this->max_cpu_percent > 0 || $this->max_memory_mb > 0 || $this->max_processes > 0;
    }

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
