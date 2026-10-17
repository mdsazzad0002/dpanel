<?php

namespace App\Services\Bandwidth;

use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads the monthly per-domain traffic snapshots the edge gateway writes to
 * `serverpanel.bandwidth_directory` (`<Y-m>.json`, keyed by canonical domain).
 */
class BandwidthUsageService
{
    /**
     * One traffic report used by both the dashboard summary and the Bandwidth
     * page, so the two always agree. Each website row includes its alias
     * domains (the gateway counts an alias under its own hostname); admins
     * also get a row for every other domain the gateway counted (removed
     * websites, aliases without a main site), so their total is the server's.
     *
     * @return array{available: bool, rows: Collection<int, array<string, mixed>>, daily: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function report(User $actor, string $month): array
    {
        $usage = $this->snapshot($month);
        $available = $usage !== null;
        $usage ??= [];
        $isAdmin = $actor->hasRole('admin');

        $websites = $this->websitesQueryFor($actor)
            ->with('assignedUser:id,name,email')
            ->get(['id', 'domain', 'assigned_user_id']);
        $aliasesByParent = Website::query()
            ->whereIn('type', ['alis', 'alias'])
            ->whereIn('parent_id', $websites->pluck('id'))
            ->get(['domain', 'parent_id'])
            ->groupBy('parent_id');

        $counted = [];
        $rows = collect();
        foreach ($websites as $website) {
            $domain = $this->normalize($website->domain);
            if ($domain === '' || isset($counted[$domain])) {
                continue;
            }
            $aliases = collect($aliasesByParent->get($website->id, []))
                ->map(fn (Website $alias) => $this->normalize($alias->domain))
                ->filter(fn (string $alias) => $alias !== '' && ! isset($counted[$alias]) && $alias !== $domain)
                ->unique()->values()->all();
            $domains = [$domain, ...$aliases];
            foreach ($domains as $counting) {
                $counted[$counting] = true;
            }

            $rows->push([
                'id' => $website->id,
                'domain' => $domain,
                'aliases' => $aliases,
                'owner' => $website->assignedUser?->name ?: $website->assignedUser?->email,
                'removed' => false,
                'note' => null,
                ...$this->sumDomains($usage, $domains),
            ]);
        }

        if ($isAdmin) {
            $known = Website::query()->pluck('domain')->map(fn ($domain) => $this->normalize($domain))->flip();
            foreach (array_keys($usage) as $domain) {
                $domain = $this->normalize((string) $domain);
                if ($domain === '' || isset($counted[$domain])) {
                    continue;
                }
                $counted[$domain] = true;
                $rows->push([
                    'id' => null,
                    'domain' => $domain,
                    'aliases' => [],
                    'owner' => null,
                    'removed' => true,
                    'note' => $known->has($domain) ? 'Alias without a main website' : 'Website removed from panel',
                    ...$this->sumDomains($usage, [$domain]),
                ]);
            }
        }

        $rows = $rows->sortByDesc('total_bytes')->values();

        return [
            'available' => $available,
            'rows' => $rows,
            'daily' => $this->dailyTotals($usage, array_keys($counted), $month),
            'totals' => [
                'websites' => $rows->where('removed', false)->count(),
                'active_websites' => $rows->where('removed', false)->where('requests', '>', 0)->count(),
                'requests' => (int) $rows->sum('requests'),
                'upload_bytes' => (int) $rows->sum('upload_bytes'),
                'download_bytes' => (int) $rows->sum('download_bytes'),
                'total_bytes' => (int) $rows->sum('total_bytes'),
            ],
        ];
    }

    /**
     * Main websites (aliases excluded) the actor may see: admins every
     * website, resellers their own and their customers', others their own.
     */
    public function websitesQueryFor(User $actor): Builder
    {
        $query = Website::query()->whereNotIn('type', ['alis', 'alias']);

        if ($actor->hasRole('admin')) {
            return $query;
        }

        $userIds = $actor->hasRole('reseller')
            ? User::query()->whereKey($actor->id)->orWhere('reseller_id', $actor->id)->pluck('id')->all()
            : [$actor->id];

        return $query->where(function (Builder $query) use ($actor, $userIds): void {
            $query->whereIn('assigned_user_id', $userIds);
            if ($actor->hasRole('reseller')) {
                $query->orWhere(function (Builder $query) use ($actor): void {
                    $query->where('assigned_reseller_id', $actor->id)->whereNull('assigned_user_id');
                });
            }
        });
    }

    /**
     * Decoded snapshot for a month, or null when the gateway has not written
     * one yet (or it is unreadable).
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(string $month): ?array
    {
        $path = rtrim((string) config('serverpanel.bandwidth_directory'), '/').'/'.$month.'.json';
        $payload = is_file($path) ? @file_get_contents($path) : false;
        $usage = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($usage) ? $usage : null;
    }

    /**
     * @param  array<int, string>  $domains
     * @return array{requests: int, upload_bytes: int, download_bytes: int, total_bytes: int}
     */
    private function sumDomains(array $usage, array $domains): array
    {
        $totals = ['requests' => 0, 'upload_bytes' => 0, 'download_bytes' => 0];
        foreach ($domains as $domain) {
            $record = $usage[$domain] ?? null;
            if (! is_array($record)) {
                continue;
            }
            $totals['requests'] += max(0, (int) ($record['requests'] ?? 0));
            $totals['upload_bytes'] += max(0, (int) ($record['upload_bytes'] ?? 0));
            $totals['download_bytes'] += max(0, (int) ($record['download_bytes'] ?? 0));
        }
        $totals['total_bytes'] = $totals['upload_bytes'] + $totals['download_bytes'];

        return $totals;
    }

    private function normalize(mixed $domain): string
    {
        return strtolower(trim((string) $domain));
    }

    /**
     * Per-day totals across the given domains, newest first.
     *
     * @param  array<int, string>  $domains  normalized domains
     * @return array<int, array{date: string, requests: int, upload_bytes: int, download_bytes: int, total_bytes: int}>
     */
    private function dailyTotals(array $usage, array $domains, string $month): array
    {
        $daily = [];
        foreach ($domains as $domain) {
            $days = $usage[$domain]['days'] ?? [];
            foreach (is_array($days) ? $days : [] as $date => $record) {
                if (! is_array($record)
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)
                    || ! str_starts_with((string) $date, $month.'-')) {
                    continue;
                }
                $daily[$date] ??= ['date' => (string) $date, 'requests' => 0, 'upload_bytes' => 0, 'download_bytes' => 0];
                $daily[$date]['requests'] += max(0, (int) ($record['requests'] ?? 0));
                $daily[$date]['upload_bytes'] += max(0, (int) ($record['upload_bytes'] ?? 0));
                $daily[$date]['download_bytes'] += max(0, (int) ($record['download_bytes'] ?? 0));
            }
        }
        krsort($daily);

        return array_values(array_map(function (array $row): array {
            $row['total_bytes'] = $row['upload_bytes'] + $row['download_bytes'];

            return $row;
        }, $daily));
    }
}
