<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Replaces Spatie's role middleware. Syntax unchanged: pipe-separated role
 * names, e.g. "admin|reseller".
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string $roles)
    {
        $user = $request->user();

        if (! $user) {
            throw new HttpException(403, 'Unauthenticated.');
        }

        foreach (explode('|', $roles) as $role) {
            $role = trim($role);

            if ($role !== '' && $user->hasRole($role)) {
                return $next($request);
            }
        }

        throw new HttpException(403, 'This action is unauthorized.');
    }
}
