<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

            return $this->expireStaleWildcardCookies($request, $next($request));
        }

        // This middleware is appended globally to the 'web' group, so it runs
        // on every request — including guest-facing pages like /login. The
        // forced-logout recovery below exists to catch an authenticated
        // session that's missing its panel token on an actual panel route
        // (cpsess{token}/...); it must never fire on a route that isn't one,
        // or it can invalidate/regenerate the session and CSRF token out from
        // under a login page the browser already rendered, causing a
        // reproducible "CSRF token mismatch" on submit whenever the browser
        // still holds an older authenticated session cookie.
        $isPanelRoute = $request->route()?->parameter('token') !== null;

        if ($isPanelRoute && Auth::check()) {
            $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');

            Log::info('DEBUG_PANEL_ROUTE_DEFAULTS force_logout', [
                'scheme' => $request->getScheme(),
                'secure' => $request->isSecure(),
                'path' => $request->path(),
            ]);

            Auth::guard('web')->logout();
            $request->session()->forget('panel_session_token');
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withCookie(Cookie::forget($cookieName));
        }

        return $this->expireStaleWildcardCookies($request, $next($request));
    }

    /**
     * The session/XSRF cookies used to be scoped to a wildcard parent domain
     * (e.g. Domain=dengrweb.com) via SESSION_COOKIE_DOMAIN. That's now unset
     * so cookies are host-only per-domain, but browsers that visited before
     * the change still hold the old wildcard-domain cookie alongside the new
     * host-only one. Both get sent on every request, and whichever one the
     * server happens to read can be a stale session/token — causing a
     * reproducible "CSRF token mismatch" only on domains that were visited
     * under the old config. Proactively expire the wildcard variant so the
     * browser drops it.
     */
    private function expireStaleWildcardCookies(Request $request, Response $response): Response
    {
        $host = $request->getHost();
        $labels = explode('.', $host);

        if (count($labels) < 3 || filter_var($host, FILTER_VALIDATE_IP)) {
            return $response;
        }

        $parentDomain = implode('.', array_slice($labels, 1));
        $sessionCookieName = (string) config('session.cookie');

        foreach (['.'.$parentDomain, $parentDomain] as $domain) {
            $response->headers->setCookie(Cookie::forget($sessionCookieName, '/', $domain));
            $response->headers->setCookie(Cookie::forget('XSRF-TOKEN', '/', $domain));
        }

        return $response;
    }
}
