<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A session opened before the suspension must not outlive it. */
class EndSuspendedSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::user()?->is_suspended) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login');
    }
}
