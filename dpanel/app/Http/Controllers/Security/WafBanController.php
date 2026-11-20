<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\Platform\FirewallClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Addresses the WAF banned at the firewall after repeated attacks. drust
 * lifts each ban when its time is up; an admin can lift one early here.
 */
class WafBanController extends Controller
{
    public function __construct(
        private readonly FirewallClient $firewall,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'bans' => $this->firewall->wafBans()]);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function destroy(Request $request, string $token, string $ip): JsonResponse
    {
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP) !== false, 404);

        try {
            $message = $this->firewall->liftWafBan($ip);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->activity->log('firewall.waf_ban_lifted', null, ['ip' => $ip], $request);

        return response()->json(['ok' => true, 'message' => $message]);
    }
}
