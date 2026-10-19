<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's plain `throttle:N,1` keys its counter on the user alone, so every
 * throttled route shares one bucket: a page polling a `throttle:1200,1`
 * endpoint exhausts a `throttle:10,1` action it never called ("Too Many
 * Attempts"). Key the counter per route as well, so each limit only counts
 * hits on its own route.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route ? ($route->getName() ?? implode('|', $route->methods()).':'.$route->uri()) : '';

        return sha1($routeKey.'|'.parent::resolveRequestSignature($request));
    }
}
