<?php

namespace App\Services;

use App\Models\AccountUsageSample;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Platform\AccountLimitsClient;
use App\Services\Platform\SystemAccountSuspender;

/**
 * Task P2: measures each account's disk use against its package's storage
 * limit. In `report` mode going over only sends a notice. In `enforce` mode
 * each of the account's system users also gets a hard limit from the OS, so
 * together they cannot write past the package limit.
 */
class DiskUsageService
{
    public function __construct(
        private readonly AccountLimitsClient $limits,
        private readonly UsageAlertService $alerts,
    ) {}

    /** @return array{checked: int, over: int, failed: int} */
    public function checkAll(): array
    {
        $result = ['checked' => 0, 'over' => 0, 'failed' => 0];
        // Every account with a package or a website: use is shown even without a limit.
        User::query()
            ->where(fn ($query) => $query->whereNotNull('package_id')
                ->orWhereIn('id', Website::query()->whereNotNull('assigned_user_id')->select('assigned_user_id')))
            ->with('package')->orderBy('id')->get()
            ->each(function (User $user) use (&$result): void {
                try {
                    $usage = $this->check($user);
                } catch (\RuntimeException $e) {
                    report($e);
                    $result['failed']++;

                    return;
                }
                if ($usage !== null) {
                    $result['checked']++;
                    $result['over'] += $usage['over'] ? 1 : 0;
                }
            });

        return $result;
    }

    /** @return array{disk_mb: int, limit_mb: int, over: bool}|null null when the account has nothing to measure */
    public function check(User $user): ?array
    {
        $package = $user->loadMissing('package')->package;
        $owners = $this->systemAccounts($user);
        if ($owners === []) {
            return null;
        }

        $used = [];
        foreach ($owners as $owner) {
            $used[$owner] = $this->limits->usage($owner)['disk_mb'];
        }
        $total = array_sum($used);
        AccountUsageSample::query()->create(['user_id' => $user->id, 'sampled_at' => now(), 'disk_mb' => $total]);
        $limit = (int) ($package?->max_storage_mb ?? 0);
        if ($limit <= 0) {
            // No limit: only the reading, no alert and no quota change.
            return ['disk_mb' => $total, 'limit_mb' => 0, 'over' => false];
        }

        $over = $total >= $limit;
        $this->alerts->record($user, 'disk', $total, $limit, "{$total} MB of {$limit} MB", data: ['disk_mb' => $total, 'limit_mb' => $limit]);
        $this->applyLimits($package, $used, $limit - $total);

        return ['disk_mb' => $total, 'limit_mb' => $limit, 'over' => $over];
    }

    /**
     * Each account may grow by what the package has left, so writes stop by
     * the next check at the latest. `report` takes any earlier limit away.
     *
     * @param  array<string, int>  $used
     */
    private function applyLimits(PackagePlan $package, array $used, int $left): void
    {
        $enforce = $package->enforcement_mode === PackagePlan::MODE_ENFORCE;
        foreach ($used as $owner => $mb) {
            try {
                $this->limits->setDiskLimit($owner, $enforce ? max(1, $mb + max(0, $left)) : 0);
            } catch (\RuntimeException $e) {
                // In report mode there may be no quota on this server at all.
                if ($enforce) {
                    throw $e;
                }
            }
        }
    }

    /** @return list<string> */
    private function systemAccounts(User $user): array
    {
        return Website::query()->where('assigned_user_id', $user->id)->pluck('site_owner')
            ->map(fn ($owner) => trim((string) $owner))
            ->filter(fn (string $owner) => SystemAccountSuspender::suspendable($owner))
            ->unique()->values()->all();
    }
}
