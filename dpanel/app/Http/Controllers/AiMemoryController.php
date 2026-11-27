<?php

namespace App\Http\Controllers;

use App\Models\AiMemory;
use App\Services\Mcp\GatewayChatTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The chat's long-term memory as the admin sees it: what the AI saved about
 * them and their work, and a way to delete any of it. Only the admin's own
 * entries are ever listed or deleted.
 */
class AiMemoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(GatewayChatTools::allowedFor($request->user()), 403);

        return response()->json([
            'memories' => AiMemory::query()
                ->where('user_id', $request->user()->id)
                ->latest('updated_at')
                ->get(['id', 'name', 'type', 'description', 'body', 'updated_at'])
                ->map(fn (AiMemory $m): array => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'type' => $m->type,
                    'description' => $m->description,
                    'body' => $m->body,
                    'updated_at' => $m->updated_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    public function destroy(Request $request, string $token, int $memory): JsonResponse
    {
        abort_unless(GatewayChatTools::allowedFor($request->user()), 403);
        AiMemory::query()->where('user_id', $request->user()->id)->whereKey($memory)->delete();

        return response()->json(['ok' => true]);
    }

    public function clear(Request $request): JsonResponse
    {
        abort_unless(GatewayChatTools::allowedFor($request->user()), 403);
        $deleted = AiMemory::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
