<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Website extends Model
{
    /** Gunicorn's own default is a single sync worker, which serializes every request. */
    public const DEFAULT_PYTHON_WORKERS = 4;

    public const MAX_PYTHON_WORKERS = 32;

    /** Seconds a request may run before gunicorn gives up on it. */
    public const DEFAULT_PYTHON_TIMEOUT = 30;

    public const MIN_PYTHON_TIMEOUT = 10;

    public const MAX_PYTHON_TIMEOUT = 300;

    /** production: plain gunicorn. development: auto-reload on code changes. */
    public const PYTHON_MODES = ['production', 'development'];

    protected static function booted(): void
    {
        $reload = static function (self $website): void {
            $domains = array_values(array_unique(array_filter([
                strtolower((string) $website->domain),
                strtolower((string) ($website->getOriginal('domain') ?? '')),
            ])));

            DB::afterCommit(static fn () => app(\App\Services\EdgeGatewayReloader::class)->reloadDomains($domains));
        };
        static::saved($reload);
        static::deleted($reload);
    }
    protected $table = 'websites';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'domain',
        'hostname',
        'scope',
        'parent_id',
        'config_source_id',
        'start_directory',
        'root_path',
        'project_root',
        'site_owner',
        'php_version',
        'runtime',
        'node_entry_file',
        'node_port',
        'node_version',
        'node_start_command',
        'node_process_status',
        'python_entry_file',
        'python_port',
        'python_version',
        'python_start_command',
        'python_workers',
        'python_mode',
        'python_timeout',
        'python_process_status',
        'client_max_body_size',
        'wordpress_db_prefix',
        'wordpress_sso_secret',
        'enable_ssl',
        'manage_dns',
        'filemanager_show_hidden',
        'assigned_user_id',
        'assigned_reseller_id',
        'status',
        'type',
        'ssl_mode',
    ];

    protected $casts = [
        'enable_ssl' => 'boolean',
        'manage_dns' => 'boolean',
        'filemanager_show_hidden' => 'boolean',
        'assigned_user_id' => 'integer',
        'assigned_reseller_id' => 'integer',
        'node_port' => 'integer',
        'python_port' => 'integer',
        'python_workers' => 'integer',
        'python_timeout' => 'integer',
    ];

    public function isNodeRuntime(): bool
    {
        return $this->runtime === 'node';
    }

    public function isPythonRuntime(): bool
    {
        return $this->runtime === 'python';
    }

    /** Number of gunicorn worker processes; unset sites run the default. */
    public function pythonWorkerCount(): int
    {
        $workers = (int) ($this->python_workers ?? 0);

        return $workers > 0 ? $workers : self::DEFAULT_PYTHON_WORKERS;
    }

    public function pythonTimeout(): int
    {
        $timeout = (int) ($this->python_timeout ?? 0);

        return $timeout > 0
            ? max(self::MIN_PYTHON_TIMEOUT, min(self::MAX_PYTHON_TIMEOUT, $timeout))
            : self::DEFAULT_PYTHON_TIMEOUT;
    }

    public function pythonMode(): string
    {
        return in_array($this->python_mode, self::PYTHON_MODES, true) ? $this->python_mode : 'production';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function queueWorkers(): HasMany
    {
        return $this->hasMany(WebsiteQueueWorker::class);
    }

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
