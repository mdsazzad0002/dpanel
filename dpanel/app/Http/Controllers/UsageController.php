<?php

namespace App\Http\Controllers;

use App\Models\AccountUsageSample;
use App\Models\UsageAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backlog P6: each account's use against its package limits, from the
 * samples the limit checks keep (disk hourly, bandwidth every 15 minutes,
 * CPU and memory every five), with a week of history per account.
 */
class UsageController extends Controller
{
    private const METRICS = ['disk_mb', 'bandwidth_mb', 'cpu_percent', 'memory_mb'];

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $users = $this->accounts($actor)->with('package')->orderBy('name')->get();
        $latest = $this->latestSamples($users->pluck('id')->all());
        $alerts = UsageAlert::query()->whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id');

        return Inertia::render('Usage/Index', [
            'scope' => match (true) {
                $actor->hasRole('admin') => 'Every account with a package',
                $actor->hasRole('reseller') => 'You and your customers',
                default => 'Your account',
            },
            'accounts' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'package' => $user->package?->name,
                'resources' => $this->resources($user, $latest[$user->id] ?? []),
                'alerts' => collect($alerts->get($user->id, []))
                    ->mapWithKeys(fn (UsageAlert $alert) => [$alert->resource => $alert->level])->all(),
            ])->values(),
        ]);
    }

    public function history(Request $request, string $token, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->accounts($actor)->whereKey($id)->first();
        abort_if($user === null, 404);

        $samples = AccountUsageSample::query()
            ->where('user_id', $user->id)
            ->where('sampled_at', '>=', now()->subDays(7))
            ->orderBy('sampled_at')
            ->get(['sampled_at', ...self::METRICS]);

        return response()->json([
            'series' => collect(self::METRICS)->mapWithKeys(fn (string $metric) => [$metric => $this->hourly($samples, $metric)])->all(),
        ]);
    }

    /** Accounts the actor may see: everyone for an admin, a reseller's own customers, else only themselves. */
    private function accounts(User $actor): Builder
    {
        $query = User::query();
        if ($actor->hasRole('admin')) {
            return $query->whereNotNull('package_id');
        }
        if ($actor->hasRole('reseller')) {
            return $query->where(fn (Builder $q) => $q->whereKey($actor->id)->orWhere('reseller_id', $actor->id));
        }

        return $query->whereKey($actor->id);
    }

    /**
     * The newest reading of each metric per account (each check writes only its own).
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, float|int>>
     */
    private function latestSamples(array $userIds): array
    {
        $latest = [];
        foreach (self::METRICS as $metric) {
            $query = AccountUsageSample::query()->whereIn('user_id', $userIds)->whereNotNull($metric);
            if ($metric === 'bandwidth_mb') {
                // Bandwidth counts from the first of the month.
                $query->where('sampled_at', '>=', now('UTC')->startOfMonth());
            }
            $ids = $query->selectRaw('MAX(id) as id')->groupBy('user_id')->pluck('id');
            AccountUsageSample::query()->whereIn('id', $ids)->get(['user_id', $metric])
                ->each(function (AccountUsageSample $sample) use (&$latest, $metric): void {
                    $latest[$sample->user_id][$metric] = $metric === 'cpu_percent' ? (float) $sample->{$metric} : (int) $sample->{$metric};
                });
        }

        return $latest;
    }

    /**
     * @param  array<string, float|int>  $latest
     * @return list<array{key: string, label: string, used: float|int|null, limit: int|null, unit: string, percent: int|null}>
     */
    private function resources(User $user, array $latest): array
    {
        $package = $user->package;
        $rows = [
            ['key' => 'disk', 'label' => 'Disk', 'metric' => 'disk_mb', 'limit' => $package?->max_storage_mb, 'unit' => 'MB'],
            ['key' => 'bandwidth', 'label' => 'Bandwidth (month)', 'metric' => 'bandwidth_mb', 'limit' => $package ? $package->max_bandwidth_gb * 1024 : null, 'unit' => 'MB'],
            ['key' => 'memory', 'label' => 'Memory', 'metric' => 'memory_mb', 'limit' => $package?->max_memory_mb, 'unit' => 'MB'],
            ['key' => 'cpu', 'label' => 'CPU', 'metric' => 'cpu_percent', 'limit' => $package?->max_cpu_percent, 'unit' => '%'],
        ];

        return array_map(function (array $row) use ($latest): array {
            $used = $latest[$row['metric']] ?? null;
            $limit = ($row['limit'] ?? 0) > 0 ? (int) $row['limit'] : null;

            return [
                'key' => $row['key'],
                'label' => $row['label'],
                'used' => $used,
                'limit' => $limit,
                'unit' => $row['unit'],
                'percent' => $used !== null && $limit !== null ? (int) min(999, round($used / $limit * 100)) : null,
            ];
        }, $rows);
    }

    /**
     * One point per hour (the average), so a week stays small.
     *
     * @param  Collection<int, AccountUsageSample>  $samples
     * @return list<array{0: string, 1: float}>
     */
    private function hourly(Collection $samples, string $metric): array
    {
        return $samples->filter(fn (AccountUsageSample $sample) => $sample->{$metric} !== null)
            ->groupBy(fn (AccountUsageSample $sample) => $sample->sampled_at->copy()->utc()->format('Y-m-d\TH:00:00\Z'))
            ->map(fn (Collection $hour, string $at) => [$at, round((float) $hour->avg($metric), 1)])
            ->values()->all();
    }
}
