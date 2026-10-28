<?php

namespace App\Http\Controllers;

use App\Models\McpAccessToken;
use App\Models\McpOAuthClient;
use App\Services\ActivityLogService;
use App\Services\Mcp\McpOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Allow <client> to access dPanel?" screen of the MCP OAuth flow. It
 * sits inside the panel session, so only a signed-in admin can approve.
 */
class McpOAuthConsentController extends Controller
{
    public function __construct(
        private readonly McpOAuth $oauth,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function show(Request $request, $token, string $requestId): Response
    {
        [$pending, $client] = $this->pending($requestId);

        return Inertia::render('Settings/McpAuthorize', [
            'requestId' => $requestId,
            'client' => $client ? [
                'name' => $client->client_name,
                'redirect_host' => $this->redirectLabel($pending['redirect_uri']),
                'registered_at' => $client->created_at?->toIso8601String(),
            ] : null,
            'requestedScope' => $pending['scope'] ?? McpAccessToken::SCOPE_READ,
            'endpointUrl' => $this->oauth->resourceUrl($request),
        ]);
    }

    public function decide(Request $request, $token, string $requestId): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'scope' => ['required_if:approve,true', Rule::in([McpAccessToken::SCOPE_READ, McpAccessToken::SCOPE_FULL])],
        ]);

        [$pending, $client] = $this->pending($requestId);
        if (! $client) {
            return response()->json(['message' => 'This sign-in request has expired. Start the connection again from your AI client.'], 410);
        }

        Cache::forget(McpOAuth::PENDING_PREFIX.$requestId);

        $params = ['state' => $pending['state'], 'iss' => $this->oauth->issuer($request)];

        if (! $data['approve']) {
            return response()->json(['redirect' => $this->oauth->appendQuery($pending['redirect_uri'], array_filter([
                'error' => 'access_denied',
                'error_description' => 'The administrator denied access.',
                ...$params,
            ]))]);
        }

        $code = Str::random(64);
        Cache::put(McpOAuth::CODE_PREFIX.hash('sha256', $code), [
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['code_challenge'],
            'scope' => $data['scope'],
        ], now()->addMinutes(5));

        $this->activityLog->log('mcp.oauth_approved', $client, [
            'client' => $client->client_name,
            'scope' => $data['scope'],
        ]);

        return response()->json(['redirect' => $this->oauth->appendQuery($pending['redirect_uri'], array_filter([
            'code' => $code,
            ...$params,
        ]))]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: McpOAuthClient|null}
     */
    private function pending(string $requestId): array
    {
        $pending = Cache::get(McpOAuth::PENDING_PREFIX.$requestId);
        if (! is_array($pending)) {
            return [[], null];
        }

        return [$pending, McpOAuthClient::query()->find($pending['client_id'])];
    }

    private function redirectLabel(string $uri): string
    {
        $parts = parse_url($uri);
        $host = $parts['host'] ?? null;

        return $host ? $host.(isset($parts['port']) ? ':'.$parts['port'] : '') : ($parts['scheme'] ?? $uri).'://';
    }
}
