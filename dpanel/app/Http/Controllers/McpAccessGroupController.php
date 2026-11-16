<?php

namespace App\Http\Controllers;

use App\Models\McpAccessGroup;
use App\Services\ActivityLogService;
use App\Services\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Access groups for MCP tokens: named allow-lists of tools, so a client can
 * be given only what it needs (e.g. monitoring, or one site's files) instead
 * of every tool the panel offers.
 */
class McpAccessGroupController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $group = McpAccessGroup::create([...$data, 'created_by' => $request->user()->id]);
        $this->activityLog->log('mcp.group_created', $group, ['name' => $group->name, 'tools' => $group->tools]);

        return response()->json(['group' => self::serialise($group->loadCount('tokens'))]);
    }

    public function update(Request $request, $token, McpAccessGroup $mcpGroup): JsonResponse
    {
        $mcpGroup->update($this->validated($request));
        $this->activityLog->log('mcp.group_updated', $mcpGroup, ['name' => $mcpGroup->name, 'tools' => $mcpGroup->tools]);

        return response()->json(['group' => self::serialise($mcpGroup->loadCount('tokens'))]);
    }

    public function destroy(Request $request, $token, McpAccessGroup $mcpGroup): JsonResponse
    {
        $inUse = $mcpGroup->tokens()->count();
        if ($inUse > 0) {
            return response()->json([
                'message' => "This group is used by {$inUse} token(s). Revoke them or move them to another group first.",
            ], 422);
        }

        $this->activityLog->log('mcp.group_deleted', $mcpGroup, ['name' => $mcpGroup->name]);
        $mcpGroup->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialise(McpAccessGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'tools' => array_values($group->tools ?? []),
            'tokens_count' => (int) ($group->tokens_count ?? 0),
        ];
    }

    /**
     * @return array{name: string, description: ?string, tools: array<int, string>}
     */
    private function validated(Request $request): array
    {
        $toolNames = array_keys(app(McpServer::class)->tools());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'tools' => ['required', 'array', 'min:1'],
            'tools.*' => ['string', 'distinct', Rule::in($toolNames)],
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'tools' => array_values($data['tools']),
        ];
    }
}
