<?php

namespace App\Http\Controllers;

use App\Services\Tasks\BackgroundTaskQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The panel's floating task queue: the cards for work that was queued instead
 * of held in a request (website deletion today, anything that follows). Only
 * the signed-in user's own tasks are served, and every task type goes through
 * the same two endpoints, so a new one needs nothing here.
 */
class BackgroundTaskController extends Controller
{
    public function index(Request $request, string $token): JsonResponse
    {
        $type = trim((string) $request->query('type', ''));

        return response()->json([
            'ok' => true,
            'tasks' => BackgroundTaskQueue::forUser((int) ($request->user()?->id ?? 0), $type === '' ? null : $type),
        ]);
    }

    public function destroy(Request $request, string $token, string $taskId): JsonResponse
    {
        BackgroundTaskQueue::forget($taskId, (int) ($request->user()?->id ?? 0));

        return response()->json(['ok' => true]);
    }
}
