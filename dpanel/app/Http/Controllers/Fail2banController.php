<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\Security\DrustSecurityClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets an admin see and lift fail2ban bans from the panel. A few wrong SSH
 * passwords ban the admin's own IP on port 22, and the panel (80/443) is
 * then the only way back in.
 */
class Fail2banController extends Controller
{
    public function __construct(
        private readonly DrustSecurityClient $drust,
        private readonly ActivityLogService $activity,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Security/Fail2ban/Index', [
            'clientIp' => (string) $request->ip(),
        ]);
    }

    public function status(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->drust->fail2banStatus()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function history(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->drust->sshHistory()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function unban(Request $request): JsonResponse
    {
        return $this->act($request, 'unban', 'unblocked');
    }

    public function ban(Request $request): JsonResponse
    {
        return $this->act($request, 'ban', 'blocked from SSH');
    }

    public function policy(Request $request): JsonResponse
    {
        $maxRetry = (int) $request->validate(['max_retry' => ['required', 'integer', 'min:1', 'max:10']])['max_retry'];

        try {
            $data = $this->drust->fail2banAction('policy', '', ['max_retry' => $maxRetry]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => preg_replace('/^Failed:\s*/', '', $e->getMessage())], 422);
        }
        try {
            $this->activity->log('fail2ban.policy', null, ['max_retry' => $maxRetry], $request);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => "SSH now blocks an IP permanently after {$maxRetry} failed logins.",
            'data' => $data,
        ]);
    }

    public function whitelistAdd(Request $request): JsonResponse
    {
        return $this->act($request, 'whitelist_add', 'whitelisted');
    }

    public function whitelistRemove(Request $request): JsonResponse
    {
        return $this->act($request, 'whitelist_remove', 'removed from the whitelist');
    }

    private function act(Request $request, string $action, string $done): JsonResponse
    {
        // drust validates the address again; this only rejects obvious junk early.
        $ip = trim((string) $request->validate(['ip' => ['required', 'string', 'max:64']])['ip']);

        try {
            $data = $this->drust->fail2banAction($action, $ip);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => preg_replace('/^Failed:\s*/', '', $e->getMessage())], 422);
        }
        try {
            $this->activity->log('fail2ban.'.$action, null, ['ip' => $ip], $request);
        } catch (\Throwable $e) {
            // The change is already live on the server; a missing audit row must not report it as failed.
            report($e);
        }

        return response()->json(['success' => true, 'message' => "{$ip} {$done}.", 'data' => $data]);
    }
}
