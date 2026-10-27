<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use App\Models\McpOAuthClient;
use App\Services\Mcp\McpOAuth;
use App\Support\PanelReturnPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OAuth 2.1 authorization server for the MCP endpoint, so clients that sign
 * in with OAuth (claude.ai connectors, Claude Code, Cursor, …) can connect
 * without a pasted token. Implements what the MCP authorization spec asks
 * for: protected resource metadata (RFC 9728), authorization server metadata
 * (RFC 8414), dynamic client registration (RFC 7591), authorization code
 * with PKCE (S256 only), refresh token rotation and revocation (RFC 7009).
 *
 * The consent screen lives inside the panel session (McpOAuthConsentController),
 * so sign-in, two-factor and the return-after-login flow are the panel's own.
 */
class McpOAuthController extends Controller
{
    public function __construct(private readonly McpOAuth $oauth) {}

    public function protectedResource(Request $request): JsonResponse
    {
        return $this->metadata([
            'resource' => $this->oauth->resourceUrl($request),
            'authorization_servers' => [$this->oauth->issuer($request)],
            'scopes_supported' => McpOAuth::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'dPanel',
        ]);
    }

    public function authorizationServer(Request $request): JsonResponse
    {
        $issuer = $this->oauth->issuer($request);

        return $this->metadata([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'scopes_supported' => McpOAuth::SCOPES,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        ]);
    }

