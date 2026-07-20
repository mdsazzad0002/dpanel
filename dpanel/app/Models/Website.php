<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Website extends Model
{
    protected $table = 'websites';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'domain',
        'start_directory',
        'root_path',
        'project_root',
        'site_owner',
        'php_version',
        'client_max_body_size',
        'wordpress_db_prefix',
        'enable_ssl',
        'filemanager_show_hidden',
        'assigned_user_id',
        'assigned_reseller_id',
        'command',
        'status',
        'type',
    ];

    protected $casts = [
        'enable_ssl' => 'boolean',
        'filemanager_show_hidden' => 'boolean',
        'assigned_user_id' => 'integer',
        'assigned_reseller_id' => 'integer',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedReseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reseller_id');
    }

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
