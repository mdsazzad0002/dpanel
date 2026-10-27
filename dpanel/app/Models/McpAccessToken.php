<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Bearer token for the remote MCP endpoint (`/api/mcp`). Only the SHA-256
 * hash is stored; the plaintext is shown to the admin exactly once.
 */
class McpAccessToken extends Model
{
    public const PREFIX = 'dp-mcp-';

    public const SCOPE_READ = 'read';

    public const SCOPE_FULL = 'full';

    protected $fillable = [
        'user_id',
        'name',
        'token_prefix',
        'token_hash',
        'scope',
        'is_active',
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
            'is_active' => 'boolean',
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
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    public function allowsMutations(): bool
    {
        return $this->scope === self::SCOPE_FULL;
    }

    public function touchLastUsed(?string $ip): void
    {
        // Avoid a write on every JSON-RPC call from a chatty client.
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute()) && $this->last_used_ip === $ip) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->save();
    }
}
