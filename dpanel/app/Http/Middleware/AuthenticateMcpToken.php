<?php

namespace App\Http\Middleware;

use App\Models\McpAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates remote MCP clients via `Authorization: Bearer dp-mcp-...`.
 * The token acts as its owner, who must still be an active admin — demoting
 * or suspending the user disables every token they issued.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();

        if ($plain === '' || ! str_starts_with($plain, McpAccessToken::PREFIX)) {
            return $this->unauthorized('Missing or malformed Authorization header. Expected: Authorization: Bearer dp-mcp-...');
        }

        $token = McpAccessToken::findUsableByPlainToken($plain);
        $user = $token?->user;

        if (! $token || ! $user) {
            return $this->unauthorized('Invalid, expired or revoked MCP token.');
        }

        if ($user->is_suspended || ! $user->hasRole('admin')) {
            return $this->unauthorized('The owner of this MCP token is no longer an active admin.');
        }

        $token->touchLastUsed($request->ip());

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('mcp_token', $token);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32001, 'message' => $message],
        ], 401, ['WWW-Authenticate' => 'Bearer realm="dpanel-mcp"']);
    }
}
