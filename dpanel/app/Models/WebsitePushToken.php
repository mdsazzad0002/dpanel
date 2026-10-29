<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Lets a panel that can't be reached from the internet (a local dev box) push
 * a website *to* this server, where the normal Clone & Share flow would need
 * this server to pull from it. Only the SHA-256 hash is stored; the plaintext
 * is shown to the admin exactly once.
 */
class WebsitePushToken extends Model
{
    public const PREFIX = 'dp-push-';

    protected $fillable = [
        'website_id',
        'user_id',
        'name',
        'token_prefix',
        'token_hash',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'last_used_ip',
        'last_push_status',
        'last_push_message',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return array{0: self, 1: string} the model and its plaintext token */
    public static function issue(Website $website, ?User $user, string $name, ?\DateTimeInterface $expiresAt): array
    {
        $plain = self::PREFIX.Str::random(48);
        $token = self::query()->create([
            'website_id' => (string) $website->id,
            'user_id' => $user?->id,
            'name' => $name,
            'token_prefix' => substr($plain, 0, 14),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $token = self::query()->where('token_hash', hash('sha256', $plain))->first();

        return $token?->isActive() ? $token : null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
