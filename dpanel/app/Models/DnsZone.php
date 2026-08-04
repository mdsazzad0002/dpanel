<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZone extends Model
{
    protected $table = 'dns_zones';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'domain',
        'powerdns_domain_id',
        'website_id',
        'server_id',
        'status',
        'assigned_user_id',
        'assigned_reseller_id',
        'created_by_user_id',
        'owner_user_id',
        'transferred_by_user_id',
        'transferred_at',
        'source',
        'provider',
        'mode',
        'dnssec_enabled',
        'proxy_enabled',
        'logging_enabled',
        'analytics_enabled',
        'settings',
    ];

    protected $casts = [
        'powerdns_domain_id' => 'integer',
        'dnssec_enabled' => 'boolean',
        'proxy_enabled' => 'boolean',
        'logging_enabled' => 'boolean',
        'analytics_enabled' => 'boolean',
        'settings' => 'array',
        'transferred_at' => 'datetime',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        if ($user->hasRole('reseller')) {
            return $query->where(function (Builder $scope) use ($user) {
                $scope->where('owner_user_id', $user->id)
                    ->orWhere('assigned_reseller_id', $user->id);
            });
        }

        return $query->where('owner_user_id', $user->id);
    }
}
