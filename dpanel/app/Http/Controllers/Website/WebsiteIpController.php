<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\Website;
use App\Services\ActivityLogService;
use App\Services\Network\IpPool;
use App\Services\Network\WebsiteIpAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The IP picker on a website's Manage page (backlog U5): which address of
 * the pool the site answers on and its DNS points at.
 */
class WebsiteIpController extends Controller
{
    public function show(Request $request, IpPool $pool, string $token, string $id): JsonResponse
    {
        $website = Website::query()->visibleTo($request->user())->findOrFail($id);
        $main = $pool->mainSite($website);
        $current = $pool->addressOf($website);

        return response()->json([
            'follows_parent' => $main->id !== $website->id ? $main->domain : null,
            'current' => $current ? self::row($current) : null,
            'choices' => $pool->choicesFor($website, $request->user())->map(fn (IpAddress $address) => self::row($address))->values()->all(),
        ]);
    }

    public function update(Request $request, WebsiteIpAssigner $assigner, ActivityLogService $activity, string $token, string $id): JsonResponse
    {
        $website = Website::query()->visibleTo($request->user())->findOrFail($id);
        $validated = $request->validate(['ip_address_id' => ['nullable', 'integer', 'exists:ip_addresses,id']]);
        $address = isset($validated['ip_address_id']) ? IpAddress::query()->find($validated['ip_address_id']) : null;

        $result = $assigner->assign($website, $address, $request->user());
        $activity->log('website.ip.updated', $website, ['address' => $result['address']?->address, 'dns' => $result['dns']['changed']], $request);
        $now = $result['address'];

        return response()->json([
            'message' => $now ? "{$website->domain} now answers on {$now->dnsAddress()}." : "{$website->domain} saved.",
            'current' => $now ? self::row($now) : null,
            'dns' => $result['dns'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(IpAddress $address): array
    {
        return [
            'id' => $address->id,
            'address' => $address->dnsAddress(),
            'label' => $address->label,
            'mode' => $address->mode,
            'is_main' => $address->is_main,
        ];
    }
}
