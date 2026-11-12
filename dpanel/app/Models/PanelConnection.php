<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A link to another dPanel for live migration: its URL and an API token made
 * there with the `migration:read` (take from it) and/or `migration:write`
 * (send to it) scope. The token is stored encrypted.
 */
class PanelConnection extends Model
{
    protected $fillable = ['user_id', 'name', 'base_url', 'token', 'remote_server', 'remote_version', 'remote_scopes', 'last_checked_at', 'last_error'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'remote_scopes' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(PanelTransfer::class, 'connection_id');
    }

    public function canPull(): bool
    {
        return array_intersect(['migration:read', 'migration:write'], (array) $this->remote_scopes) !== [];
    }

    public function canPush(): bool
    {
        return in_array('migration:write', (array) $this->remote_scopes, true);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'remote_server' => $this->remote_server,
            'remote_version' => $this->remote_version,
            'can_pull' => $this->canPull(),
            'can_push' => $this->canPush(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
