<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGatewayModel extends Model
{
    use HasFactory;

    /**
     * Consecutive rate-limit/quota failures before a model is
     * auto-disabled and must be manually re-enabled.
     */
    public const FAILURE_THRESHOLD = 3;

    /**
     * How long a model is skipped by auto-failover after a failure.
     */
    public const COOLDOWN_MINUTES = 5;

    protected $fillable = [
        'provider_id',
        'name',
        'display_name',
        'context_window',
        'max_output_tokens',
        'capabilities',
        'input_price',
        'output_price',
        'is_active',
        'is_default',
        'failure_count',
        'cooldown_until',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'input_price' => 'float',
            'output_price' => 'float',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'failure_count' => 'integer',
            'cooldown_until' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiGatewayProvider::class, 'provider_id');
    }

    public function isInCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /**
     * Record a rate-limit/quota failure: put the model in a short cooldown,
     * and once it has failed FAILURE_THRESHOLD times in a row, disable it
     * outright until an admin manually re-enables it.
     *
     * @param  ?int  $cooldownSeconds  When the provider told us how long to
     *                                 wait (e.g. "try again in 7.725s"), pass
     *                                 that instead of the blanket cooldown —
     *                                 clamped to [5s, COOLDOWN_MINUTES] so a
     *                                 short-lived TPM limit doesn't bench the
     *                                 model for the full default window.
     */
    public function recordFailure(?int $cooldownSeconds = null): void
    {
        $this->failure_count += 1;
        $seconds = $cooldownSeconds !== null
            ? min(max($cooldownSeconds, 5), self::COOLDOWN_MINUTES * 60)
            : self::COOLDOWN_MINUTES * 60;
        $this->cooldown_until = now()->addSeconds($seconds);

        if ($this->failure_count >= self::FAILURE_THRESHOLD) {
            $this->is_active = false;
        }

        $this->save();
    }

    /**
     * Clear the failure streak after a successful request.
     */
    public function recordSuccess(): void
    {
        if ($this->failure_count !== 0 || $this->cooldown_until !== null) {
            $this->failure_count = 0;
            $this->cooldown_until = null;
            $this->save();
        }
    }

    /**
     * Reset the failure streak — called when an admin manually re-enables
     * a model that was auto-disabled.
     */
    public function resetFailover(): void
    {
        $this->failure_count = 0;
        $this->cooldown_until = null;
    }
}
