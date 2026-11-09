<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Services\Mail\Backend\MailBackend;
use App\Services\Mail\Backend\MailBackendException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deleting and retrying queued mail (backlog P5). The list itself is on the
 * Mail Health page (MailDeliveryDiagnosticsService).
 */
class MailQueueController extends Controller
{
    public function __construct(private readonly MailBackend $mail) {}

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'string', 'distinct', 'regex:/^[0-9A-Za-z]{5,20}$/'],
        ]);

        try {
            $deleted = $this->mail->deleteQueued(array_values($validated['ids']));
        } catch (MailBackendException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'deleted' => $deleted,
            'message' => $deleted === 1 ? '1 message deleted.' : "{$deleted} messages deleted.",
        ]);
    }

    public function retry(): JsonResponse
    {
        try {
            $this->mail->retryQueue();
        } catch (MailBackendException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Postfix is trying to deliver the queued mail now.']);
    }
}
