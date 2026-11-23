<?php

namespace App\Http\Middleware;

use App\Models\AiGatewayApiKey;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates external AI Gateway API requests via an
 * `Authorization: Bearer sk-ag-...` header, OpenAI/OpenRouter-style.
 *
 * Before the key is looked up, each client IP is capped (all keys together)
 * and an IP that keeps sending wrong keys is locked out for a while, so a
 * flood costs no DB queries. A key with an IP allowlist only works from it.
 *
 * As an AuthenticatesRequests middleware it is sorted ahead of `throttle`,
 * so the per-key `throttle:ai-gateway` limiter can see the key.
 */
class AuthenticateAiGatewayApiKey implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $ipPerMinute = (int) config('aigateway.rate_limit.ip_per_minute', 120);
        $ipBucket = 'ai-gateway-ip:'.$request->ip();

        if ($ipPerMinute > 0) {
            if (RateLimiter::tooManyAttempts($ipBucket, $ipPerMinute)) {
                return $this->error('Too many requests from this IP. Slow down and retry after the Retry-After interval.', 'rate_limit_exceeded', 429, [
                    'Retry-After' => (string) RateLimiter::availableIn($ipBucket),
                ]);
            }
            RateLimiter::hit($ipBucket, 60);
        }

        $badKeyBucket = 'ai-gateway-bad-key:'.$request->ip();
        $maxBad = (int) config('aigateway.rate_limit.bad_key_attempts', 10);

        if ($maxBad > 0 && RateLimiter::tooManyAttempts($badKeyBucket, $maxBad)) {
            $retry = RateLimiter::availableIn($badKeyBucket);

            return $this->error('Too many invalid API key attempts from this IP. Try again later.', 'rate_limit_exceeded', 429, [
                'Retry-After' => (string) $retry,
            ]);
        }

        $token = $request->bearerToken();

        if (! $token || ! str_starts_with($token, 'sk-ag-')) {
            $this->recordBadKey($badKeyBucket);

            return $this->error('Missing or malformed Authorization header. Expected: Authorization: Bearer sk-ag-...', 'invalid_api_key', 401);
        }

        $key = AiGatewayApiKey::findActiveByPlainKey($token);

        if (! $key) {
            $this->recordBadKey($badKeyBucket);

            return $this->error('Invalid or revoked API key.', 'invalid_api_key', 401);
        }

        if (! $key->allowsIp($request->ip())) {
            return $this->error('This API key is not allowed from your IP address.', 'ip_not_allowed', 403);
        }

        $key->touchLastUsed();
        $request->attributes->set('ai_gateway_api_key', $key);

        return $next($request);
    }

    private function recordBadKey(string $bucket): void
    {
        RateLimiter::hit($bucket, max(60, (int) config('aigateway.rate_limit.bad_key_lockout_seconds', 900)));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(string $message, string $code, int $status, array $headers = []): Response
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => $status === 429 ? 'rate_limit_error' : 'invalid_request_error',
                'code' => $code,
            ],
        ], $status, $headers);
    }
}
