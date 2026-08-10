<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MigrationImport extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'provider', 'original_name', 'archive_path', 'archive_size', 'status', 'inventory', 'last_error', 'created_by', 'assigned_reseller_id'];
    protected $casts = ['inventory' => 'array', 'archive_size' => 'integer'];

    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor?->hasRole('admin')) return $query;
        return $query->where(fn (Builder $query) => $query->where('created_by', $actor?->id)->orWhere('assigned_reseller_id', $actor?->id));
    }
}