    /**
     * Dynamic client registration (RFC 7591). Open by design — registering
     * grants nothing until an admin approves the client on the consent screen.
     */
    public function register(Request $request): JsonResponse
    {
        if (! config('mcp.enabled', true) || ! config('mcp.oauth.enabled', true)) {
            return $this->oauthError('access_denied', 'OAuth sign-in for MCP is disabled on this panel.', 403);
        }

        $redirectUris = $request->input('redirect_uris');
        if (! is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > 10) {
            return $this->oauthError('invalid_redirect_uri', 'redirect_uris must list 1 to 10 URIs.');
        }
        foreach ($redirectUris as $uri) {
            if (! is_string($uri) || ! $this->oauth->isAcceptableRedirectUri($uri)) {
                return $this->oauthError('invalid_redirect_uri', 'Redirect URIs must be https, a loopback http address, or an app scheme.');
            }
        }

        $authMethod = (string) $request->input('token_endpoint_auth_method', 'none');
        if (! in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            return $this->oauthError('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }

        foreach ((array) $request->input('grant_types', ['authorization_code']) as $grant) {
            if (! in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                return $this->oauthError('invalid_client_metadata', "Unsupported grant type: {$grant}");
            }
        }

        $name = trim(strip_tags((string) $request->input('client_name', '')));
        $name = $name !== '' ? mb_substr($name, 0, 100) : 'MCP client';

        $secret = $authMethod === 'none' ? null : 'dp-mcps-'.Str::random(48);

        $client = McpOAuthClient::query()->create([
            'client_id' => 'dp-mcpc-'.Str::random(32),
            'client_secret_hash' => $secret ? hash('sha256', $secret) : null,
            'client_name' => $name,
            'redirect_uris' => array_values($redirectUris),
            'token_endpoint_auth_method' => $authMethod,
            'registered_ip' => $request->ip(),
        ]);

        return response()->json(array_filter([
            'client_id' => $client->client_id,
            'client_secret' => $secret,
            'client_secret_expires_at' => $secret ? 0 : null,
            'client_id_issued_at' => $client->created_at?->timestamp,
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'token_endpoint_auth_method' => $authMethod,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => implode(' ', McpOAuth::SCOPES),
        ], fn ($value) => $value !== null), 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Validates the request, parks it for the consent screen and sends the
     * browser there (through the panel login when needed).
     */
    public function authorizeRequest(Request $request): RedirectResponse|Response
    {
        $client = McpOAuthClient::query()->where('client_id', (string) $request->query('client_id'))->first();
        if (! $client) {
            return $this->errorPage('Unknown OAuth client. Remove the connector from your AI client and add it again.');
        }

        $redirectUri = (string) $request->query('redirect_uri', '');
        if ($redirectUri === '' && count((array) $client->redirect_uris) === 1) {
            $redirectUri = (string) $client->redirect_uris[0];
        }
        if (! $client->allowsRedirect($redirectUri)) {
            return $this->errorPage('The redirect_uri does not match the one this client registered.');
        }

        // From here on errors go back to the client, as OAuth requires.
        $state = $request->query('state');
        $fail = fn (string $error, string $description) => redirect()->away($this->oauth->appendQuery($redirectUri, array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => is_string($state) ? $state : null,
            'iss' => $this->oauth->issuer($request),
        ])));

        if (! config('mcp.enabled', true) || ! config('mcp.oauth.enabled', true)) {
            return $fail('access_denied', 'OAuth sign-in for MCP is disabled on this panel.');
        }
        if ($request->query('response_type') !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }

        $challenge = (string) $request->query('code_challenge', '');
        if ($request->query('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) !== 1) {
            return $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $resource = $request->query('resource');
        if (is_string($resource) && $resource !== '' && ! $this->oauth->isOurResource($request, $resource)) {
            return $fail('invalid_target', 'This server only issues tokens for '.$this->oauth->resourceUrl($request));
        }

        $requestId = Str::random(40);
        Cache::put(McpOAuth::PENDING_PREFIX.$requestId, [
            'client_id' => $client->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'state' => is_string($state) ? $state : null,
            'scope' => $this->oauth->requestedScope((string) $request->query('scope', '')),
        ], now()->addMinutes(15));

        $consentPath = '/settings/mcp/authorize/'.$requestId;
        $panelToken = $request->hasSession() ? (string) $request->session()->get('panel_session_token', '') : '';

        if (Auth::check() && $panelToken !== '') {
            return redirect('/cpsess'.$panelToken.$consentPath);
        }

        // Land on the consent screen right after signing in.
        PanelReturnPath::rememberUrl($request, $request->getSchemeAndHttpHost().'/cpsess0'.$consentPath);

        return redirect()->route('login');
    }

    public function token(Request $request): JsonResponse
    {
        $client = $this->authenticateClient($request);
        if ($client instanceof JsonResponse) {
            return $client;
        }

        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request, $client),
            'refresh_token' => $this->refresh($request, $client),
            default => $this->oauthError('unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.'),
        };
    }

    /**
     * Token revocation (RFC 7009). Revoking either token ends the whole grant.
     */
    public function revoke(Request $request): Response|JsonResponse
    {
        $client = $this->authenticateClient($request);
        if ($client instanceof JsonResponse) {
            return $client;
        }

        $hash = hash('sha256', (string) $request->input('token', ''));

        McpAccessToken::query()
            ->where('oauth_client_id', $client->id)
            ->where(fn ($q) => $q->where('token_hash', $hash)->orWhere('refresh_token_hash', $hash))
            ->delete();

        return response('', 200);
    }

    private function exchangeCode(Request $request, McpOAuthClient $client): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $grant = $code !== '' ? Cache::pull(McpOAuth::CODE_PREFIX.hash('sha256', $code)) : null;

        if (! is_array($grant) || $grant['client_id'] !== $client->id) {
            return $this->oauthError('invalid_grant', 'The authorization code is invalid, expired or already used.');
        }

        $redirectUri = $request->input('redirect_uri');
        if (is_string($redirectUri) && $redirectUri !== $grant['redirect_uri']) {
            return $this->oauthError('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        $verifier = (string) $request->input('code_verifier', '');
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1
            || ! hash_equals($grant['code_challenge'], $this->oauth->s256($verifier))) {
            return $this->oauthError('invalid_grant', 'PKCE verification failed.');
        }

        $resource = $request->input('resource');
        if (is_string($resource) && $resource !== '' && ! $this->oauth->isOurResource($request, $resource)) {
            return $this->oauthError('invalid_target', 'This server only issues tokens for '.$this->oauth->resourceUrl($request));
        }

        $user = \App\Models\User::query()->find($grant['user_id']);
        if (! $user || $user->is_suspended || ! $user->hasRole('admin')) {
            return $this->oauthError('invalid_grant', 'The approving user is no longer an active admin.');
        }

        $access = McpAccessToken::generate();
        $refresh = McpAccessToken::generateRefresh();

        $token = McpAccessToken::query()->create([
            'user_id' => $user->id,
            'oauth_client_id' => $client->id,
            'name' => $client->client_name,
            'token_prefix' => $access['prefix'],
            'token_hash' => $access['hash'],
            'refresh_token_hash' => $refresh['hash'],
            'scope' => $grant['scope'],
            'is_active' => true,
            'expires_at' => now()->addSeconds($this->oauth->accessTtl()),
            'refresh_expires_at' => now()->addDays($this->oauth->refreshTtlDays()),
        ]);

        return $this->tokenResponse($token, $access['plain'], $refresh['plain']);
    }

    private function refresh(Request $request, McpOAuthClient $client): JsonResponse
    {
        $plain = (string) $request->input('refresh_token', '');

        return DB::transaction(function () use ($plain, $client) {
            $token = McpAccessToken::query()
                ->with('user')
                ->where('oauth_client_id', $client->id)
                ->where('refresh_token_hash', hash('sha256', $plain))
                ->lockForUpdate()
                ->first();

            if (! $token || ! $token->is_active || $token->isExpired()) {
                return $this->oauthError('invalid_grant', 'The refresh token is invalid, expired or revoked.');
            }

            $user = $token->user;
            if (! $user || $user->is_suspended || ! $user->hasRole('admin')) {
                return $this->oauthError('invalid_grant', 'The approving user is no longer an active admin.');
            }

            // Rotate both tokens: a replayed old refresh token stops working.
            $access = McpAccessToken::generate();
            $refresh = McpAccessToken::generateRefresh();

            $token->forceFill([
                'token_prefix' => $access['prefix'],
                'token_hash' => $access['hash'],
                'refresh_token_hash' => $refresh['hash'],
                'expires_at' => now()->addSeconds($this->oauth->accessTtl()),
                'refresh_expires_at' => now()->addDays($this->oauth->refreshTtlDays()),
            ])->save();

            return $this->tokenResponse($token, $access['plain'], $refresh['plain']);
        });
    }

    private function tokenResponse(McpAccessToken $token, string $access, string $refresh): JsonResponse
    {
        return response()->json([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $this->oauth->accessTtl(),
            'refresh_token' => $refresh,
            'scope' => McpOAuth::scopeFor($token->scope),
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function authenticateClient(Request $request): McpOAuthClient|JsonResponse
    {
        $clientId = $request->input('client_id');
        $secret = $request->input('client_secret');

        if ($request->getUser() !== null) {
            $clientId = urldecode((string) $request->getUser());
            $secret = urldecode((string) $request->getPassword());
        }

        $client = is_string($clientId) && $clientId !== ''
            ? McpOAuthClient::query()->where('client_id', $clientId)->first()
            : null;

        if (! $client || ($client->isConfidential() && ! $client->secretMatches(is_string($secret) ? $secret : null))) {
            return $this->oauthError('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }

    private function metadata(array $data): JsonResponse
    {
        return response()->json($data, 200, [
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function oauthError(string $error, string $description, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], $status, ['Cache-Control' => 'no-store']);
    }

    private function errorPage(string $message): Response
    {
        return response(
            '<!doctype html><meta charset="utf-8"><title>Authorization error</title>'
            .'<body style="font-family:system-ui;max-width:32rem;margin:4rem auto;padding:0 1rem">'
            .'<h1 style="font-size:1.25rem">Can’t connect to dPanel</h1><p>'.e($message).'</p></body>',
            400,
        );
    }
}
