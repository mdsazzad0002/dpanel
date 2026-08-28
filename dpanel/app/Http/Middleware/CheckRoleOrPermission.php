<?php

namespace App\Http\Middleware;

use App\Support\RolePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Replaces Spatie's role_or_permission middleware now that permissions are
 * resolved from the hardcoded static map (App\Support\RolePermissions)
 * instead of the role_has_permissions pivot table. Accepts the same
 * pipe-separated syntax: role names and permission names mixed freely,
 * e.g. "admin|reseller|manage_websites".
 */
class CheckRoleOrPermission
{
    public function handle(Request $request, Closure $next, string $tokens)
    {
        $user = $request->user();

        if (! $user) {
            throw new HttpException(403, 'Unauthenticated.');
        }

        foreach (explode('|', $tokens) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (in_array($token, RolePermissions::ROLES, true) && $user->hasRole($token)) {
                return $next($request);
            }

            if ($user->hasAccess($token)) {
                return $next($request);
            }
        }

        throw new HttpException(403, 'This action is unauthorized.');
    }
}
