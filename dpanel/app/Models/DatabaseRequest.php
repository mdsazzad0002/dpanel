<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class DatabaseRequest extends Model
{
    protected $fillable = [
        'id',
        'domain',
        'database_name',
        'database_user',
        'database_password',
        'database_host',
        'charset',
        'collation',
        'status',
        'assigned_user_id',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->hasRole('admin')) {
            return $query;
        }

        $visibleDomains = Website::query()
            ->visibleTo($actor)
            ->pluck('domain')
            ->filter(fn ($domain) => is_string($domain) && trim($domain) !== '')
            ->map(fn ($domain) => strtolower(trim($domain)))
            ->unique()
            ->values();

        return $query->where(function (Builder $q) use ($actor, $visibleDomains) {
            $q->where('assigned_user_id', $actor->id);

            if ($visibleDomains->isNotEmpty()) {
                $q->orWhereIn(DB::raw('LOWER(domain)'), $visibleDomains->all());
            }
        });
    }
}
