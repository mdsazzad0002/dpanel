<?php

namespace App\Services;

use App\Models\PanelSession;
use App\Models\User;
use App\Models\Website;

/**
 * The one way an account is suspended, from the panel and from WHMCS alike.
 * Mail, FTP, cron, queue workers and SSH are still open (task P1).
 */
class AccountSuspensionService
{
    public function __construct(private readonly EdgeGatewayReloader $gateway) {}

    public function suspend(User $user): void
    {
        $user->update(['is_suspended' => true, 'suspended_at' => now()]);
        PanelSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->setServingState($user, 'active', 'suspended');
    }

    public function unsuspend(User $user): void
    {
        $user->update(['is_suspended' => false, 'suspended_at' => null]);
        $this->setServingState($user, 'suspended', 'active');
    }

    /** A site over its limit stays that way: suspension does not own that state. */
    private function setServingState(User $user, string $from, string $to): void
    {
        $websites = Website::query()->where('assigned_user_id', $user->id)->where('serving_state', $from);
        $domains = $websites->pluck('domain')->all();
        $websites->update(['serving_state' => $to]);
        $this->gateway->reloadDomains($domains);
    }
}
