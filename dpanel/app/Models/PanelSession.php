<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PanelSession extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token_hash',
        'cookie_hash',
        'ip_address',
        'user_agent_hash',
        'expires_at',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function syncSingleSession(
        int $userId,
        string $token,
        string $cookieToken,
        string $ipAddress,
        string $userAgent,
        \DateTimeInterface $expiresAt,
        \DateTimeInterface $lastSeenAt,
    ): self {
        $tokenHash = hash('sha256', $token);
        $cookieHash = hash('sha256', $cookieToken);
        $userAgentHash = hash('sha256', $userAgent);

        return DB::transaction(function () use (
            $userId,
            $tokenHash,
            $cookieHash,
            $ipAddress,
            $userAgentHash,
            $expiresAt,
            $lastSeenAt,
        ): self {
            DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            $session = self::query()
                ->where('user_id', $userId)
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($session instanceof self) {
                self::query()
                    ->where('user_id', $userId)
                    ->where('id', '!=', $session->id)
                    ->delete();

                $session->fill([
                    'cookie_hash' => $cookieHash,
                    'ip_address' => $ipAddress,
                    'user_agent_hash' => $userAgentHash,
                    'expires_at' => $expiresAt,
                    'last_seen_at' => $lastSeenAt,
                    'revoked_at' => null,
                ]);
                $session->save();

                return $session->refresh();
            }

            self::query()
                ->where('user_id', $userId)
                ->delete();

            $session = self::create([
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'cookie_hash' => $cookieHash,
                'ip_address' => $ipAddress,
                'user_agent_hash' => $userAgentHash,
                'expires_at' => $expiresAt,
                'last_seen_at' => $lastSeenAt,
                'revoked_at' => null,
            ]);

            if (! $session instanceof self) {
                throw new RuntimeException('Unable to sync panel session.');
            }

            return $session;
        }, 3);
    }
}
