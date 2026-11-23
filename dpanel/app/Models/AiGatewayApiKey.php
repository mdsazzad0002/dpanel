<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

class AiGatewayApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'key_prefix',
        'key_hash',
        'is_active',
        'rate_limit_per_minute',
        'ip_rate_limit_per_minute',
        'allowed_ips',
        'last_used_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
            'ip_rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate a new key, returning both the plaintext (shown to the admin
     * exactly once) and the record's storable attributes.
     *
     * @return array{plain: string, prefix: string, hash: string}
     */
    public static function generate(): array
    {
        $secret = Str::random(40);
        $plain = 'sk-ag-'.$secret;

        return [
            'plain' => $plain,
            'prefix' => substr($plain, 0, 12).'…',
            'hash' => hash('sha256', $plain),
        ];
    }

    public static function findActiveByPlainKey(string $plain): ?self
    {
        return static::query()
            ->where('key_hash', hash('sha256', $plain))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Only write once a minute, so a busy key isn't an UPDATE per request.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /** Requests per minute for this key across all IPs (0 = unlimited). */
    public function effectiveRateLimit(): int
    {
        return max(0, $this->rate_limit_per_minute ?? (int) config('aigateway.rate_limit.key_per_minute', 600));
    }

    /** Requests per minute for this key from any single IP (0 = unlimited). */
    public function effectiveIpRateLimit(): int
    {
        return max(0, $this->ip_rate_limit_per_minute ?? (int) config('aigateway.rate_limit.key_ip_per_minute', 60));
    }

    /**
     * @return list<string> IPs / CIDR ranges this key may be used from (empty = any)
     */
    public function allowedIpList(): array
    {
        return self::parseIpList((string) $this->allowed_ips);
    }

    public function allowsIp(?string $ip): bool
    {
        $list = $this->allowedIpList();

        return $list === [] || ($ip !== null && IpUtils::checkIp($ip, $list));
    }

    /**
     * @return list<string>
     */
    public static function parseIpList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($parts));
    }

    public static function isValidIpOrCidr(string $entry): bool
    {
        [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($mask === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask <= $max;
    }
}
