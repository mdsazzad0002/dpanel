<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplyPanelRouteDefaults
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $token = (string) $request->session()->get('panel_session_token', '');

        if ($token !== '') {
            URL::defaults(['token' => $token]);
            return $next($request);
        }

        if (Auth::check()) {
            $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');

            Auth::guard('web')->logout();
            $request->session()->forget('panel_session_token');
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withCookie(Cookie::forget($cookieName));
        }

        return $next($request);
    }
}
