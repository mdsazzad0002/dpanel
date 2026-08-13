<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiGatewayApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'key_prefix',
        'key_hash',
        'is_active',
        'last_used_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
