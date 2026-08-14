<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'assigned_user_id',
        'assigned_reseller_id',
        'created_by',
        'type',
        'status',
        'title',
        'message',
        'subject_type',
        'subject_id',
        'data',
        'read_at',
    ];

    protected $casts = [
        'assigned_user_id' => 'integer',
        'assigned_reseller_id' => 'integer',
        'created_by' => 'integer',
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /**
     * Same convention as Website::scopeVisibleTo: admin sees everything, a
     * reseller sees notifications tagged with their own id (their own actions
     * plus anything raised for users under them), a general user sees only
     * notifications tagged with their own id.
     */
    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->hasRole('admin')) {
            return $query;
        }

        if ($actor->hasRole('reseller')) {
            return $query->where('assigned_reseller_id', $actor->id);
        }

        if ($actor->hasRole('general') || $actor->hasRole('general_user')) {
            return $query->where('assigned_user_id', $actor->id);
        }

        return $query->whereRaw('1 = 0');
    }
}
