<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;

/**
 * The backstop limit for the panel routes that carry no `throttle:` of their
 * own (about two thirds of them), so no endpoint can be hammered unmetered.
 *
 * Rules that keep it from ever costing a real user a response:
 *   - a route with its own `throttle:` is left alone. Two throttles on one
 *     route would otherwise share a cache key — Laravel's key does not
 *     include the limit — so every request would count twice and halve the
 *     stricter limit.
 *   - it counts per route and per user (ThrottleRequestsPerRoute), under its
 *     own `baseline:` prefix: one busy endpoint cannot exhaust another.
 *   - the ceiling is far above what a page produces (1200 hits a minute on
 *     one endpoint by one user, i.e. 20/second sustained).
 *   - routes that fan out by design (a page pulling many files through one
 *     route) are listed in `dpanel.rate_limit.baseline_exempt`.
 *
 * DPANEL_RATE_LIMIT_BASELINE=0 switches it off entirely.
 */
class BaselineRateLimit extends ThrottleRequestsPerRoute
{
    public function handle($request, Closure $next, $maxAttempts = 0, $decayMinutes = 1, $prefix = 'baseline:')
    {
        $perMinute = (int) config('dpanel.rate_limit.baseline_per_minute', 1200);

        if ($perMinute <= 0 || ! config('dpanel.rate_limit.enabled', true)) {
            return $next($request);
        }

        $route = $request->route();

        // No match (404) means no per-route key to count under.
        if (! $route || $this->hasOwnThrottle($route) || $this->isExempt($route)) {
            return $next($request);
        }

        return parent::handle($request, $next, $perMinute, 1, 'baseline:');
    }

    /** Does the route already declare a throttle of its own? */
    private function hasOwnThrottle($route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'throttle') || str_contains($middleware, 'ThrottleRequests')) {
                return true;
            }
        }

        return false;
    }

    /** Routes a single page is expected to call many times over. */
    private function isExempt($route): bool
    {
        $name = (string) $route->getName();

        if ($name === '') {
            return false;
        }

        $patterns = (array) config('dpanel.rate_limit.baseline_exempt', []);

        return $patterns !== [] && Str::is($patterns, $name);
    }
}
