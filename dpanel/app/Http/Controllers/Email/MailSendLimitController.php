<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Services\Mail\Backend\MailBackendException;
use App\Services\Mail\SendLimits\MailSendLimitService;
use Illuminate\Http\JsonResponse;

/** Each account's outbound mail use against its package limit (backlog P5). */
class MailSendLimitController extends Controller
{
    public function __construct(private readonly MailSendLimitService $limits) {}

    public function index(): JsonResponse
    {
        return response()->json($this->limits->report());
    }

    /** Writes the limits now instead of within five minutes. */
    public function apply(): JsonResponse
    {
        try {
            $state = $this->limits->sync(force: true);
        } catch (MailBackendException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $state['ready'] ? 'Mail limits applied.' : "Mail limits saved, but not enforced: {$state['reason']}.",
            ...$this->limits->report(),
        ]);
    }
}
