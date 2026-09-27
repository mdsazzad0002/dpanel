<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'github_id', 'login', 'name', 'avatar_url', 'access_token',
        'refresh_token', 'token_expires_at', 'scopes', 'last_used_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function deployments(): HasMany { return $this->hasMany(WebsiteGitDeployment::class); }

    /** A linked GitHub account is private to the panel user who connected it. */
    public function scopeOwnedBy(Builder $query, ?User $user): Builder
    {
        return $user ? $query->where('user_id', $user->id) : $query->whereRaw('1 = 0');
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'login' => $this->login,
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'scopes' => $this->scopes,
            'deployments_count' => $this->deployments_count ?? null,
            'connected_at' => $this->created_at?->toDateTimeString(),
            'last_used_at' => $this->last_used_at?->toDateTimeString(),
        ];
    }
}
