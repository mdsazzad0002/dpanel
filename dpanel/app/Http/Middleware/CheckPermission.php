<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Replaces Spatie's permission middleware. Syntax unchanged: pipe-separated
 * permission names, e.g. "manage_websites|manage_databases".
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissions)
    {
        $user = $request->user();

        if (! $user) {
            throw new HttpException(403, 'Unauthenticated.');
        }

        foreach (explode('|', $permissions) as $permission) {
            $permission = trim($permission);

            if ($permission !== '' && $user->hasAccess($permission)) {
                return $next($request);
            }
        }

        throw new HttpException(403, 'This action is unauthorized.');
    }
}
