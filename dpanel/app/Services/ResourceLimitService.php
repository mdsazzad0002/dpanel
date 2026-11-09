<?php

namespace App\Services;

use App\Models\AccountUsageSample;
use App\Models\User;
use App\Models\Website;
use App\Services\Platform\ResourceLimitsClient;
use App\Services\Platform\SystemAccountSuspender;
use App\Services\Platform\UnsupportedLimit;

/**
 * Task P4: the package's CPU, memory and process limit. drust puts the
 * account in a systemd slice with those limits and runs its sites' PHP in
 * masters of their own inside it; the kernel enforces the rest. The limit is
 * sent again only when it or the account's site users changed, and taken
 * away once when the package stops setting one. Usage is sampled each run.
 */
class ResourceLimitService
{
    public function __construct(private readonly ResourceLimitsClient $client) {}

    public static function group(User $user): string
    {
        return 'a'.$user->id;
    }

    /** @return array{checked: int, failed: int, unsupported: int} */
    public function checkAll(): array
    {
        $result = ['checked' => 0, 'failed' => 0, 'unsupported' => 0];
        User::query()
            ->where(fn ($query) => $query->whereNotNull('package_id')->orWhereNotNull('resource_limits_applied'))
            ->with('package')->orderBy('id')->get()
            ->each(function (User $user) use (&$result): void {
                try {
                    if ($this->check($user) !== null) {
                        $result['checked']++;
                    }
                } catch (UnsupportedLimit) {
                    $result['unsupported']++;
                } catch (\RuntimeException $e) {
                    report($e);
                    $result['failed']++;
                }
            });

        return $result;
    }

    /**
     * @return array{cpu_percent: float, memory_mb: int, processes: int}|null null when the account has no limit
     *
     * @throws \RuntimeException
     */
    public function check(User $user): ?array
    {
        $package = $user->loadMissing('package')->package;
        $owners = $this->siteUsers($user);
        $group = self::group($user);

        if ($package === null || ! $package->hasResourceLimits() || $owners === []) {
            if ($user->resource_limits_applied !== null) {
                $this->client->clear($group);
                $user->update(['resource_limits_applied' => null]);
            }

            return null;
        }

        $limits = [(int) $package->max_cpu_percent, (int) $package->max_memory_mb, (int) $package->max_processes];
        $hash = sha1(json_encode([$limits, $owners]));
        if ($user->resource_limits_applied !== $hash) {
            $this->client->set($group, $limits[0], $limits[1], $limits[2], $owners);
            $user->update(['resource_limits_applied' => $hash]);
        }

        $usage = $this->client->usage($group);
        AccountUsageSample::query()->create([
            'user_id' => $user->id,
            'sampled_at' => now(),
            'cpu_percent' => $usage['cpu_percent'],
            'memory_mb' => $usage['memory_mb'],
        ]);

        return $usage;
    }

    /** @return list<string> */
    private function siteUsers(User $user): array
    {
        return Website::query()->where('assigned_user_id', $user->id)->pluck('site_owner')
            ->map(fn ($owner) => trim((string) $owner))
            ->filter(fn (string $owner) => SystemAccountSuspender::suspendable($owner) && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $owner) === 1)
            ->unique()->sort()->values()->all();
    }
}
