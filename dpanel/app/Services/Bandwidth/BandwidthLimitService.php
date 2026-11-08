<?php

namespace App\Services\Bandwidth;

use App\Models\AccountUsageSample;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use App\Services\EdgeGatewayReloader;
use App\Services\NotificationService;

/**
 * Task P3: the package's monthly bandwidth limit. Going over sends one notice
 * a month; with `block` the account's websites are served a 503 until the
 * count resets with the new month (the gateway starts a new file each month).
 * Only `over_limit` sites go back on, so a suspended site stays suspended.
 */
class BandwidthLimitService
{
    public function __construct(
        private readonly BandwidthUsageService $usage,
        private readonly NotificationService $notifications,
        private readonly EdgeGatewayReloader $gateway,
    ) {}

    /** @return array{checked: int, over: int} */
    public function checkAll(?string $month = null): array
    {
        $month ??= now()->format('Y-m');
        $result = ['checked' => 0, 'over' => 0];
        User::query()->whereNotNull('package_id')->with('package')->orderBy('id')->get()
            ->each(function (User $user) use ($month, &$result): void {
                $over = $this->check($user, $month);
                if ($over !== null) {
                    $result['checked']++;
                    $result['over'] += $over ? 1 : 0;
                }
            });

        return $result;
    }

    /** @return bool|null null when the package sets no limit */
    public function check(User $user, string $month): ?bool
    {
        $package = $user->loadMissing('package')->package;
        if ($package === null || (int) $package->max_bandwidth_gb <= 0) {
            $this->setServingState($user, 'over_limit', 'active');

            return null;
        }

        $bytes = $this->usage->bytesForUser($user, $month);
        $limitBytes = (int) $package->max_bandwidth_gb * 1024 ** 3;
        AccountUsageSample::query()->create([
            'user_id' => $user->id, 'sampled_at' => now(), 'bandwidth_mb' => intdiv($bytes, 1024 ** 2),
        ]);

        $over = $bytes >= $limitBytes;
        if ($over && $user->bandwidth_over_limit_month !== $month) {
            $user->update(['bandwidth_over_limit_month' => $month]);
            $this->notify($user, $package, $bytes);
        }

        $block = $over && $package->over_bandwidth_action === PackagePlan::BANDWIDTH_BLOCK;
        $block
            ? $this->setServingState($user, 'active', 'over_limit')
            : $this->setServingState($user, 'over_limit', 'active');

        return $over;
    }

    private function setServingState(User $user, string $from, string $to): void
    {
        $websites = Website::query()->where('assigned_user_id', $user->id)->where('serving_state', $from);
        $domains = $websites->pluck('domain')->all();
        if ($domains === []) {
            return;
        }
        $websites->update(['serving_state' => $to]);
        $this->gateway->reloadDomains($domains);
    }

    private function notify(User $user, PackagePlan $package, int $bytes): void
    {
        $used = round($bytes / 1024 ** 3, 2);
        $blocked = $package->over_bandwidth_action === PackagePlan::BANDWIDTH_BLOCK;
        $this->notifications->create(
            type: 'bandwidth_limit',
            status: 'blocked',
            title: 'Monthly bandwidth limit reached',
            message: "{$user->name} used {$used} GB of {$package->max_bandwidth_gb} GB this month."
                .($blocked ? ' Their websites are paused until the month ends.' : ''),
            assignedUserId: $user->id,
            assignedResellerId: $user->reseller_id,
            subject: $user,
            data: ['bytes' => $bytes, 'limit_gb' => (int) $package->max_bandwidth_gb, 'blocked' => $blocked],
        );
    }
}
