<?php

namespace App\Services;

use App\Models\CronJob;
use App\Models\PanelSession;
use App\Models\User;
use App\Models\Website;
use App\Services\Cron\CronSystemService;

/**
 * The one way an account is suspended, from the panel and from WHMCS alike.
 * Mail, FTP, queue workers and SSH are still open (task P1).
 */
class AccountSuspensionService
{
    public function __construct(
        private readonly EdgeGatewayReloader $gateway,
        private readonly CronSystemService $cron,
    ) {}

    /** A reseller's customers go with them, and come back with them. */
    public function suspend(User $user): void
    {
        $this->close($user, withReseller: false);
        $user->managedUsers()->where('is_suspended', false)->get()
            ->each(fn (User $customer) => $this->close($customer, withReseller: true));
    }

    /** A customer suspended on their own stays suspended. */
    public function unsuspend(User $user): void
    {
        $this->open($user);
        $user->managedUsers()->where('suspended_with_reseller', true)->get()
            ->each(fn (User $customer) => $this->open($customer));
    }

    private function close(User $user, bool $withReseller): void
    {
        $user->update(['is_suspended' => true, 'suspended_at' => $user->suspended_at ?? now(), 'suspended_with_reseller' => $withReseller]);
        PanelSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->setServingState($user, 'active', 'suspended');
        $this->setCronStatus($user, 'active', 'suspended');
    }

    private function open(User $user): void
    {
        $user->update(['is_suspended' => false, 'suspended_at' => null, 'suspended_with_reseller' => false]);
        $this->setServingState($user, 'suspended', 'active');
        $this->setCronStatus($user, 'suspended', 'active');
    }

    /** A site over its limit stays that way: suspension does not own that state. */
    private function setServingState(User $user, string $from, string $to): void
    {
        $websites = Website::query()->where('assigned_user_id', $user->id)->where('serving_state', $from);
        $domains = $websites->pluck('domain')->all();
        $websites->update(['serving_state' => $to]);
        $this->gateway->reloadDomains($domains);
    }

    /**
     * A job the owner turned off stays off. When drust cannot be reached the
     * job is still marked, so the account is suspended either way; the error
     * is reported and the next sync of that job removes it.
     */
    private function setCronStatus(User $user, string $from, string $to): void
    {
        $websites = Website::query()->where('assigned_user_id', $user->id)->get()->keyBy('id');
        CronJob::query()->whereIn('website_id', $websites->keys())->where('status', $from)->get()
            ->each(function (CronJob $job) use ($to, $websites): void {
                $job->update(['status' => $to]);
                try {
                    $this->cron->sync($job, $websites[$job->website_id]);
                } catch (\RuntimeException $e) {
                    report($e);
                }
            });
    }
}
