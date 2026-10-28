<?php

namespace App\Services\Mcp;

use App\Models\McpAccessToken;
use Illuminate\Http\Request;

/**
 * Shared OAuth helpers for the MCP authorization server.
 */
class McpOAuth
{
    public const SCOPE_READ = 'mcp:read';

    public const SCOPE_FULL = 'mcp:full';

    public const SCOPES = [self::SCOPE_READ, self::SCOPE_FULL];

    public const PENDING_PREFIX = 'mcp_oauth:pending:';

    public const CODE_PREFIX = 'mcp_oauth:code:';

    public function issuer(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    public function resourceUrl(Request $request): string
    {
        return $this->issuer($request).'/api/mcp';
    }

    public function isOurResource(Request $request, string $resource): bool
    {
        return rtrim($resource, '/') === $this->resourceUrl($request)
            || rtrim($resource, '/') === $this->issuer($request);
    }

    /** The token scope ('read' or 'full') a client asked for. */
    public function requestedScope(string $scope): string
    {
        return in_array(self::SCOPE_FULL, preg_split('/\s+/', trim($scope)) ?: [], true)
            ? McpAccessToken::SCOPE_FULL
            : McpAccessToken::SCOPE_READ;
    }

    public static function scopeFor(string $tokenScope): string
    {
        return $tokenScope === McpAccessToken::SCOPE_FULL
            ? self::SCOPE_READ.' '.self::SCOPE_FULL
            : self::SCOPE_READ;
    }

    public function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * https anywhere, plain http only to a loopback address (native apps
     * such as Claude Code or Cursor), or a private app scheme like cursor://.
     */
    public function isAcceptableRedirectUri(string $uri): bool
    {
        if (strlen($uri) > 2000 || str_contains($uri, '#')) {
            return false;
        }

        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme === 'https') {
            return isset($parts['host']) && $parts['host'] !== '';
        }

        if ($scheme === 'http') {
            return in_array(strtolower((string) ($parts['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        }

        // Private-use URI schemes (RFC 8252 §7.1), never script-capable ones.
        return preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) === 1
            && ! in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'], true);
    }

    /**
     * @param  array<string, string>  $params
     */
    public function appendQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function accessTtl(): int
    {
        return max(60, (int) config('mcp.oauth.access_token_ttl', 3600));
    }

    public function refreshTtlDays(): int
    {
        return max(1, (int) config('mcp.oauth.refresh_token_ttl_days', 30));
    }
}
