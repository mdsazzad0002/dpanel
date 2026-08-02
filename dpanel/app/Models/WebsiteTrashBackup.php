<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WebsiteTrashBackup extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'website_id',
        'domain',
        'file_name',
        'file_path',
        'file_size',
        'metadata',
        'assigned_user_id',
        'assigned_reseller_id',
        'deleted_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'metadata' => 'array',
        'assigned_user_id' => 'integer',
        'assigned_reseller_id' => 'integer',
        'deleted_by' => 'integer',
    ];

    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->hasRole('admin')) {
            return $query;
        }

        if ($actor->hasRole('reseller')) {
            return $query->where(function (Builder $query) use ($actor): void {
                $query->where('assigned_reseller_id', $actor->id)
                    ->orWhere('deleted_by', $actor->id);
            });
        }

        return $query->where(function (Builder $query) use ($actor): void {
            $query->where('assigned_user_id', $actor->id)
                ->orWhere('deleted_by', $actor->id);
        });
    }
}
