<?php

namespace App\Http\Controllers;

use App\Models\DnsZone;
use App\Services\Dns\Backend\DnsBackend;
use App\Services\Dns\Backend\UnsupportedDnsFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DNSSEC for one zone (backlog M6): sign it, unsign it, and show the DS
 * records the owner gives their registrar.
 */
class DnsZoneDnssecController extends Controller
{
    public function show(Request $request, string $token, string $id, DnsBackend $dns): JsonResponse
    {
        [$profile, $domain] = $this->zone($request, $id);

        return $this->respond(fn () => $dns->dnssecStatus($domain), $profile);
    }

    public function update(Request $request, string $token, string $id, DnsBackend $dns): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        [$profile, $domain] = $this->zone($request, $id);

        return $this->respond(fn () => $dns->enableDnssec($domain, (bool) $validated['enabled']), $profile);
    }

    /** @param  callable(): array{enabled: bool, ds: list<string>}  $call */
    private function respond(callable $call, DnsZone $profile): JsonResponse
    {
        try {
            $state = $call();
        } catch (UnsupportedDnsFeature $error) {
            return response()->json(['message' => $error->getMessage(), 'supported' => false], 501);
        } catch (\RuntimeException $error) {
            Log::warning('DNSSEC request failed.', ['zone' => $profile->domain, 'error' => $error->getMessage()]);

            return response()->json(['message' => 'DNSSEC could not be changed: '.$error->getMessage()], 502);
        }
        if ((bool) $profile->dnssec_enabled !== $state['enabled']) {
            $profile->forceFill(['dnssec_enabled' => $state['enabled']])->save();
        }

        return response()->json(['supported' => true, ...$state]);
    }

    /** @return array{0: DnsZone, 1: string} */
    private function zone(Request $request, string $id): array
    {
        $profile = DnsZone::query()
            ->visibleTo($request->user())
            ->where(fn ($query) => $query->where('id', $id)->when(ctype_digit($id), fn ($inner) => $inner->orWhere('powerdns_domain_id', (int) $id)))
            ->whereNotNull('powerdns_domain_id')
            ->firstOrFail();
        $domain = strtolower((string) DB::table('domains')->where('id', (int) $profile->powerdns_domain_id)->value('name'));
        abort_if($domain === '', 404);

        return [$profile, $domain];
    }
}
