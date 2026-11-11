<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Personal token for the public REST API at /api/v1 (backlog U1). Only the
 * SHA-256 hash is stored; the plaintext is shown once, when it is made. A
 * token acts as its owner and never does more than the owner may.
 */
class ApiToken extends Model
{
    public const PREFIX = 'dp-api-';

    protected $fillable = [
        'user_id',
        'name',
        'token_prefix',
        'token_hash',
        'scopes',
        'last_used_at',
        'last_used_ip',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{plain: string, prefix: string, hash: string}
     */
    public static function generate(): array
    {
        $plain = self::PREFIX.Str::random(48);

        return [
            'plain' => $plain,
            'prefix' => substr($plain, 0, 13).'…',
            'hash' => hash('sha256', $plain),
        ];
    }

    public static function findUsableByPlainToken(string $plain): ?self
    {
        return static::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function touchLastUsed(?string $ip): void
    {
        // One write a minute is enough for "last used".
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute()) && $this->last_used_ip === $ip) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->save();
    }
}
