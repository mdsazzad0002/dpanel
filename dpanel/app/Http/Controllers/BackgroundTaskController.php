<?php

namespace App\Http\Controllers;

use App\Services\Tasks\BackgroundTaskQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The panel's floating task queue: the cards for every piece of work that was
 * queued instead of held in a request — website deletions, clones, shares,
 * imports, pushes to live, quick exports, backup restores, installs.
 *
 * Every task type goes through these two endpoints, so a new one needs nothing
 * here. What a caller gets back is its own tasks in any stage, plus the
 * still-unfinished tasks of the users it is responsible for (an admin sees
 * everyone, a reseller its own customers), each carrying who queued it:
 * BackgroundTaskQueue::visibleTo() decides.
 */
class BackgroundTaskController extends Controller
{
    public function index(Request $request, string $token): JsonResponse
    {
        $type = trim((string) $request->query('type', ''));

        return response()->json([
            'ok' => true,
            'tasks' => BackgroundTaskQueue::visibleTo($request->user(), $type === '' ? null : $type),
        ]);
    }

    public function destroy(Request $request, string $token, string $taskId): JsonResponse
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $task = BackgroundTaskQueue::get($taskId);

        // Only the user who queued the work clears its card: another user's
        // card is visible so its progress can be followed, and dismissing it
        // would take the entry away from its owner mid-run.
        if ($task !== null && (int) ($task['user_id'] ?? 0) !== $userId) {
            return response()->json(['ok' => false, 'message' => 'This task belongs to another user.'], 403);
        }

        BackgroundTaskQueue::forget($taskId, $userId);

        return response()->json(['ok' => true]);
    }
}
