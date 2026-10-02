<?php

namespace App\Http\Controllers;

use App\Models\DnsZone;
use App\Services\Dns\PublicDnsLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Whether each zone's domain points at the nameservers the zone publishes. */
class DnsZoneConnectionController extends Controller
{
    public function index(Request $request, string $token, PublicDnsLookup $dns): JsonResponse
    {
        $zones = DnsZone::query()->visibleTo($request->user())->whereNotNull('powerdns_domain_id')
            ->get(['domain', 'powerdns_domain_id']);
        $dns->prefetch($zones->map(fn (DnsZone $zone) => [(string) $zone->domain, 'NS'])->all());

        $expected = DB::table('records')
            ->whereIn('domain_id', $zones->pluck('powerdns_domain_id'))
            ->where('type', 'NS')
            ->get(['domain_id', 'name', 'content'])
            ->groupBy('domain_id');

        return response()->json(['zones' => $zones->mapWithKeys(function (DnsZone $zone) use ($dns, $expected) {
            $domain = strtolower((string) $zone->domain);
            $assigned = collect($expected->get($zone->powerdns_domain_id, []))
                ->filter(fn ($row) => strtolower((string) $row->name) === $domain)
                ->map(fn ($row) => rtrim(strtolower((string) $row->content), '.'))
                ->unique()->values();
            $current = collect($dns->records($domain, 'NS'))->unique()->values();
            $status = match (true) {
                $dns->failed($domain, 'NS') => 'unknown',
                $current->isEmpty() => 'unregistered',
                $assigned->isNotEmpty() && $current->every(fn ($ns) => $assigned->contains($ns)) => 'connected',
                default => 'pending',
            };

            return [$domain => [
                'status' => $status,
                'current' => $current->all(),
                'assigned' => $assigned->all(),
            ]];
        })]);
    }
}
