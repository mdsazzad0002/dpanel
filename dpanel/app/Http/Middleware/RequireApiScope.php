<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\ApiScopes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.scope:websites:write`: the token must carry the scope, and its owner
 * must still be allowed that area in the panel. A `write` scope also allows
 * reading the same area.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->attributes->get('api_token');
        if (! $token instanceof ApiToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $hasScope = $token->hasScope($scope)
            || (str_ends_with($scope, ':read') && $token->hasScope(substr($scope, 0, -5).':write'));
        if (! $hasScope) {
            return response()->json(['message' => "This token does not have the {$scope} scope."], 403);
        }
        if (! ApiScopes::userMay($request->user(), $scope)) {
            return response()->json(['message' => "The owner of this token may not use {$scope} any more."], 403);
        }

        return $next($request);
    }
}
