<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Extension;
use App\Services\ActivityLogService;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the public API (/api/v1, backlog U1) with
 * `Authorization: Bearer dp-api-...`. The token acts as its owner, so a
 * suspended or terminated owner is refused, and every change made through
 * the API is written to the activity log with the token that made it.
 * As an AuthenticatesRequests middleware it runs before `throttle`, so the
 * rate limit is counted per token.
 */
class AuthenticateApiToken implements AuthenticatesRequests
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        if ($plain === '' || ! str_starts_with($plain, ApiToken::PREFIX)) {
            return $this->error('Missing or malformed Authorization header. Expected: Authorization: Bearer dp-api-...', 401);
        }

        $token = ApiToken::findUsableByPlainToken($plain);
        $user = $token?->user;
        if (! $token || ! $user) {
            return $this->error('Invalid, expired or revoked API token.', 401);
        }
        if ($user->is_suspended || $user->terminated_at !== null) {
            return $this->error('The account that owns this API token is suspended.', 403);
        }
        // An extension's token (U7) works only while the extension is enabled.
        if ($user->is_service_account && ! Extension::query()->where('service_user_id', $user->id)->where('enabled', true)->exists()) {
            return $this->error('This extension is disabled.', 403);
        }

        $token->touchLastUsed($request->ip());
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);

        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            $this->activityLog->log('api.request', $token, [
                'token' => $token->name,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status' => $response->getStatusCode(),
            ], $request);
        }

        return $response;
    }

    private function error(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer realm="dpanel-api"'] : []);
    }
}
