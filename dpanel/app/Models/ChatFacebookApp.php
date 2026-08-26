<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatFacebookApp extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'app_id',
        'app_secret',
        'verify_token',
        'is_active',
        'created_by',
    ];

    protected $hidden = [
        'app_secret',
    ];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ChatChannel::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if (! $actor) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->hasAnyRole(['admin', 'superadmin'])) {
            return $query;
        }

        if ($actor->hasRole('reseller')) {
            return $query->whereIn('created_by', $actor->managedUsers()->select('id')->union(
                User::query()->whereKey($actor->id)->select('id')
            ));
        }

        return $query->where('created_by', $actor->id);
    }
}
