<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionQuotaService
{
    /**
     * Get current active subscription with package loaded.
     */
    public function activeSubscriptionFor(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->with('package')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('started_at')
            ->first();
    }

    /**
     * Check whether user can consume a specific resource.
     */
    public function canUse(User $user, string $resource, int $amount = 1): bool
    {
        $subscription = $this->activeSubscriptionFor($user);

        if ($subscription === null) {
            return false;
        }

        return $subscription->canUse($resource, $amount);
    }

    /**
     * Consume quota if available.
     */
    public function consume(User $user, string $resource, int $amount = 1): bool
    {
        $subscription = $this->activeSubscriptionFor($user);

        if ($subscription === null) {
            return false;
        }

        return $subscription->consume($resource, $amount);
    }

    /**
     * Release consumed quota.
     */
    public function release(User $user, string $resource, int $amount = 1): void
    {
        $subscription = $this->activeSubscriptionFor($user);

        if ($subscription === null) {
            return;
        }

        $subscription->release($resource, $amount);
    }
}
