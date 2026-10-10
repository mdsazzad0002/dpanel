<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Node.js or Python app run by systemd from a folder in a site owner's home. */
class AppProject extends Model
{
    public const RUNTIMES = ['node', 'python'];

    protected $fillable = [
        'name',
        'runtime',
        'site_owner',
        'working_directory',
        'entry_file',
        'start_command',
        'version',
        'port',
        'python_workers',
        'python_mode',
        'python_timeout',
        'status',
        'unit_key',
        'created_by',
    ];

    protected $casts = [
        'port' => 'integer',
        'python_workers' => 'integer',
        'python_timeout' => 'integer',
    ];

    public function shares(): HasMany
    {
        return $this->hasMany(PortShare::class, 'app_project_id');
    }

    /** Projects live in a site owner's home, so they follow that owner's websites. */
    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor?->hasRole('admin')) {
            return $query;
        }

        return $query->whereIn('site_owner', self::ownersVisibleTo($actor));
    }

    /** @return array<int, string> Linux users whose homes the actor may use. */
    public static function ownersVisibleTo(?User $actor): array
    {
        return Website::query()
            ->visibleTo($actor)
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'))
            ->whereNotNull('site_owner')
            ->where('site_owner', '!=', '')
            ->distinct()
            ->orderBy('site_owner')
            ->pluck('site_owner')
            ->all();
    }

    public function homeDirectory(): string
    {
        return '/home/'.$this->site_owner;
    }

    public function pythonWorkerCount(): int
    {
        $workers = (int) ($this->python_workers ?? 0);

        return $workers > 0 ? $workers : Website::DEFAULT_PYTHON_WORKERS;
    }

    public function pythonTimeout(): int
    {
        $timeout = (int) ($this->python_timeout ?? 0);

        return $timeout > 0
            ? max(Website::MIN_PYTHON_TIMEOUT, min(Website::MAX_PYTHON_TIMEOUT, $timeout))
            : Website::DEFAULT_PYTHON_TIMEOUT;
    }

    public function pythonMode(): string
    {
        return in_array($this->python_mode, Website::PYTHON_MODES, true) ? $this->python_mode : 'production';
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'runtime' => $this->runtime,
            'site_owner' => $this->site_owner,
            'home' => $this->homeDirectory(),
            'working_directory' => $this->working_directory,
            'entry_file' => $this->entry_file,
            'start_command' => $this->start_command,
            'version' => $this->version,
            'port' => $this->port,
            'python_workers' => $this->python_workers,
            'python_mode' => $this->python_mode,
            'python_timeout' => $this->python_timeout,
            'status' => $this->status,
        ];
    }
}
