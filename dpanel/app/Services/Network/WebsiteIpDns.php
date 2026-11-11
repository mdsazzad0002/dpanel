<?php

namespace App\Services\Network;

use App\Models\Website;
use App\Services\Dns\DnsRecordService;
use Illuminate\Support\Facades\DB;

/**
 * Points a website's names at its new address in the zone this server
 * hosts for it (backlog U5).
 *
 * Rewritten: the A (or AAAA) records of the site's own names (the domain,
 * www. and its other aliases), and every other record of the zone that
 * held the old address, except names of other websites (they keep their
 * own address) and mail hosts the zone's MX records point at (mail has its
 * own sending IP). An own name with no record of that type, or several not
 * holding the old address, is left alone: someone set it up by hand.
 */
class WebsiteIpDns
{
    public function __construct(private readonly DnsRecordService $records) {}

    /**
     * `$onlyOld`: own names change only where they held the old address too
     * (a new main IP moves many sites at once, so nothing is guessed).
     *
     * @return array{zone: ?string, changed: list<string>, skipped: ?string}
     */
    public function rewrite(Website $site, ?string $oldIp, string $newIp, bool $onlyOld = false): array
    {
        $domain = strtolower(trim((string) $site->domain));
        $zone = $this->zoneFor($domain);
        if ($zone === null) {
            return ['zone' => null, 'changed' => [], 'skipped' => 'This server does not host a DNS zone for '.$domain.'; point its A record at '.$newIp.' where its DNS is.'];
        }
        if (strtoupper((string) $zone->type) === 'SLAVE') {
            return ['zone' => $zone->name, 'changed' => [], 'skipped' => $zone->name.' is a secondary zone; change its records on the primary server.'];
        }
        if ($oldIp === $newIp) {
            return ['zone' => $zone->name, 'changed' => [], 'skipped' => null];
        }

        $type = str_contains($newIp, ':') ? 'AAAA' : 'A';
        $zoneName = (string) $zone->name;
        $own = $this->ownNames($site, $zoneName);
        $rows = collect($this->records->list((int) $zone->id));
        $mailHosts = $rows->where('type', 'MX')->map(fn ($row) => strtolower(rtrim((string) $row['content'], '.')))->all();
        $otherSites = $this->otherWebsiteNames($site, $rows->pluck('name')->unique()->all());

        $targets = [];
        foreach ($rows->where('type', $type)->groupBy(fn ($row) => strtolower((string) $row['name'])) as $name => $group) {
            if (in_array($name, $own, true) && ! $onlyOld) {
                $matching = $group->filter(fn ($row) => $row['content'] === $oldIp);
                $pick = $matching->isNotEmpty() ? $matching : ($group->count() === 1 ? $group : collect());
            } elseif (! in_array($name, $otherSites, true) && ! in_array($name, $mailHosts, true)) {
                $pick = $group->filter(fn ($row) => $oldIp !== null && $row['content'] === $oldIp);
            } else {
                $pick = collect();
            }
            foreach ($pick as $row) {
                if ($row['content'] !== $newIp) {
                    $targets[] = $row;
                }
            }
        }

        $changed = [];
        foreach ($targets as $row) {
            $this->records->update((int) $row['id'], (int) $zone->id, (int) $zone->id, $zoneName, [
                'type' => $type,
                'name' => $row['name'],
                'content' => $newIp,
                'ttl' => $row['ttl'],
                'priority' => $row['priority'],
                'status' => $row['status'],
            ]);
            $changed[] = "{$row['name']} {$type} {$row['content']} → {$newIp}";
        }

        return ['zone' => $zoneName, 'changed' => $changed, 'skipped' => null];
    }

    /** The longest zone name this server hosts that the domain is in. */
    private function zoneFor(string $domain): ?object
    {
        $candidates = [];
        $labels = explode('.', $domain);
        while (count($labels) >= 2) {
            $candidates[] = implode('.', $labels);
            array_shift($labels);
        }
        if ($candidates === []) {
            return null;
        }

        return DB::connection()->table('domains')->whereIn('name', $candidates)->get()
            ->sortByDesc(fn ($zone) => strlen((string) $zone->name))->first();
    }

    /** @return list<string> the site's domain and its aliases' domains inside the zone */
    private function ownNames(Website $site, string $zone): array
    {
        $names = Website::query()->where('parent_id', $site->id)->whereIn('type', ['alias', 'alis'])->pluck('domain')
            ->push($site->domain)
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->filter(fn ($name) => $name === $zone || str_ends_with($name, '.'.$zone));

        return $names->unique()->values()->all();
    }

    /**
     * Names in the zone that belong to other websites, with their aliases.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function otherWebsiteNames(Website $site, array $names): array
    {
        $own = Website::query()->where('parent_id', $site->id)->whereIn('type', ['alias', 'alis'])->pluck('id')->push($site->id)->all();

        return Website::query()->whereIn(DB::raw('LOWER(domain)'), array_map('strtolower', $names))
            ->whereNotIn('id', $own)->pluck('domain')->map(fn ($name) => strtolower((string) $name))->all();
    }
}
