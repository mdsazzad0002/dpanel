<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
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
        'command',
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

        return $query->where('assigned_user_id', $actor->id);
    }
}
