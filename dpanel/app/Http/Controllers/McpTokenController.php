<?php

namespace App\Http\Controllers;

use App\Models\McpAccessToken;
use App\Services\ActivityLogService;
use App\Services\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issues the bearer tokens remote MCP clients (Claude, Cursor, …) use to
 * reach /api/mcp.
 */
class McpTokenController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/McpAccess', [
            'tokens' => McpAccessToken::query()
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (McpAccessToken $t) => $this->serialise($t)),
            'endpointUrl' => rtrim($request->getSchemeAndHttpHost(), '/').'/api/mcp',
            'enabled' => (bool) config('mcp.enabled', true),
            'tools' => array_map(function (string $class) {
                $tool = app($class);

                return ['name' => $tool->name(), 'title' => $tool->title(), 'read_only' => $tool->readOnly()];
            }, McpServer::TOOLS),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::in([McpAccessToken::SCOPE_READ, McpAccessToken::SCOPE_FULL])],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $generated = McpAccessToken::generate();

        $token = McpAccessToken::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scope' => $data['scope'],
            'is_active' => true,
            'expires_at' => isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
        ]);

        $this->activityLog->log('mcp.token_created', $token, ['name' => $token->name, 'scope' => $token->scope]);

        return response()->json([
            'token' => $this->serialise($token->load('user:id,name,email')),
            'plain_token' => $generated['plain'],
        ]);
    }

    public function toggle(Request $request, $token, McpAccessToken $mcpToken): RedirectResponse
    {
        $mcpToken->update(['is_active' => ! $mcpToken->is_active]);
        $this->activityLog->log($mcpToken->is_active ? 'mcp.token_enabled' : 'mcp.token_disabled', $mcpToken, ['name' => $mcpToken->name]);

        return back();
    }

    public function destroy(Request $request, $token, McpAccessToken $mcpToken): RedirectResponse
    {
        $this->activityLog->log('mcp.token_revoked', $mcpToken, ['name' => $mcpToken->name]);
        $mcpToken->delete();

        return back();
    }

    private function serialise(McpAccessToken $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'token_prefix' => $t->token_prefix,
            'scope' => $t->scope,
            'is_active' => $t->is_active,
            'owner' => $t->user?->email,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'last_used_ip' => $t->last_used_ip,
            'expires_at' => $t->expires_at?->toIso8601String(),
            'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
