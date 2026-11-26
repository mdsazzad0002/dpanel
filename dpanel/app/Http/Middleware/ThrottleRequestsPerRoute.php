<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel's plain `throttle:N,1` keys its counter on the user alone, so every
 * throttled route shares one bucket: a page polling a `throttle:1200,1`
 * endpoint exhausts a `throttle:10,1` action it never called ("Too Many
 * Attempts"). Key the counter per route as well, so each limit only counts
 * hits on its own route.
 *
 * A limiter is a guard, never a reason a panel request fails, so on top of
 * that this middleware:
 *   - passes everything through when `dpanel.rate_limit.enabled` is off;
 *   - fails open when the cache backend behind the limiter is unreachable
 *     (a refused Redis connection used to surface as a 500 on every
 *     throttled route);
 *   - answers an XHR with a JSON 429 carrying `retry_after` instead of an
 *     HTML error page the caller cannot read.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    /** One log line per request when the limiter's backend is down. */
    private bool $failureLogged = false;

    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (! config('dpanel.rate_limit.enabled', true)) {
            return $next($request);
        }

        // Forward exactly the arguments the router passed: the parent reads
        // func_num_args() to tell `throttle:api-v1` (a named limiter) from
        // `throttle:60,1`, so padding the call would break named limiters.
        return parent::handle(...func_get_args());
    }

    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route ? ($route->getName() ?? implode('|', $route->methods()).':'.$route->uri()) : '';

        return sha1($routeKey.'|'.parent::resolveRequestSignature($request));
    }

    /**
     * The parent's loop, with every limiter call allowed to fail. A backend
     * error must not raise, and must not re-run $next either: the counting is
     * skipped for that limit and the request is served.
     */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $counted = [];

        foreach ($limits as $limit) {
            $tooMany = $this->withLimiter(
                fn () => $this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts),
                false,
            );

            if ($tooMany) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }

            if (! $limit->afterCallback) {
                $hit = $this->withLimiter(
                    function () use ($limit) {
                        $this->limiter->hit($limit->key, $limit->decaySeconds);

                        return true;
                    },
                    false,
                );

                if (! $hit) {
                    // Backend down: serve the request, and add no headers for
                    // a count that was never recorded.
                    continue;
                }
            }

            $counted[] = $limit;
        }

        $response = $next($request);

        foreach ($counted as $limit) {
            if ($limit->afterCallback && ($limit->afterCallback)($response)) {
                $this->withLimiter(
                    function () use ($limit) {
                        $this->limiter->hit($limit->key, $limit->decaySeconds);

                        return true;
                    },
                    false,
                );
            }

            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }

    protected function getTimeUntilNextRetry($key)
    {
        return (int) $this->withLimiter(fn () => parent::getTimeUntilNextRetry($key), 60);
    }

    protected function calculateRemainingAttempts($key, $maxAttempts, $retryAfter = null)
    {
        return (int) $this->withLimiter(
            fn () => parent::calculateRemainingAttempts($key, $maxAttempts, $retryAfter),
            0,
        );
    }

    /**
     * A 429 an API or XHR caller can act on; browser navigations and Inertia
     * visits keep Laravel's own response.
     */
    protected function buildException($request, $key, $maxAttempts, $responseCallback = null)
    {
        if (is_callable($responseCallback)) {
            return parent::buildException($request, $key, $maxAttempts, $responseCallback);
        }

        $retryAfter = $this->getTimeUntilNextRetry($key);
        $headers = $this->getHeaders($maxAttempts, 0, $retryAfter);
        $message = 'Too many requests to this endpoint. Retry in '.max(1, $retryAfter).' second(s).';

        $wantsJson = ! $request->inertia()
            && ($request->expectsJson() || $request->isJson() || $request->ajax());

        if (! $wantsJson) {
            return parent::buildException($request, $key, $maxAttempts, null);
        }

        return new HttpResponseException(response()->json([
            'message' => $message,
            'retry_after' => $retryAfter,
        ], 429, $headers));
    }

    /**
     * Run a limiter call, or fall back when its cache backend is unreachable.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $operation
     * @param  TValue  $fallback
     * @return TValue
     */
    private function withLimiter(callable $operation, mixed $fallback): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            if (! config('dpanel.rate_limit.fail_open', true)) {
                throw $e;
            }

            if (! $this->failureLogged) {
                $this->failureLogged = true;
                Log::warning('Rate limiter backend unavailable; request allowed through.', [
                    'error' => $e->getMessage(),
                ]);
            }

            return $fallback;
        }
    }
}
